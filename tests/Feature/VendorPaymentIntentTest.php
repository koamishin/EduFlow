<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\ApproveVendorDestination;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareVendorDestination;
use App\Actions\PrepareVendorPayment;
use App\Actions\ReviewInvoiceVersion;
use App\Actions\VerifyVendorPaymentDraft;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Approval;
use App\Models\Budget;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationApproval;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\artisan;

/** @return array{institution: Organization, actor: User, invoice: Invoice, wallet: Wallet, vendor: Vendor, budget: Budget} */
function vendorDraftContext(): array
{
    config([
        'eduflow.institution_id' => null,
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => null,
    ]);
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $policy = app(CreateFinancePolicyVersion::class)->handle($actor, 'v1', new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(10, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, null);
    $wallet = Wallet::query()->create([
        'organization_id' => $institution->id, 'provider' => 'circle', 'network' => 'arc',
        'address' => '0x'.str_repeat('1', 40), 'balance' => '500', 'status' => 'active',
    ]);
    $vendor = Vendor::query()->create([
        'organization_id' => $institution->id, 'name' => 'Campus Network Supplier',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low',
    ]);
    $destination = app(PrepareVendorDestination::class)->handle($actor, $vendor, 'v1', $vendor->wallet_address, 'ARC-TESTNET', 'synthetic-control-evidence');
    app(ApproveVendorDestination::class)->handle($reviewer, $destination, $destination->content_digest, null, 'synthetic-independent-verification');
    $budget = Budget::query()->create([
        'organization_id' => $institution->id, 'name' => 'Teaching services', 'category' => 'software',
        'allocated_amount' => '100', 'spent_amount' => '0', 'remaining_amount' => '100', 'status' => 'active',
    ]);
    $invoice = Invoice::query()->create([
        'organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'LMS-'.Str::uuid(), 'amount' => '25', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending',
    ]);

    return ['institution' => $institution, 'actor' => $actor, 'invoice' => $invoice, 'wallet' => $wallet, 'vendor' => $vendor, 'budget' => $budget];
}

/** @param array{institution: Organization, actor: User, invoice: Invoice, wallet: Wallet, vendor: Vendor, budget: Budget} $context */
function prepareVendorDraft(array $context, ?string $key = null): PaymentIntent
{
    return app(PrepareVendorPayment::class)->handle($context['actor'], $context['invoice'], $context['wallet'], new Money(1, CurrencyCode::USDC), $key ?? (string) Str::uuid());
}

/** @param array{institution: Organization, actor: User, invoice: Invoice, wallet: Wallet, vendor: Vendor, budget: Budget} $context */
function exactDraftEvidence(array $context, string $currency = 'USDC'): InvoiceVersion
{
    $evidence = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], [
        'capture_key' => (string) Str::uuid(), 'source_amount' => '25.000001', 'source_currency' => $currency,
        'source_evidence' => 'verified-bill-extract', 'business_approval_reference' => 'approved-business-obligation',
        'department' => 'Teaching services', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '1', 'rate_source' => 'identity-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
    ]);
    /** @var User $reviewer */
    $reviewer = User::query()->whereKey(VendorDestinationApproval::current($context['institution']->id, $context['vendor']->id)->approved_by)->firstOrFail();
    app(ReviewInvoiceVersion::class)->handle($reviewer, $evidence, $evidence->snapshot_digest, 'approve_evidence', 'Source evidence checked.');

    return $evidence;
}

test('exact reviewed USDC invoice source binds full six decimal evidence without interpreting legacy amount float', function (): void {
    $context = vendorDraftContext();
    $context['invoice']->update(['amount' => '25.123456']);
    $version = exactDraftEvidence($context);
    $draft = prepareVendorDraft($context);
    expect($draft->amount_base_units)->toBe(25_000001)->and($draft->invoice_version_id)->toBe($version->id)
        ->and($draft->invoice_version_review_id)->toBeGreaterThan(0)->and($draft->vendor_destination_version_id)->toBeGreaterThan(0)
        ->and($draft->hasValidSnapshot())->toBeTrue()
        ->and(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft)->id)->toBe($draft->id)
        ->and(Transaction::query()->count())->toBe(0)->and($context['invoice']->fresh()->status)->toBe('pending');
});

