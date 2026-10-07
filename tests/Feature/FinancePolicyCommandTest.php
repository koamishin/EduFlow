<?php

declare(strict_types=1);

use App\Actions\CreateFinancePolicyVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\artisan;

/** @return array{institution: Organization, maker: User, reviewer: User} */
function policyCommandContext(): array
{
    config(['eduflow.institution_id' => null]);
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    /** @var User $maker */
    $maker = User::factory()->create();
    $maker->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

    return ['institution' => $institution, 'maker' => $maker, 'reviewer' => $reviewer];
}

function commandPolicy(User $maker, string $version = 'v1'): FinancePolicyVersion
{
    return app(CreateFinancePolicyVersion::class)->handle($maker, $version,
        new Money(1, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC));
}

test('policy CLI preserves six decimal reserve and zero spending defaults with repeat safe preparation', function (): void {
    $context = policyCommandContext();
    $arguments = ['version' => 'term-1', '--actor' => $context['maker']->id, '--reserve' => '100.000001', '--no-interaction' => true];
    artisan('eduflow:prepare-finance-policy', $arguments)->expectsOutputToContain('operator attribution')->expectsOutputToContain('"can_execute": false')->assertSuccessful();
    artisan('eduflow:prepare-finance-policy', $arguments)->assertSuccessful();
    /** @var FinancePolicyVersion $policy */
    $policy = FinancePolicyVersion::query()->sole();
    expect($policy->minimum_reserve_base_units)->toBe(100_000001)
        ->and($policy->max_auto_payment_base_units)->toBe(0)
        ->and($policy->max_daily_disbursement_base_units)->toBe(0)
        ->and($policy->hasValidContent())->toBeTrue()
        ->and(FinancePolicyActivation::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'finance_policy_created')->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('policy CLI requires explicit reserve and authorized actor', function (string $case): void {
    $context = policyCommandContext();
    $arguments = ['version' => 'v1', '--actor' => $context['maker']->id, '--reserve' => '1'];
    match ($case) {
        'actor' => $arguments['--actor'] = null,
        'reserve' => $arguments['--reserve'] = null,
        'invalid_actor' => $arguments['--actor'] = '1.5',
        default => throw new LogicException('Unknown CLI case.'),
    };
    artisan('eduflow:prepare-finance-policy', $arguments)->assertFailed();
    expect(FinancePolicyVersion::query()->count())->toBe(0);
})->with(['actor', 'reserve', 'invalid_actor']);

test('policy CLI rejects malformed inexact negative and overflowing USDC', function (string $amount): void {
    $context = policyCommandContext();
    artisan('eduflow:prepare-finance-policy', ['version' => 'v1', '--actor' => $context['maker']->id, '--reserve' => $amount])->assertFailed();
    expect(FinancePolicyVersion::query()->count())->toBe(0);
})->with(['1e3', '1.0000001', '-1', ' 1', '9223372036855']);

test('policy CLI refuses unauthorized preparer and automatic cap above daily limit', function (string $case): void {
    $context = policyCommandContext();
    /** @var User $ordinary */
    $ordinary = User::factory()->create();
    artisan('eduflow:prepare-finance-policy', [
        'version' => 'v1', '--actor' => $case === 'authority' ? $ordinary->id : $context['maker']->id,
        '--reserve' => '0', '--auto-limit' => $case === 'cap' ? '1' : '0',
    ])->assertFailed();
    expect(FinancePolicyVersion::query()->count())->toBe(0);
})->with(['authority', 'cap']);

test('activation CLI requires explicit expected context and preserves retry evidence', function (): void {
    $context = policyCommandContext();
    $policy = commandPolicy($context['maker']);
    $arguments = ['policy' => $policy->id, '--reviewer' => $context['reviewer']->id];
    artisan('eduflow:activate-finance-policy', $arguments)->expectsOutputToContain('explicit')->assertFailed();
    expect(FinancePolicyActivation::query()->count())->toBe(0);
    $arguments['--expected-activation'] = 'none';
    artisan('eduflow:activate-finance-policy', $arguments)->expectsOutputToContain('"payment_approval_granted": false')->assertSuccessful();
    artisan('eduflow:activate-finance-policy', $arguments)->assertSuccessful();
    expect(FinancePolicyActivation::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'finance_policy_activated')->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('activation CLI refuses self review even super admin and unauthorized reviewer', function (string $case): void {
    $context = policyCommandContext();
    $policy = commandPolicy($context['maker']);
    $context['maker']->assignRole(Role::findOrCreate('super_admin', 'web'));
    /** @var User $ordinary */
    $ordinary = User::factory()->create();
    artisan('eduflow:activate-finance-policy', [
        'policy' => $policy->id, '--reviewer' => $case === 'self' ? $context['maker']->id : $ordinary->id,
        '--expected-activation' => 'none',
    ])->assertFailed();
    expect(FinancePolicyActivation::query()->count())->toBe(0);
})->with(['self', 'unauthorized']);

test('activation CLI refuses invalid IDs and expected context', function (string $option, string $value): void {
    $context = policyCommandContext();
    $policy = commandPolicy($context['maker']);
    $arguments = ['policy' => $policy->id, '--reviewer' => $context['reviewer']->id, '--expected-activation' => 'none'];
    $arguments[$option] = $value;
    artisan('eduflow:activate-finance-policy', $arguments)->assertFailed();
    expect(FinancePolicyActivation::query()->count())->toBe(0);
})->with([['policy', '0'], ['--reviewer', '0'], ['--expected-activation', '0'], ['--expected-activation', '1.5'], ['--expected-activation', '']]);

test('activation CLI replacement requires reviewed current ID not inferred latest', function (): void {
    $context = policyCommandContext();
    $first = commandPolicy($context['maker']);
    artisan('eduflow:activate-finance-policy', ['policy' => $first->id, '--reviewer' => $context['reviewer']->id, '--expected-activation' => 'none'])->assertSuccessful();
    $current = FinancePolicyActivation::current($context['institution']->id);
    $next = commandPolicy($context['maker'], 'v2');
    artisan('eduflow:activate-finance-policy', ['policy' => $next->id, '--reviewer' => $context['reviewer']->id, '--expected-activation' => 'none'])->assertFailed();
    artisan('eduflow:activate-finance-policy', ['policy' => $next->id, '--reviewer' => $context['reviewer']->id, '--expected-activation' => $current->id])->assertSuccessful();
    expect(FinancePolicyActivation::query()->count())->toBe(2);
});

test('activation CLI cannot select foreign institution policy', function (): void {
    $context = policyCommandContext();
    config(['eduflow.institution_id' => $context['institution']->id]);
    /** @var Organization $foreign */
    $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
    /** @var FinancePolicyVersion $policy */
    $policy = FinancePolicyVersion::factory()->create(['organization_id' => $foreign->id, 'created_by' => $context['maker']->id]);
    artisan('eduflow:activate-finance-policy', ['policy' => $policy->id, '--reviewer' => $context['reviewer']->id, '--expected-activation' => 'none'])->assertFailed();
    expect(FinancePolicyActivation::query()->count())->toBe(0);
});
