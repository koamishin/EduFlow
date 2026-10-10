<?php

declare(strict_types=1);

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewInvoiceVersion;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DepartmentBudgetPlanner;
use App\Services\InstallationInstitution;
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

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/** @return array{institution: Organization, actor: User, reviewer: User, budget: Budget, bills: list<InvoiceVersion>} */
function budgetPlanContext(): array
{
    config(['eduflow.institution_id' => null]);
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => 'PHP']);
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'IT department', 'category' => 'software',
        'allocated_amount' => '999999', 'spent_amount' => '0', 'remaining_amount' => '999999', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Supplier alias',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);
    $bills = [];
    foreach (['25.00', '30.00'] as $index => $amount) {
        $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'budget_id' => $budget->id,
            'vendor_id' => $vendor->id, 'reference' => 'DEPT-'.Str::uuid(), 'amount' => $amount,
            'due_date' => now()->addDays($index + 1), 'category' => 'software', 'status' => 'pending']);
        $bill = app(CaptureInvoiceVersion::class)->handle($actor, $invoice, [
            'capture_key' => (string) Str::uuid(), 'source_amount' => $amount, 'source_currency' => 'PHP',
            'source_evidence' => 'source-extract-'.$index, 'business_approval_reference' => 'department-approval-'.$index,
            'department' => 'IT department', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
            'source_per_usdc' => '50', 'rate_source' => 'staff-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
        ]);
        app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Source and reference rate checked.');
        $bills[] = $bill;
    }

    return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'budget' => $budget, 'bills' => $bills];
}

/** @param list<InvoiceVersion> $bills
 * @return array<string, mixed>
 */
function budgetPlanInput(array $bills, string $receipts = '10'): array
{
    $collectionIds = [];
    if ($receipts !== '0') {
        /** @var User $maker */
        $maker = User::query()->findOrFail($bills[0]->prepared_by);
        /** @var InvoiceVersionReview $billReview */
        $billReview = InvoiceVersionReview::query()->where('invoice_version_id', $bills[0]->id)->firstOrFail();
        /** @var User $reviewer */
        $reviewer = User::query()->findOrFail($billReview->reviewed_by);
        /** @var CollectionBatch|null $batch */
        $batch = CollectionBatch::query()->where('organization_id', $bills[0]->organization_id)->where('source_reference', 'planning-fixture-receipts')->first();
        $batch ??= app(CaptureCollectionBatch::class)->handle($maker, [
            'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-test', 'source_reference' => 'planning-fixture-receipts',
            'source_document_digest' => hash('sha256', 'planning-test-collection'), 'currency' => 'PHP', 'received_amount' => $receipts, 'restricted_amount' => '0',
            'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
            'cash_evidence_reference' => 'synthetic-reconciled-cashier-report', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
        ]);
        $collectionIds[] = app(ReviewCollectionBatch::class)->handle($reviewer, $batch, $batch->snapshot_digest, 'approve_receipts',
            'synthetic-independent-report-check', 'Actual receipts and restrictions checked.')->id;
    }

    return ['capture_key' => (string) Str::uuid(), 'currency' => 'PHP', 'department' => 'IT department',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => array_map(fn (InvoiceVersion $bill): int => $bill->id, $bills), 'allocation' => '100.00', 'already_spent' => '10.00',
        'other_budget_commitments' => '0', 'opening_funds' => '80', 'realized_receipts' => $receipts, 'actual_outflows' => '5',
        'restricted_cash' => '10', 'protected_reserve' => '30', 'other_cash_commitments' => '0',
        'budget_evidence' => 'approved-budget-extract', 'cash_evidence' => 'verified-realized-receipts', 'commitment_evidence' => 'other-obligations-extract',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => $collectionIds];
}

test('exact department budget and realized cash headroom stay separate and cumulative across bills', function (): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    $plan = app(DepartmentBudgetPlanner::class)->handle($context['reviewer'], $snapshot);
    expect($snapshot->hasValidSnapshot())->toBeTrue()->and($plan['headroom'])->toBe(['budget_minor_units' => '9000', 'cash_minor_units' => '4500'])
        ->and($plan['bills'][0]['decision'])->toBe('propose_human_review')->and($plan['bills'][1]['decision'])->toBe('hold')
        ->and($plan['bills'][1]['checks']['realized_cash_available'])->toBeFalse()
        ->and($plan['remaining_budget_minor_units'])->toBe('6500')->and($plan['remaining_cash_minor_units'])->toBe('2000')
        ->and($plan['suggested_usdc_valuation_base_units'])->toBe('500000')->and($plan['can_execute'])->toBeFalse()
        ->and($plan['settlement_funding_verified'])->toBeFalse()->and($plan['funds_reserved'])->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0)->and(Student::query()->count())->toBe(0)
        ->and($context['budget']->fresh()->remaining_amount)->toBe(999999.0)
        ->and(Invoice::query()->where('status', 'pending')->count())->toBe(2);
});