test('local reference valuation cannot authorize USDC payment draft', function (): void {
    $context = vendorDraftContext();
    $version = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], [
        'capture_key' => (string) Str::uuid(), 'source_amount' => '25.01', 'source_currency' => 'PHP',
        'source_evidence' => 'verified-bill-extract', 'business_approval_reference' => 'approved-business-obligation',
        'department' => 'Teaching services', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '50', 'rate_source' => 'staff-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
    ]);
    /** @var User $reviewer */
    $reviewer = User::query()->whereKey(VendorDestinationApproval::current($context['institution']->id, $context['vendor']->id)->approved_by)->firstOrFail();
    app(ReviewInvoiceVersion::class)->handle($reviewer, $version, $version->snapshot_digest, 'approve_evidence', 'Source evidence checked.');
    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

test('missing destination approval prevents drafts and replacement stales recorded draft', function (string $case): void {
    $context = vendorDraftContext();
    $approval = VendorDestinationApproval::current($context['institution']->id, $context['vendor']->id);
    if ($case === 'missing') {
        DB::table((new VendorDestinationApproval)->getTable())->where('id', $approval->id)->delete();
        expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class);

        return;
    }
    $draft = prepareVendorDraft($context);
    $replacement = app(PrepareVendorDestination::class)->handle($context['actor'], $context['vendor'], 'v2', $context['vendor']->wallet_address, 'ARC-TESTNET', 'renewed-control-check');
    /** @var User $reviewer */
    $reviewer = User::query()->whereKey($approval->approved_by)->firstOrFail();
    app(ApproveVendorDestination::class)->handle($reviewer, $replacement, $replacement->content_digest, $approval->id, 'renewed-independent-contact');
    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class)
        ->and(fn (): PaymentIntent => prepareVendorDraft($context, $draft->intent_key))->toThrow(ValidationException::class)
        ->and($draft->fresh()->vendor_destination_approval_id)->toBe($approval->id);
})->with(['missing', 'replacement']);

test('current destination network and retained policy history are mandatory', function (string $case): void {
    $context = vendorDraftContext();
    $approval = VendorDestinationApproval::current($context['institution']->id, $context['vendor']->id);
    if ($case === 'tampered') {
        DB::table((new VendorDestinationApproval)->getTable())->where('id', $approval->id)->update(['verification_reference' => 'changed']);
    } else {
        config(['lepton.arc.chain' => 'ARC', 'lepton.arc.chain_id' => 5042]);
    }
    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class);
})->with(['tampered', 'chain']);

test('new payment evidence cannot be discarded through rollback and raw destination deletion', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $migration = require database_path('migrations/2026_10_08_031157_add_vendor_destination_context_to_payment_intents_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists')
        ->and(fn () => DB::table((new VendorDestinationApproval)->getTable())->where('id', $draft->vendor_destination_approval_id)->delete())->toThrow(QueryException::class);
});

test('destination context migration preserves old drafts without inferring review and restores monetary guards', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $row = $draft->getAttributes();
    unset($row['id'], $row['vendor_destination_version_id'], $row['vendor_destination_approval_id'], $row['invoice_version_id'], $row['invoice_version_review_id']);
    DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->delete();
    $migration = require database_path('migrations/2026_10_08_031157_add_vendor_destination_context_to_payment_intents_table.php');
    $migration->down();
    $id = DB::table((new PaymentIntent)->getTable())->insertGetId($row);
    $migration->up();
    /** @var PaymentIntent $legacy */
    $legacy = PaymentIntent::query()->findOrFail($id);
    expect($legacy->vendor_destination_version_id)->toBeNull()->and($legacy->vendor_destination_approval_id)->toBeNull()
        ->and($legacy->snapshot_digest)->toBe($draft->snapshot_digest)->and($legacy->hasValidSnapshot())->toBeFalse()
        ->and(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $id)->update(['amount_base_units' => 1.5]))->toThrow(QueryException::class)
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $legacy))->toThrow(ValidationException::class);
});

