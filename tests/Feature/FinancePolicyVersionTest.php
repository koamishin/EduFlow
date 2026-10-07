<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\CreateFinancePolicyVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/** @return array{institution: Organization, maker: User, reviewer: User} */
function financePolicyContext(): array
{
    config(['eduflow.institution_id' => null]);
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

function makeFinancePolicy(User $maker, string $version = 'v1'): FinancePolicyVersion
{
    return app(CreateFinancePolicyVersion::class)->handle($maker, $version,
        new Money(100_000000, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(1, CurrencyCode::USDC));
}

test('policy preparation keeps exact limits and zero autonomous spending without activating', function (): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    expect($policy->hasValidContent())->toBeTrue()
        ->and($policy->minimum_reserve_base_units)->toBe(100_000000)
        ->and($policy->max_fee_base_units)->toBe(1)
        ->and($policy->max_auto_payment_base_units)->toBe(0)
        ->and(FinancePolicyActivation::current($context['institution']->id))->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);
});

test('policy creation is repeat safe and refuses changing an existing version', function (): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    expect(makeFinancePolicy($context['maker'])->id)->toBe($policy->id)
        ->and(FinancePolicyVersion::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'finance_policy_created')->count())->toBe(1);
    expect(fn () => app(CreateFinancePolicyVersion::class)->handle($context['maker'], 'v1', new Money(1, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC)))->toThrow(ValidationException::class);
});

test('separate reviewer activates policy and identical retry preserves one audit', function (): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    $activation = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, null);
    $repeat = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, null);
    expect($activation->hasValidEvidence($policy))->toBeTrue()
        ->and($repeat->id)->toBe($activation->id)
        ->and(FinancePolicyActivation::current($context['institution']->id)?->id)->toBe($activation->id)
        ->and(Activity::query()->where('event', 'finance_policy_activated')->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('self approval stays forbidden even for a super administrator', function (): void {
    $context = financePolicyContext();
    $context['maker']->assignRole(Role::findOrCreate('super_admin', 'web'));
    $policy = makeFinancePolicy($context['maker']);
    expect(fn () => app(ActivateFinancePolicy::class)->handle($context['maker'], $policy, null))->toThrow(AuthorizationException::class)
        ->and(FinancePolicyActivation::query()->count())->toBe(0);
});

test('finance officer cannot activate and unprivileged user cannot prepare a policy', function (): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    /** @var User $otherOfficer */
    $otherOfficer = User::factory()->create();
    $otherOfficer->assignRole(Role::findOrCreate('finance_officer', 'web'));
    expect(fn () => app(ActivateFinancePolicy::class)->handle($otherOfficer, $policy, null))->toThrow(AuthorizationException::class);
    /** @var User $ordinary */
    $ordinary = User::factory()->create();
    expect(fn (): FinancePolicyVersion => makeFinancePolicy($ordinary))->toThrow(AuthorizationException::class);
});

test('replacement checks expected activation and cannot reactivate an older version', function (): void {
    $context = financePolicyContext();
    $first = makeFinancePolicy($context['maker']);
    $activation = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, null);
    $second = makeFinancePolicy($context['maker'], 'v2');
    expect(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, null))->toThrow(ValidationException::class);
    $replacement = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, $activation->id);
    expect($replacement->previous_activation_id)->toBe($activation->id)
        ->and(FinancePolicyActivation::query()->count())->toBe(2);
    expect(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, $replacement->id))->toThrow(ValidationException::class);
});

test('immutable versions and activations reject updates and deletes', function (string $target, string $operation): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    $activation = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, null);
    $record = $target === 'policy' ? $policy : $activation;
    expect(fn () => $operation === 'delete' ? $record->delete() : $record->update(['organization_id' => 999]))->toThrow(LogicException::class);
})->with([['policy', 'update'], ['policy', 'delete'], ['activation', 'update'], ['activation', 'delete']]);