test('budget alone cannot make a local cash shortfall affordable and exact boundary is inclusive', function (string $case): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput($context['bills'], '0');
    $data['opening_funds'] = $case === 'empty_cash' ? '0' : '25';
    foreach (['realized_receipts', 'actual_outflows', 'restricted_cash', 'protected_reserve'] as $field) {
        $data[$field] = '0';
    }
    if ($case === 'empty_budget') {
        $data['allocation'] = '0';
    }
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], $data);
    $plan = app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot);
    expect($plan['bills'][0]['decision'])->toBe($case === 'boundary' ? 'propose_human_review' : 'hold');
})->with(['empty_cash', 'empty_budget', 'boundary']);

test('negative headroom is visible instead of inventing cash and every bill remains held', function (): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput($context['bills']);
    $data['allocation'] = '5';
    $data['opening_funds'] = '0';
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], $data);
    $plan = app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot);
    expect($plan['headroom']['budget_minor_units'])->toBe('-500')->and($plan['headroom']['cash_minor_units'])->toBe('-3500')
        ->and(array_column($plan['bills'], 'decision'))->toBe(['hold', 'hold']);
});

test('snapshot capture retries preserve identity and reject changed amounts', function (): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput($context['bills']);
    $capture = app(CaptureBudgetSnapshot::class);
    $snapshot = $capture->handle($context['actor'], $context['budget'], $data);
    $data['bill_ids'] = array_reverse($data['bill_ids']);
    expect($capture->handle($context['actor'], $context['budget'], $data)->id)->toBe($snapshot->id)
        ->and(Activity::query()->where('event', 'budget_snapshot_captured')->count())->toBe(1);
    $data['realized_receipts'] = '11';
    expect(fn () => $capture->handle($context['actor'], $context['budget'], $data))->toThrow(ValidationException::class)
        ->and(BudgetSnapshot::query()->count())->toBe(1);
});

test('snapshot requires exact money source evidence and disjoint commitment attestations', function (string $case): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput($context['bills']);
    match ($case) {
        'inexact' => $data['realized_receipts'] = '0.001', 'negative' => $data['opening_funds'] = '-1',
        'numeric' => $data['allocation'] = 100, 'forecast' => $data['realized_receipts'] = 'future fees',
        'overlap' => $data['commitments_exclude_selected_bills'] = false, 'buckets' => $data['cash_buckets_disjoint'] = false,
        'missing_evidence' => $data['cash_evidence'] = '', 'duplicate' => $data['bill_ids'] = [$context['bills'][0]->id, $context['bills'][0]->id],
        'currency' => $data['currency'] = 'INVALID', 'department' => $data['department'] = 'Other department',
        'period' => $data['period_end'] = now()->addDays(10)->toDateString(),
        'future' => $data['as_of'] = now()->addMinute()->toIso8601String(),
        default => throw new LogicException('Unknown case.'),
    };
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], $data))->toThrow(ValidationException::class);
})->with(['inexact', 'negative', 'numeric', 'forecast', 'overlap', 'buckets', 'missing_evidence', 'duplicate', 'currency', 'department', 'period', 'future']);

test('changed source or review evidence blocks complete plan instead of freeing uncertain cash', function (string $case): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    $bill = $context['bills'][0];
    match ($case) {
        'bill' => DB::table((new InvoiceVersion)->getTable())->where('id', $bill->id)->update(['valuation_base_units' => 1]),
        'invoice' => Invoice::query()->whereKey($bill->invoice_id)->update(['reference' => 'CHANGED']),
        'review' => DB::table((new InvoiceVersionReview)->getTable())->where('invoice_version_id', $bill->id)->update(['decision' => 'reject']),
        default => throw new LogicException('Unknown case.'),
    };
    expect(fn () => app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot))->toThrow(ValidationException::class);
})->with(['bill', 'invoice', 'review']);

test('budget planner is replay safe and stays bound to selected bills rather than all department invoices', function (): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput([$context['bills'][0]]);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], $data);
    $planner = app(DepartmentBudgetPlanner::class);
    $first = $planner->handle($context['actor'], $snapshot);
    expect($planner->handle($context['actor'], $snapshot))->toBe($first)
        ->and(array_column($first['bills'], 'invoice_version_id'))->toBe([$context['bills'][0]->id])
        ->and(Transaction::query()->count())->toBe(0);
});