test('destination snapshot cannot conceal wrong recipient even with recomputed top-level digest', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $snapshot = $draft->snapshot;
    $snapshot['vendor_destination']['content']['address'] = '0x'.str_repeat('4', 40);
    $snapshot['vendor_destination']['content_digest'] = PaymentIntent::digest($snapshot['vendor_destination']['content']);
    DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
    expect($draft->fresh()->hasValidSnapshot())->toBeFalse();
});

test('prepares an exact institution vendor draft without students approval reservation or payment', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);

    expect($draft->status)->toBe('draft')
        ->and($draft->amount_base_units)->toBe(25_000000)
        ->and($draft->max_fee_base_units)->toBe(1)
        ->and($draft->snapshot['invoice']['amount_base_units'])->toBe('25000000')
        ->and($draft->hasValidSnapshot())->toBeTrue()
        ->and($draft->evidence()['can_execute'])->toBeFalse()
        ->and($draft->evidence()['approved'])->toBeFalse()
        ->and($draft->evidence()['funds_reserved'])->toBeFalse()
        ->and($context['invoice']->fresh()->status)->toBe('pending')
        ->and($context['wallet']->fresh()->balance)->toBe(500.0)
        ->and($context['budget']->fresh()->remaining_amount)->toBe(100.0)
        ->and(Student::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(Approval::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'vendor_payment_prepared')->count())->toBe(1);
    expect(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft)->id)->toBe($draft->id);
});

test('repeated preparation preserves one identity one audit and original preparer', function (): void {
    $context = vendorDraftContext();
    $key = (string) Str::uuid();
    $draft = prepareVendorDraft($context, $key);
    /** @var User $otherActor */
    $otherActor = User::factory()->create();
    $otherActor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $context['actor'] = $otherActor;
    $repeat = prepareVendorDraft($context, strtoupper($key));

    expect($repeat->id)->toBe($draft->id)
        ->and($repeat->prepared_by)->toBe($draft->prepared_by)
        ->and($repeat->prepared_by)->not->toBe($otherActor->id)
        ->and($repeat->provider_idempotency_key)->toBe('eduflow:'.$key)
        ->and(PaymentIntent::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'vendor_payment_prepared')->count())->toBe(1);
});

test('new intent identity cannot create another full-payment draft for the same bill', function (): void {
    $context = vendorDraftContext();
    prepareVendorDraft($context);

    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(1);
});

test('one intent identity cannot be reused for another bill', function (): void {
    $context = vendorDraftContext();
    $key = (string) Str::uuid();
    prepareVendorDraft($context, $key);
    $context['invoice'] = Invoice::query()->create([
        'organization_id' => $context['institution']->id, 'vendor_id' => $context['vendor']->id,
        'reference' => 'OTHER-'.Str::uuid(), 'amount' => '25', 'due_date' => now(), 'status' => 'pending',
    ]);

    expect(fn (): PaymentIntent => prepareVendorDraft($context, $key))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(1);
});

test('changed document destination treasury or policy makes a draft stale and retry conflicts', function (string $change): void {
    $context = vendorDraftContext();
    $key = (string) Str::uuid();
    $draft = prepareVendorDraft($context, $key);
    match ($change) {
        'amount' => $context['invoice']->update(['amount' => '26']),
        'reference' => $context['invoice']->update(['reference' => 'CHANGED']),
        'deadline' => $context['invoice']->update(['due_date' => now()->addDays(4)]),
        'destination' => $context['vendor']->update(['wallet_address' => '0x'.str_repeat('3', 40)]),
        'treasury' => $context['wallet']->update(['address' => '0x'.str_repeat('4', 40)]),
        'policy' => FinancePolicyVersion::query()->where('organization_id', $context['institution']->id)->update(['minimum_reserve_base_units' => 10]),
        'budget' => $context['budget']->update(['allocated_amount' => '110']),
        default => throw new LogicException('Unknown document-change test case.'),
    };

    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class)
        ->and(fn (): PaymentIntent => prepareVendorDraft($context, $key))->toThrow(ValidationException::class)
        ->and($draft->fresh()->snapshot_digest)->toBe($draft->snapshot_digest)
        ->and(PaymentIntent::query()->count())->toBe(1);
})->with(['amount', 'reference', 'deadline', 'destination', 'treasury', 'policy', 'budget']);