test('policy tampering cannot be hidden by stale caller model during activation', function (): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    DB::table((new FinancePolicyVersion)->getTable())->where('id', $policy->id)->update(['minimum_reserve_base_units' => 0]);
    expect(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, null))->toThrow(ValidationException::class);
});

test('activation tampering blocks both retry and replacement', function (): void {
    $context = financePolicyContext();
    $first = makeFinancePolicy($context['maker']);
    $activation = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, null);
    $second = makeFinancePolicy($context['maker'], 'v2');
    DB::table((new FinancePolicyActivation)->getTable())->where('id', $activation->id)->update(['policy_digest' => str_repeat('0', 64)]);
    expect(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, null))->toThrow(ValidationException::class)
        ->and(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, $activation->id))->toThrow(ValidationException::class);
});

test('exact policy bounds and stable version identifier are required', function (string $case): void {
    $context = financePolicyContext();
    $reserve = new Money(1, CurrencyCode::USDC);
    $auto = new Money(0, CurrencyCode::USDC);
    $daily = new Money(0, CurrencyCode::USDC);
    $version = 'v1';
    match ($case) {
        'negative' => $reserve = new Money(-1, CurrencyCode::USDC),
        'currency' => $reserve = new Money(1, CurrencyCode::PHP),
        'cap' => $auto = new Money(1, CurrencyCode::USDC),
        'version' => $version = 'invalid version',
        default => throw new LogicException('Unknown policy test case.'),
    };
    expect(fn () => app(CreateFinancePolicyVersion::class)->handle($context['maker'], $version, $reserve, $auto, $daily, new Money(0, CurrencyCode::USDC)))->toThrow(ValidationException::class);
})->with(['negative', 'currency', 'cap', 'version']);

test('policy evidence prevents cascading deletion of institution or actors', function (string $target): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, null);
    expect(fn () => $context[$target]->delete())->toThrow(QueryException::class);
})->with(['institution', 'maker', 'reviewer']);

test('policy factory creates intact zero-automation content without activation', function (): void {
    $context = financePolicyContext();
    /** @var FinancePolicyVersion $policy */
    $policy = FinancePolicyVersion::factory()->create(['organization_id' => $context['institution']->id, 'created_by' => $context['maker']->id]);
    expect($policy->hasValidContent())->toBeTrue()
        ->and($policy->max_auto_payment_base_units)->toBe(0)
        ->and(FinancePolicyActivation::query()->count())->toBe(0);
});

test('policy migration rolls back only empty evidence tables', function (): void {
    $migration = require database_path('migrations/2026_10_06_130313_create_finance_policy_versions_table.php');
    $contextMigration = require database_path('migrations/2026_10_07_004315_add_finance_policy_context_to_payment_intents_table.php');
    $contextMigration->down();
    $migration->down();
    expect(Schema::hasTable('finance_policy_versions'))->toBeFalse();
    $migration->up();
    $contextMigration->up();
    $context = financePolicyContext();
    makeFinancePolicy($context['maker']);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});

test('corrupted historical policy or activation blocks new activation and current retry', function (string $case): void {
    $context = financePolicyContext();
    $first = makeFinancePolicy($context['maker']);
    $initial = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, null);
    $second = makeFinancePolicy($context['maker'], 'v2');
    $current = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, $initial->id);
    $next = makeFinancePolicy($context['maker'], 'v3');
    if ($case === 'policy') {
        DB::table((new FinancePolicyVersion)->getTable())->where('id', $first->id)->update(['minimum_reserve_base_units' => 0]);
    } else {
        DB::table((new FinancePolicyActivation)->getTable())->where('id', $initial->id)->update(['approved_by' => $context['maker']->id]);
    }
    expect($current->hasValidHistory())->toBeFalse()
        ->and(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, $initial->id))->toThrow(ValidationException::class)
        ->and(fn () => app(ActivateFinancePolicy::class)->handle($context['reviewer'], $next, $current->id))->toThrow(ValidationException::class)
        ->and(FinancePolicyActivation::query()->count())->toBe(2);
})->with(['policy', 'activation']);

