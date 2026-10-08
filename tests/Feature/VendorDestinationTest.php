<?php

declare(strict_types=1);

use App\Actions\ApproveVendorDestination;
use App\Actions\PrepareVendorDestination;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationApproval;
use App\Models\VendorDestinationVersion;
use App\Services\InstallationInstitution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/** @return array{institution: Organization, maker: User, reviewer: User, vendor: Vendor} */
function destinationContext(): array
{
    config(['eduflow.institution_id' => null]);
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    /** @var User $maker */
    $maker = User::factory()->create();
    $maker->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Recorded supplier',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);

    return ['institution' => $institution, 'maker' => $maker, 'reviewer' => $reviewer, 'vendor' => $vendor];
}

/** @param array{institution: Organization, maker: User, reviewer: User, vendor: Vendor} $context */
function prepareDestination(array $context, string $version = 'v1', ?string $address = null): VendorDestinationVersion
{
    return app(PrepareVendorDestination::class)->handle($context['maker'], $context['vendor'], $version,
        $address ?? $context['vendor']->wallet_address, 'ARC-TESTNET', 'control-check-record-01');
}

test('destination preparation and separate approval preserve retry identity without payment or legacy address mutation', function (): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    expect($version->hasValidContent())->toBeTrue()->and(prepareDestination($context)->id)->toBe($version->id)
        ->and(VendorDestinationApproval::current($context['institution']->id, $context['vendor']->id))->toBeNull();
    $approval = app(ApproveVendorDestination::class)->handle($context['reviewer'], $version, $version->content_digest, null, 'independent-contact-record-01');
    $repeat = app(ApproveVendorDestination::class)->handle($context['reviewer'], $version, $version->content_digest, null, 'independent-contact-record-01');
    expect($approval->hasValidHistory())->toBeTrue()->and($repeat->id)->toBe($approval->id)
        ->and(Activity::query()->where('event', 'vendor_destination_prepared')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'vendor_destination_approved')->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($context['vendor']->fresh()->wallet_address)->toBe($context['vendor']->wallet_address);
});

test('super administrator cannot self approve and finance officer cannot approve another preparer', function (string $case): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    $actor = $context['maker'];
    if ($case === 'self') {
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    } else {
        /** @var User $actor */
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    }
    expect(fn () => app(ApproveVendorDestination::class)->handle($actor, $version, $version->content_digest, null, 'independent-check'))->toThrow(AuthorizationException::class);
})->with(['self', 'finance_officer']);

test('replacement requires latest review context and superseded destination cannot reactivate', function (): void {
    $context = destinationContext();
    $first = prepareDestination($context);
    $root = app(ApproveVendorDestination::class)->handle($context['reviewer'], $first, $first->content_digest, null, 'independent-check-v1');
    $second = prepareDestination($context, 'v2', '0x'.str_repeat('3', 40));
    expect(fn () => app(ApproveVendorDestination::class)->handle($context['reviewer'], $second, $second->content_digest, null, 'independent-check-v2'))->toThrow(ValidationException::class);
    $current = app(ApproveVendorDestination::class)->handle($context['reviewer'], $second, $second->content_digest, $root->id, 'independent-check-v2');
    expect($current->hasValidHistory())->toBeTrue();
    expect(fn () => app(ApproveVendorDestination::class)->handle($context['reviewer'], $first, $first->content_digest, $current->id, 'independent-check-v1'))->toThrow(ValidationException::class);
});

test('invalid destination address chain version or missing control evidence is refused', function (string $case): void {
    $context = destinationContext();
    $address = $context['vendor']->wallet_address;
    $chain = 'ARC-TESTNET';
    $version = 'v1';
    $evidence = 'control-check';
    match ($case) {
        'zero' => $address = '0x'.str_repeat('0', 40), 'malformed' => $address = '0xstudent_invalid',
        'chain' => $chain = 'ETH', 'version' => $version = 'invalid version', 'evidence' => $evidence = '',
        default => throw new LogicException('Unknown case.'),
    };
    expect(fn () => app(PrepareVendorDestination::class)->handle($context['maker'], $context['vendor'], $version, $address, $chain, $evidence))->toThrow(ValidationException::class);
})->with(['zero', 'malformed', 'chain', 'version', 'evidence']);

test('tampered predecessor policy evidence blocks destination replacement and current retry', function (string $case): void {
    $context = destinationContext();
    $first = prepareDestination($context);
    $root = app(ApproveVendorDestination::class)->handle($context['reviewer'], $first, $first->content_digest, null, 'check-v1');
    $second = prepareDestination($context, 'v2');
    $current = app(ApproveVendorDestination::class)->handle($context['reviewer'], $second, $second->content_digest, $root->id, 'check-v2');
    $third = prepareDestination($context, 'v3');
    if ($case === 'content') {
        DB::table((new VendorDestinationVersion)->getTable())->where('id', $first->id)->update(['address' => '0x'.str_repeat('4', 40)]);
    } else {
        DB::table((new VendorDestinationApproval)->getTable())->where('id', $root->id)->update(['verification_reference' => 'changed']);
    }
    expect($current->hasValidHistory())->toBeFalse()
        ->and(fn () => app(ApproveVendorDestination::class)->handle($context['reviewer'], $second, $second->content_digest, $root->id, 'check-v2'))->toThrow(ValidationException::class)
        ->and(fn () => app(ApproveVendorDestination::class)->handle($context['reviewer'], $third, $third->content_digest, $current->id, 'check-v3'))->toThrow(ValidationException::class);
})->with(['content', 'review']);