test('current verification refuses revoked vendor or closed invoice', function (string $change): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    if ($change === 'vendor') {
        $context['vendor']->update(['status' => 'pending']);
    } else {
        $context['invoice']->update(['status' => 'paid']);
    }

    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class);
})->with(['vendor', 'invoice']);

test('draft verification reloads stored fields and detects raw amount tampering', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['amount_base_units' => 1]);

    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class);
});

test('digest is stable for reordered JSON object keys but not changed values', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $snapshot = array_reverse($draft->snapshot, true);
    $snapshot['invoice'] = array_reverse($snapshot['invoice'], true);
    expect(PaymentIntent::digest($snapshot))->toBe($draft->snapshot_digest);
    $snapshot['invoice']['amount_base_units'] = '1';
    expect(PaymentIntent::digest($snapshot))->not->toBe($draft->snapshot_digest);
    expect(PaymentIntent::digest(['list' => [1, 2]]))->not->toBe(PaymentIntent::digest(['list' => [2, 1]]));
});

test('unauthorized actor cannot prepare read or execute a draft', function (): void {
    $context = vendorDraftContext();
    /** @var User $student */
    $student = User::factory()->create();
    expect(fn () => app(PrepareVendorPayment::class)->handle($student, $context['invoice'], $context['wallet'], new Money(0, CurrencyCode::USDC), (string) Str::uuid()))->toThrow(AuthorizationException::class);
    $draft = prepareVendorDraft($context);
    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($student, $draft))->toThrow(AuthorizationException::class)
        ->and(Gate::forUser($context['actor'])->allows('execute', $draft))->toBeFalse();
});

test('draft evidence cannot be changed or deleted through model operations', function (string $operation): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    expect(fn () => $operation === 'update' ? $draft->update(['status' => 'approved']) : $draft->delete())->toThrow(LogicException::class);
})->with(['update', 'delete']);

test('draft foreign keys preserve document and treasury evidence', function (string $parent): void {
    $context = vendorDraftContext();
    prepareVendorDraft($context);
    expect(fn () => $context[$parent]->delete())->toThrow(QueryException::class);
})->with(['invoice', 'wallet', 'vendor', 'budget', 'actor']);

test('invalid identity addresses fees currency or network cannot create drafts', function (string $case): void {
    $context = vendorDraftContext();
    $fee = new Money(0, CurrencyCode::USDC);
    $key = (string) Str::uuid();
    match ($case) {
        'identity' => $key = 'invalid',
        'destination' => $context['vendor']->update(['wallet_address' => '0xstudent_invalid']),
        'zero_destination' => $context['vendor']->update(['wallet_address' => '0x'.str_repeat('0', 40)]),
        'self_payment' => $context['vendor']->update(['wallet_address' => $context['wallet']->address]),
        'negative_fee' => $fee = new Money(-1, CurrencyCode::USDC),
        'fiat_fee' => $fee = new Money(1, CurrencyCode::PHP),
        'fiat_reporting' => $context['institution']->update(['currency' => 'PHP']),
        'network' => config(['lepton.arc.chain_id' => 1]),
        'configured_treasury' => config(['lepton.arc.treasury' => '0x'.str_repeat('9', 40)]),
        default => throw new LogicException('Unknown invalid-draft test case.'),
    };

    expect(fn () => app(PrepareVendorPayment::class)->handle($context['actor'], $context['invoice'], $context['wallet'], $fee, $key))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
})->with(['identity', 'destination', 'zero_destination', 'self_payment', 'negative_fee', 'fiat_fee', 'fiat_reporting', 'network', 'configured_treasury']);