test('history refuses cycles missing root skipped predecessors and foreign predecessors despite recomputed own digest', function (string $case): void {
    $context = financePolicyContext();
    $first = makeFinancePolicy($context['maker']);
    $root = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, null);
    $second = makeFinancePolicy($context['maker'], 'v2');
    $current = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, $root->id);
    $current->previous_activation_id = $case === 'cycle' ? $current->id : null;
    $current->previous_activation_digest = $case === 'cycle' ? $current->activation_digest : null;
    if ($case === 'foreign') {
        /** @var Organization $foreign */
        $foreign = Organization::factory()->create();
        DB::table((new FinancePolicyActivation)->getTable())->where('id', $root->id)->update(['organization_id' => $foreign->id]);
        $current->previous_activation_id = $root->id;
        $current->previous_activation_digest = $root->activation_digest;
    }
    if ($case === 'missing') {
        expect(fn () => DB::table((new FinancePolicyActivation)->getTable())->where('id', $root->id)->delete())->toThrow(QueryException::class);

        return;
    }
    if ($case === 'skipped') {
        expect(fn () => DB::table((new FinancePolicyActivation)->getTable())->where('id', $current->id)->update(['previous_activation_id' => null]))->toThrow(QueryException::class);

        return;
    }
    DB::table((new FinancePolicyActivation)->getTable())->where('id', $current->id)->update([
        'previous_activation_id' => $current->previous_activation_id,
        'previous_activation_digest' => $current->previous_activation_digest,
        'activation_digest' => PaymentIntent::digest($current->content()),
    ]);
    expect($current->fresh()->hasValidHistory())->toBeFalse();
})->with(['cycle', 'missing', 'skipped', 'foreign']);

test('policy DB bounds reject invalid raw values on insert and update', function (string $case, string $operation): void {
    $context = financePolicyContext();
    $policy = makeFinancePolicy($context['maker']);
    $updates = match ($case) {
        'reserve' => ['minimum_reserve_base_units' => -1],
        'auto' => ['max_auto_payment_base_units' => -1],
        'daily' => ['max_daily_disbursement_base_units' => -1],
        'fee' => ['max_fee_base_units' => -1],
        'fraction' => ['minimum_reserve_base_units' => 0.5],
        'cap' => ['max_auto_payment_base_units' => 1],
        'currency' => ['currency' => 'PHP'],
        default => throw new LogicException('Unknown policy bounds case.'),
    };
    $row = array_merge($policy->getAttributes(), $updates, ['version' => 'v2']);
    unset($row['id']);
    expect(fn () => $operation === 'insert'
        ? DB::table((new FinancePolicyVersion)->getTable())->insert($row)
        : DB::table((new FinancePolicyVersion)->getTable())->where('id', $policy->id)->update($updates))->toThrow(QueryException::class);
})->with(['reserve', 'auto', 'daily', 'fee', 'fraction', 'cap', 'currency'])->with(['insert', 'update']);

test('activation DB enforces one root and one successor per institution', function (string $case): void {
    $context = financePolicyContext();
    $first = makeFinancePolicy($context['maker']);
    $root = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $first, null);
    $second = makeFinancePolicy($context['maker'], 'v2');
    $current = app(ActivateFinancePolicy::class)->handle($context['reviewer'], $second, $root->id);
    $next = makeFinancePolicy($context['maker'], 'v3');
    $row = $case === 'root' ? $root->getAttributes() : $current->getAttributes();
    unset($row['id']);
    $row['finance_policy_version_id'] = $next->id;
    expect(fn () => DB::table((new FinancePolicyActivation)->getTable())->insert($row))->toThrow(QueryException::class);
})->with(['root', 'successor']);