test('destination model and approval evidence are immutable', function (string $record, string $operation): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    $approval = app(ApproveVendorDestination::class)->handle($context['reviewer'], $version, $version->content_digest, null, 'independent-check');
    $model = $record === 'version' ? $version : $approval;
    expect(fn () => $operation === 'delete' ? $model->delete() : $model->update(['organization_id' => 999]))->toThrow(LogicException::class);
})->with(['version', 'approval'])->with(['update', 'delete']);

test('HTTP review uses authenticated separate staff and explicit current context', function (): void {
    $context = destinationContext();
    $data = ['vendor_id' => $context['vendor']->id, 'version' => 'v1', 'address' => $context['vendor']->wallet_address,
        'chain' => 'ARC-TESTNET', 'control_evidence' => 'recorded-control-check'];
    postJson(route('finance.vendor-destinations.store'), $data)->assertUnauthorized();
    actingAs($context['maker']);
    postJson(route('finance.vendor-destinations.store'), $data + ['prepared_by' => $context['reviewer']->id])->assertUnprocessable();
    postJson(route('finance.vendor-destinations.store'), $data)->assertSuccessful()->assertJsonPath('data.can_execute', false);
    /** @var VendorDestinationVersion $version */
    $version = VendorDestinationVersion::query()->sole();
    $approvalData = ['expected_digest' => $version->content_digest, 'expected_approval' => 'none', 'verification_reference' => 'independent-contact-check', 'control_verified' => true];
    postJson(route('finance.vendor-destinations.approve', $version), $approvalData)->assertForbidden();
    actingAs($context['reviewer']);
    $missing = $approvalData;
    unset($missing['expected_approval']);
    postJson(route('finance.vendor-destinations.approve', $version), $missing)->assertUnprocessable();
    postJson(route('finance.vendor-destinations.approve', $version), $approvalData)->assertSuccessful()->assertJsonPath('data.payment_approved', false);
});

test('destination DB preserves institution vendor and reviewer evidence and rejects mismatched network', function (string $case): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    app(ApproveVendorDestination::class)->handle($context['reviewer'], $version, $version->content_digest, null, 'independent-check');
    if ($case === 'chain') {
        expect(fn () => DB::table((new VendorDestinationVersion)->getTable())->where('id', $version->id)->update(['chain_id' => 5042]))->toThrow(QueryException::class);
    } else {
        expect(fn () => $context[$case]->delete())->toThrow(QueryException::class);
    }
})->with(['chain', 'institution', 'vendor', 'maker', 'reviewer']);

test('destination review refuses stale content and revoked vendor status', function (string $case): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    if ($case === 'vendor') {
        $context['vendor']->update(['status' => 'pending']);
    } else {
        DB::table((new VendorDestinationVersion)->getTable())->where('id', $version->id)->update(['control_evidence' => 'changed']);
    }
    expect(fn () => app(ApproveVendorDestination::class)->handle($context['reviewer'], $version, $version->content_digest, null, 'independent-check'))->toThrow(ValidationException::class);
})->with(['vendor', 'tampered']);

test('destination preparation and approval cannot cross institution boundaries', function (): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    /** @var Organization $foreign */
    $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
    $resolver = Mockery::mock(InstallationInstitution::class);
    $resolver->shouldReceive('current')->andReturn($context['institution']);
    $resolver->shouldReceive('require')->andReturn($context['institution']);
    app()->instance(InstallationInstitution::class, $resolver);
    DB::table((new VendorDestinationVersion)->getTable())->where('id', $version->id)->update(['organization_id' => $foreign->id]);
    expect(fn () => app(ApproveVendorDestination::class)->handle($context['reviewer'], $version, $version->content_digest, null, 'independent-check'))->toThrow(ModelNotFoundException::class);
    $context['vendor']->update(['organization_id' => $foreign->id]);
    expect(fn (): VendorDestinationVersion => prepareDestination($context, 'v2'))->toThrow(ModelNotFoundException::class);
});

test('HTTP destination approval refuses claimed control and authority injection', function (): void {
    $context = destinationContext();
    $version = prepareDestination($context);
    actingAs($context['reviewer']);
    $data = ['expected_digest' => $version->content_digest, 'expected_approval' => 'none',
        'verification_reference' => 'independent-contact-check', 'control_verified' => false];
    postJson(route('finance.vendor-destinations.approve', $version), $data)->assertUnprocessable();
    $data['control_verified'] = true;
    postJson(route('finance.vendor-destinations.approve', $version), $data + ['approved_by' => $context['maker']->id])->assertUnprocessable();
    expect(VendorDestinationApproval::query()->count())->toBe(0);
});

test('destination DB enforces one root and one successor for vendor', function (): void {
    $context = destinationContext();
    $first = prepareDestination($context);
    $approval = app(ApproveVendorDestination::class)->handle($context['reviewer'], $first, $first->content_digest, null, 'check-v1');
    $second = prepareDestination($context, 'v2');
    $row = $approval->getAttributes();
    unset($row['id']);
    $row['vendor_destination_version_id'] = $second->id;
    expect(fn () => DB::table((new VendorDestinationApproval)->getTable())->insert($row))->toThrow(QueryException::class);
});

test('destination migration only rolls back empty evidence and explicit factory uses recorded vendor', function (): void {
    $contextMigration = require database_path('migrations/2026_10_08_031157_add_vendor_destination_context_to_payment_intents_table.php');
    $contextMigration->down();
    $migration = require database_path('migrations/2026_10_08_030549_create_vendor_destination_versions_table.php');
    $migration->down();
    expect(Schema::hasTable('vendor_destination_versions'))->toBeFalse();
    $migration->up();
    $contextMigration->up();
    $context = destinationContext();
    /** @var VendorDestinationVersion $version */
    $version = VendorDestinationVersion::factory()->forVendor($context['vendor'], $context['maker'])->create();
    expect($version->hasValidContent())->toBeTrue()->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});