test('legacy floating storage fails closed instead of claiming recovered precision', function (): void {
    $context = vendorDraftContext();
    DB::table((new Invoice)->getTable())->where('id', $context['invoice']->id)->update(['amount' => 25.123456]);
    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

test('factory builds a valid draft for existing institution documents', function (): void {
    $context = vendorDraftContext();
    /** @var PaymentIntent $draft */
    $draft = PaymentIntent::factory()->forVendorBill($context['invoice'], $context['wallet'], $context['actor'])->create();
    expect($draft->hasValidSnapshot())->toBeTrue();
});

test('snapshot refuses foreign vendor and budget ownership without inventing institution context', function (string $record): void {
    $context = vendorDraftContext();
    $foreign = clone $context[$record];
    $foreign->organization_id = $context['institution']->id + 1;
    $context['invoice']->setRelation($record, $foreign);

    expect(fn () => app(VendorPaymentSnapshot::class)->build($context['institution'], $context['invoice'], $context['wallet'], new Money(0, CurrencyCode::USDC)))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
})->with(['vendor', 'budget']);

test('preparation refuses a bill or wallet owned by another institution', function (string $record): void {
    $context = vendorDraftContext();
    /** @var Organization $foreign */
    $foreign = Organization::factory()->create();
    $context[$record]->update(['organization_id' => $foreign->id]);
    $resolver = Mockery::mock(InstallationInstitution::class);
    $resolver->allows(['require' => $context['institution'], 'current' => $context['institution']]);
    app()->instance(InstallationInstitution::class, $resolver);

    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ModelNotFoundException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
})->with(['invoice', 'wallet']);

test('ambiguous institution configuration cannot prepare a draft', function (): void {
    $context = vendorDraftContext();
    Organization::factory()->create();
    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(AuthorizationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

test('tampered snapshot metadata fails verification even when monetary fields are unchanged', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $snapshot = $draft->snapshot;
    $snapshot['policy']['minimum_reserve_base_units'] = '999';
    DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class);
});

test('database uniqueness rejects duplicate full-payment document and intent identities', function (string $duplicate): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $attributes = $draft->getAttributes();
    unset($attributes['id']);
    if ($duplicate === 'document') {
        $attributes['intent_key'] = (string) Str::uuid();
        $attributes['provider_idempotency_key'] = 'eduflow:'.$attributes['intent_key'];
    }

    expect(fn () => DB::table((new PaymentIntent)->getTable())->insert($attributes))->toThrow(QueryException::class);
})->with(['document', 'identity']);

test('database bounds reject promotion negative amounts and fee overflow', function (string $change): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $updates = match ($change) {
        'state' => ['status' => 'approved'],
        'negative' => ['amount_base_units' => -1],
        'zero' => ['amount_base_units' => 0],
        'fee' => ['max_fee_base_units' => -1],
        'overflow' => ['amount_base_units' => PHP_INT_MAX, 'max_fee_base_units' => 1],
        'fraction' => ['amount_base_units' => 1.5],
        default => throw new LogicException('Unknown bounds test case.'),
    };

    expect(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update($updates))->toThrow(QueryException::class);
})->with(['state', 'negative', 'zero', 'fee', 'overflow', 'fraction']);

test('negative or zero invoice cannot become a monetary draft', function (string $amount): void {
    $context = vendorDraftContext();
    $context['invoice']->update(['amount' => $amount]);
    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class);
})->with(['-25', '0']);

test('the CLI refuses absent staff wallet and retry identity', function (): void {
    $context = vendorDraftContext();
    artisan('eduflow:prepare-vendor-payment', ['invoice' => $context['invoice']->id, '--no-interaction' => true])
        ->expectsOutputToContain('required')->assertFailed();
    expect(PaymentIntent::query()->count())->toBe(0);
});