test('arbitrary precision headroom handles sums beyond signed integer range without float conversion', function (): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput($context['bills'], '92233720368547758.07');
    $data['opening_funds'] = '92233720368547758.07';
    $data['realized_receipts'] = '92233720368547758.07';
    foreach (['actual_outflows', 'restricted_cash', 'protected_reserve', 'other_cash_commitments'] as $field) {
        $data[$field] = '0';
    }
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], $data);
    $plan = app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot);
    expect($plan['headroom']['cash_minor_units'])->toBe('18446744073709551614')
        ->and($plan['remaining_cash_minor_units'])->toBe('18446744073709546114');
});

test('malformed bound bill snapshot refuses complete plan before sorting or calculating', function (): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    DB::table((new InvoiceVersion)->getTable())->where('id', $context['bills'][0]->id)->update(['snapshot' => 'null']);
    expect(fn () => app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot))->toThrow(ValidationException::class);
});

test('departmental snapshot and plan refuse unauthorized or unverified staff', function (string $case): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    /** @var User $other */
    $other = User::factory()->create(['email_verified_at' => null]);
    if ($case === 'unverified') {
        $other->assignRole(Role::findOrCreate('admin', 'web'));
    }
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($other, $context['budget'], budgetPlanInput($context['bills'])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DepartmentBudgetPlanner::class)->handle($other, $snapshot))->toThrow(AuthorizationException::class);
})->with(['ordinary', 'unverified']);

test('closed department plan cannot bind foreign budget even with installation resolver controlled', function (): void {
    $context = budgetPlanContext();
    /** @var Organization $foreign */
    $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
    $resolver = Mockery::mock(InstallationInstitution::class);
    $resolver->shouldReceive('current')->andReturn($context['institution']);
    $resolver->shouldReceive('require')->andReturn($context['institution']);
    app()->instance(InstallationInstitution::class, $resolver);
    $context['budget']->update(['organization_id' => $foreign->id]);
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills'])))->toThrow(ModelNotFoundException::class);
});

test('changed legacy budget evidence invalidates snapshot without recovering float precision', function (): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    $context['budget']->update(['allocated_amount' => '1']);
    expect(fn () => app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot))->toThrow(ValidationException::class);
});

test('expired evidence and corrupted snapshot fail closed', function (string $case): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    if ($case === 'expired') {
        travel(2)->hours();
    } else {
        DB::table((new BudgetSnapshot)->getTable())->where('id', $snapshot->id)->update(['snapshot' => 'null']);
    }
    expect(fn () => app(DepartmentBudgetPlanner::class)->handle($context['actor'], $snapshot))->toThrow(ValidationException::class);
})->with(['expired', 'corrupt']);

test('authenticated budget input and planning HTTP endpoints reject spoofed and forecast authority', function (): void {
    $context = budgetPlanContext();
    $data = budgetPlanInput($context['bills']) + ['budget_id' => $context['budget']->id];
    postJson(route('finance.budget-snapshots.store'), $data)->assertUnauthorized();
    /** @var User $ordinary */
    $ordinary = User::factory()->create();
    actingAs($ordinary);
    postJson(route('finance.budget-snapshots.store'), $data)->assertForbidden();
    actingAs($context['actor']);
    postJson(route('finance.budget-snapshots.store'), $data + ['prepared_by' => $context['reviewer']->id])->assertUnprocessable();
    postJson(route('finance.budget-snapshots.store'), $data + ['forecast_revenue' => '1000'])->assertUnprocessable();
    postJson(route('finance.budget-snapshots.store'), $data)->assertSuccessful()->assertJsonPath('data.can_execute', false);
    /** @var BudgetSnapshot $snapshot */
    $snapshot = BudgetSnapshot::query()->sole();
    postJson(route('finance.budget-snapshots.plan', $snapshot))->assertSuccessful()->assertJsonPath('data.bills.1.decision', 'hold');
    expect(Activity::query()->where('event', 'department_budget_planned')->count())->toBe(1)
        ->and(Gate::forUser($context['reviewer'])->allows('execute', $snapshot))->toBeFalse();
});

test('budget evidence immutable model and restrictive keys retain historical context', function (string $case): void {
    $context = budgetPlanContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    if ($case === 'update' || $case === 'delete') {
        expect(fn () => $case === 'update' ? $snapshot->update(['currency' => 'USD']) : $snapshot->delete())->toThrow(LogicException::class);
    } else {
        expect(fn () => $context[$case]->delete())->toThrow(QueryException::class);
    }
})->with(['update', 'delete', 'budget', 'actor', 'institution']);

test('budget evidence migration rolls back only empty tables', function (): void {
    $migration = require database_path('migrations/2026_10_08_013103_create_budget_snapshots_table.php');
    $migration->down();
    expect(Schema::hasTable('budget_snapshots'))->toBeFalse();
    $migration->up();
    $context = budgetPlanContext();
    app(CaptureBudgetSnapshot::class)->handle($context['actor'], $context['budget'], budgetPlanInput($context['bills']));
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});