test('malformed persisted snapshot fails closed instead of throwing a shape error', function (string $value): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['snapshot' => $value]);

    expect($draft->fresh()->hasValidSnapshot())->toBeFalse()
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class);
})->with(['null', '42', '"text"', '[]', '{}', '{"invoice":"not-a-document"}']);

test('snapshot digest cannot hide invalid nested document shape', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $snapshot = $draft->snapshot;
    $snapshot['treasury'] = 'not-a-treasury';
    DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update([
        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'snapshot_digest' => PaymentIntent::digest($snapshot),
    ]);
    expect($draft->fresh()->hasValidSnapshot())->toBeFalse()
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class);
});

test('factory requires explicit source documents rather than making a fictional payable', function (): void {
    $context = vendorDraftContext();
    expect(fn () => PaymentIntent::factory()->make(['prepared_by' => $context['actor']->id]))->toThrow(LogicException::class, 'forVendorBill');
});

test('empty payment intent migration can roll back and recreate without affecting source tables', function (): void {
    $migration = require database_path('migrations/2026_10_06_072406_create_payment_intents_table.php');
    $migration->down();
    expect(Schema::hasTable('payment_intents'))->toBeFalse()
        ->and(Schema::hasTable('invoices'))->toBeTrue()
        ->and(Schema::hasTable('wallets'))->toBeTrue();
    $migration->up();
    expect(Schema::hasTable('payment_intents'))->toBeTrue();
});

test('payment intent migration refuses rollback that would erase prepared evidence', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $migration = require database_path('migrations/2026_10_06_072406_create_payment_intents_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists')
        ->and(PaymentIntent::query()->whereKey($draft->id)->exists())->toBeTrue();
});

test('operator CLI prepares an attributed draft only with explicit document identities', function (): void {
    $context = vendorDraftContext();
    artisan('eduflow:prepare-vendor-payment', [
        'invoice' => $context['invoice']->id, '--actor' => $context['actor']->id,
        '--wallet' => $context['wallet']->id, '--max-fee' => '0.000001', '--intent-key' => (string) Str::uuid(), '--no-interaction' => true,
    ])->expectsOutputToContain('"can_execute": false')->assertSuccessful();

    expect(PaymentIntent::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('policy replacement invalidates existing draft and retry without rewriting original evidence', function (): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $current = FinancePolicyActivation::current($context['institution']->id);
    $policy = app(CreateFinancePolicyVersion::class)->handle($context['actor'], 'v2', new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(10, CurrencyCode::USDC));
    /** @var User $reviewer */
    $reviewer = User::query()->whereKey($current->approved_by)->firstOrFail();
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, $current->id);
    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class)
        ->and(fn (): PaymentIntent => prepareVendorDraft($context, $draft->intent_key))->toThrow(ValidationException::class)
        ->and($draft->fresh()->finance_policy_activation_id)->toBe($current->id)
        ->and(PaymentIntent::query()->count())->toBe(1);
});

test('corrupt predecessor policy blocks current draft preparation and verification', function (): void {
    $context = vendorDraftContext();
    $root = FinancePolicyActivation::current($context['institution']->id);
    $policy = app(CreateFinancePolicyVersion::class)->handle($context['actor'], 'v2', new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(10, CurrencyCode::USDC));
    /** @var User $reviewer */
    $reviewer = User::query()->whereKey($root->approved_by)->firstOrFail();
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, $root->id);
    $draft = prepareVendorDraft($context);
    DB::table((new FinancePolicyVersion)->getTable())->where('id', $root->finance_policy_version_id)->update(['minimum_reserve_base_units' => 1]);
    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class)
        ->and(fn (): PaymentIntent => prepareVendorDraft($context, $draft->intent_key))->toThrow(ValidationException::class);
});

test('policy context upgrade retains legacy evidence without guessing activation', function (): void {
    $migration = require database_path('migrations/2026_10_07_004315_add_finance_policy_context_to_payment_intents_table.php');
    $migration->down();
    $context = vendorDraftContext();
    $snapshot = app(VendorPaymentSnapshot::class)->build($context['institution'], $context['invoice'], $context['wallet'], new Money(0, CurrencyCode::USDC));
    $key = (string) Str::uuid();
    $snapshot['intent_key'] = $key;
    $snapshot['prepared_by'] = $context['actor']->id;
    $row = [
        'organization_id' => $context['institution']->id, 'invoice_id' => $context['invoice']->id,
        'wallet_id' => $context['wallet']->id, 'vendor_id' => $context['vendor']->id, 'budget_id' => $context['budget']->id,
        'prepared_by' => $context['actor']->id, 'intent_key' => $key, 'provider_idempotency_key' => 'eduflow:'.$key,
        'amount_base_units' => 25_000000, 'max_fee_base_units' => 0, 'chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
        'source_address' => $snapshot['treasury']['source_address'], 'recipient_address' => $snapshot['vendor']['recipient_address'],
        'snapshot_digest' => PaymentIntent::digest($snapshot), 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ];
    $id = DB::table((new PaymentIntent)->getTable())->insertGetId($row);
    expect(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $id)->update(['amount_base_units' => 1.5]))->toThrow(QueryException::class);
    $migration->up();
    /** @var PaymentIntent $legacy */
    $legacy = PaymentIntent::query()->findOrFail($id);
    expect($legacy->finance_policy_version_id)->toBeNull()
        ->and($legacy->finance_policy_activation_id)->toBeNull()
        ->and($legacy->snapshot_digest)->toBe($row['snapshot_digest'])
        ->and($legacy->snapshot)->toBe($snapshot)
        ->and($legacy->hasValidSnapshot())->toBeFalse()
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $legacy))->toThrow(ValidationException::class)
        ->and(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $id)->update(['amount_base_units' => 1.5]))->toThrow(QueryException::class);
});

test('missing independently active policy refuses vendor preparation', function (): void {
    $context = vendorDraftContext();
    DB::table((new FinancePolicyActivation)->getTable())->delete();
    expect(fn (): PaymentIntent => prepareVendorDraft($context))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

test('vendor draft fee must stay within active policy exact ceiling', function (): void {
    $context = vendorDraftContext();
    expect(fn () => app(PrepareVendorPayment::class)->handle($context['actor'], $context['invoice'], $context['wallet'], new Money(11, CurrencyCode::USDC), (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

test('draft policy IDs and content cannot disagree with immutable snapshot', function (string $case): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    if ($case === 'missing') {
        DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['finance_policy_version_id' => null]);
    } else {
        $snapshot = $draft->snapshot;
        $snapshot['policy']['content']['institution_id'] = $context['institution']->id + 1;
        $snapshot['policy']['content_digest'] = PaymentIntent::digest($snapshot['policy']['content']);
        DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
    }
    expect($draft->fresh()->hasValidSnapshot())->toBeFalse()
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $draft))->toThrow(ValidationException::class);
})->with(['missing', 'foreign_content']);

test('vendor draft restrictive policy FKs retain activated evidence', function (string $record): void {
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    $table = $record === 'version' ? (new FinancePolicyVersion)->getTable() : (new FinancePolicyActivation)->getTable();
    $id = $record === 'version' ? $draft->finance_policy_version_id : $draft->finance_policy_activation_id;
    expect(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class);
})->with(['version', 'activation']);

test('payment policy migration refuses rollback with context and preserves monetary triggers during empty rollback', function (): void {
    $migration = require database_path('migrations/2026_10_07_004315_add_finance_policy_context_to_payment_intents_table.php');
    $migration->down();
    expect(Schema::hasColumn('payment_intents', 'finance_policy_version_id'))->toBeFalse();
    $migration->up();
    $context = vendorDraftContext();
    $draft = prepareVendorDraft($context);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists')
        ->and(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $draft->id)->update(['amount_base_units' => 0]))->toThrow(QueryException::class);
});
