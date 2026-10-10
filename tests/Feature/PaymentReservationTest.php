<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\ApproveFundingWindow;
use App\Actions\ApproveVendorDestination;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareFundingWindow;
use App\Actions\PrepareVendorDestination;
use App\Actions\PrepareVendorPayment;
use App\Actions\ProposePaymentIntentChange;
use App\Actions\ReserveVendorPayment;
use App\Actions\ReviewInvoiceVersion;
use App\Actions\ReviewPaymentIntentChange;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyActivation;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\ArcBalanceObservation;
use Brick\Math\BigInteger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use PHPUnit\Framework\Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/** @return array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} */
function reservationContext(string $allocation = '100', string $opening = '100', string $reserve = '10'): array
{
    config(['eduflow.institution_id' => null, 'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => null]);
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $policy = app(CreateFinancePolicyVersion::class)->handle($actor, 'v1', Money::fromDecimal($reserve, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, null);
    $wallet = Wallet::query()->create(['organization_id' => $institution->id, 'provider' => 'circle', 'network' => 'arc-testnet',
        'address' => '0x'.str_repeat('1', 40), 'balance' => '999999', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'School IT Supplier', 'status' => 'verified',
        'risk_level' => 'low', 'wallet_address' => '0x'.str_repeat('2', 40)]);
    $destination = app(PrepareVendorDestination::class)->handle($actor, $vendor, 'v1', $vendor->wallet_address, 'ARC-TESTNET', 'synthetic-control-check');
    app(ApproveVendorDestination::class)->handle($reviewer, $destination, $destination->content_digest, null, 'synthetic-independent-check');
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'IT department', 'category' => 'software',
        'allocated_amount' => '999999', 'spent_amount' => '0', 'remaining_amount' => '999999', 'status' => 'active']);
    $bills = [];
    $drafts = [];
    foreach (['25.000001', '30.000001'] as $index => $amount) {
        $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
            'reference' => 'NET-'.Str::uuid(), 'amount' => '999999', 'due_date' => now()->addDays($index + 1), 'category' => 'software', 'status' => 'pending']);
        $bill = app(CaptureInvoiceVersion::class)->handle($actor, $invoice, [
            'capture_key' => (string) Str::uuid(), 'source_amount' => $amount, 'source_currency' => 'USDC',
            'source_evidence' => 'synthetic-approved-bill-'.$index, 'business_approval_reference' => 'synthetic-department-approval-'.$index,
            'department' => 'IT department', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
            'source_per_usdc' => '1', 'rate_source' => 'identity-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
        ]);
        app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Synthetic source checked.');
        $bills[] = $bill;
        $drafts[] = app(PrepareVendorPayment::class)->handle($actor, $invoice, $wallet, new Money(2, CurrencyCode::USDC), (string) Str::uuid());
    }
    $data = ['capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'IT department',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => array_map(fn (InvoiceVersion $bill): int => $bill->id, $bills), 'allocation' => $allocation, 'already_spent' => '0',
        'other_budget_commitments' => '0', 'opening_funds' => $opening, 'realized_receipts' => '0', 'actual_outflows' => '0',
        'restricted_cash' => '0', 'protected_reserve' => $reserve, 'other_cash_commitments' => '0',
        'budget_evidence' => 'synthetic-approved-allocation', 'cash_evidence' => 'synthetic-realized-opening-funds',
        'commitment_evidence' => 'synthetic-other-obligations', 'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => []];
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($actor, $budget, $data);
    stubReservationBalance('100000000000000000007');

    return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'budget' => $budget, 'wallet' => $wallet,
        'vendor' => $vendor, 'bills' => $bills, 'drafts' => $drafts, 'snapshot' => $snapshot];
}

function stubReservationBalance(string $native, string $case = 'valid'): void
{
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    /** @var Expectation $rpc */
    $rpc = $arc->shouldReceive('rpc');
    $rpc->andReturnUsing(function (string $method, array $params = []) use ($native, $case): mixed {
        $block = ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64),
            'timestamp' => '0x'.BigInteger::of(now()->timestamp - ($case === 'old-block' ? 300 : 1))->toBase(16)];

        return match ($method) {
            'eth_chainId' => $case === 'wrong-chain' ? '0x1' : '0x'.BigInteger::of(5042002)->toBase(16),
            'eth_getBalance' => $case === 'bad-quantity' ? 100.5 : '0x'.BigInteger::of($native)->toBase(16),
            'eth_getBlockByNumber' => $case === 'missing-block' ? null : ($case === 'changed-block' && ($params[0] ?? null) !== 'latest'
                ? array_replace($block, ['hash' => '0x'.str_repeat('b', 64)]) : $block),
            default => throw new RuntimeException('Unexpected Arc read: '.$method),
        };
    });
    app()->instance(ArcNetworkGateway::class, $arc);
}

/** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} $c */
function prepareReservationWindow(array $c): FundingWindow
{
    return app(PrepareFundingWindow::class)->handle($c['actor'], $c['snapshot'], $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
}

/** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} $c */
function approveReservationWindow(array $c, FundingWindow $window): FundingWindowApproval
{
    return app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic allocation and exclusive treasury checked.');
}

/** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} $c */
function reserveContextBill(array $c, FundingWindowApproval $approval, int $index = 0, ?string $key = null): PaymentReservation
{
    $draft = $c['drafts'][$index];

    return app(ReserveVendorPayment::class)->handle($c['actor'], $draft, $approval, $key ?? (string) Str::uuid(), $draft->snapshot_digest, $approval->approval_digest);
}

test('exact Arc native observation preserves overflow safe units residual block and fake label', function (): void {
    $c = reservationContext();
    $observation = app(ArcBalanceObservation::class)->capture($c['wallet']);
    expect($observation['native_units'])->toBe('100000000000000000007')->and($observation['usdc_base_units'])->toBe('100000000')
        ->and($observation['native_residual_units'])->toBe('7')->and($observation['block_number'])->toBe('100')->and($observation['is_fake'])->toBeFalse();
    config(['lepton.default' => 'fake']);
    expect(app(ArcBalanceObservation::class)->capture($c['wallet'])['is_fake'])->toBeTrue();
});

test('invalid chain block or balance observation never creates funding evidence', function (string $case): void {
    $c = reservationContext();
    stubReservationBalance('100000000000000000000', $case);
    expect(fn (): FundingWindow => prepareReservationWindow($c))->toThrow(ValidationException::class)
        ->and(FundingWindow::query()->count())->toBe(0);
})->with(['wrong-chain', 'old-block', 'missing-block', 'changed-block', 'bad-quantity']);

test('independent funding approval and atomic reservation preserve exact bill fee and immutable source accounts', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    expect($window->hasValidSnapshot())->toBeTrue()->and($window->snapshot['capacity'])->toBe([
        'budget_base_units' => '100000000', 'cash_base_units' => '90000000', 'protected_base_units' => '10000000']);
    $approval = approveReservationWindow($c, $window);
    $key = (string) Str::uuid();
    $hold = reserveContextBill($c, $approval, key: $key);
    $repeat = reserveContextBill($c, $approval, key: strtoupper($key));
    expect($hold->amount_base_units)->toBe(25_000001)->and($hold->max_fee_base_units)->toBe(2)->and($repeat->id)->toBe($hold->id)
        ->and($hold->snapshot['total_base_units'])->toBe('25000003')->and($hold->snapshot['remaining_budget_base_units'])->toBe('74999997')
        ->and($hold->snapshot['remaining_cash_base_units'])->toBe('64999997')->and($hold->evidence()['funds_reserved'])->toBeTrue()
        ->and($hold->evidence()['can_execute'])->toBeFalse()->and($hold->evidence()['external_funds_locked'])->toBeFalse()
        ->and($c['wallet']->fresh()->balance)->toBe(999999.0)->and($c['budget']->fresh()->remaining_amount)->toBe(999999.0)
        ->and($c['drafts'][0]->fresh()->status)->toBe('draft')->and($c['drafts'][0]->evidence()['funds_reserved'])->toBeTrue()
        ->and($c['drafts'][0]->evidence()['external_funds_locked'])->toBeFalse()->and(Student::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentReservation::query()->count())->toBe(1)->and(Activity::query()->where('event', 'vendor_payment_reserved')->count())->toBe(1)
        ->and(Gate::forUser($c['reviewer'])->allows('execute', $c['drafts'][0]))->toBeFalse();
});

test('cumulative reservations include fees and protect both allocation and actual Arc cash', function (string $case): void {
    $c = reservationContext($case === 'budget' ? '55.000005' : '100', '100');
    if ($case === 'cash') {
        stubReservationBalance('65000005000000000000');
    }
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    reserveContextBill($c, $approval);
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, 1))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
})->with(['budget', 'cash']);

test('exact cumulative boundary permits both bills once and preserves current cash decrease holds', function (): void {
    $c = reservationContext('55.000006');
    stubReservationBalance('65000006000000000000');
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    reserveContextBill($c, $approval);
    $second = reserveContextBill($c, $approval, 1);
    expect($second->snapshot['remaining_cash_base_units'])->toBe('0')->and($second->snapshot['remaining_budget_base_units'])->toBe('0');
});

test('current Arc cash decline blocks new holds but never frees prior reserved capacity', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    stubReservationBalance('40000000000000000000');
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, 1))->toThrow(ValidationException::class)
        ->and(reserveContextBill($c, $approval, key: $hold->reservation_key)->id)->toBe($hold->id)
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('funding snapshot refresh or another pending window cannot reset approved capacity', function (): void {
    $c = reservationContext();
    $first = prepareReservationWindow($c);
    $second = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $first);
    reserveContextBill($c, $approval);
    expect(fn (): FundingWindow => prepareReservationWindow($c))->toThrow(ValidationException::class)
        ->and(fn (): FundingWindowApproval => approveReservationWindow($c, $second))->toThrow(ValidationException::class)
        ->and(FundingWindowApproval::query()->count())->toBe(1);
});

test('funding makers cannot approve own budget or funding window even with super admin role', function (string $case): void {
    $c = reservationContext();
    $c['actor']->assignRole(Role::findOrCreate('super_admin', 'web'));
    $window = $case === 'budget-maker'
        ? app(PrepareFundingWindow::class)->handle($c['reviewer'], $c['snapshot'], $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->toIso8601String())
        : prepareReservationWindow($c);
    expect(fn () => app(ApproveFundingWindow::class)->handle($c['actor'], $window, $window->snapshot_digest, 'Self review.'))->toThrow(AuthorizationException::class)
        ->and(FundingWindowApproval::query()->count())->toBe(0);
})->with(['budget-maker', 'window-maker']);

test('expiry or budget policy treasury and driver drift blocks new holds without removing old ones', function (string $case): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    reserveContextBill($c, $approval);
    if ($case === 'expiry') {
        travel(11)->minutes();
    } elseif ($case === 'budget') {
        $c['budget']->update(['status' => 'inactive']);
    } elseif ($case === 'treasury') {
        $c['wallet']->update(['address' => '0x'.str_repeat('3', 40)]);
    } elseif ($case === 'driver') {
        config(['lepton.default' => 'fake']);
    } else {
        $policy = app(CreateFinancePolicyVersion::class)->handle($c['actor'], 'v2', new Money(20_000000, CurrencyCode::USDC),
            new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
        app(ActivateFinancePolicy::class)->handle($c['reviewer'], $policy, $window->finance_policy_activation_id);
    }
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, 1))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
})->with(['expiry', 'budget', 'treasury', 'driver', 'policy']);

test('new reservation key or actor cannot bypass existing bill hold', function (string $case): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    if ($case === 'actor') {
        $c['actor'] = $c['reviewer'];
    }
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, key: $case === 'actor' ? $hold->reservation_key : null))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
})->with(['key', 'actor']);

test('reserved draft blocks new recovery and acceptance of previously prepared recovery', function (): void {
    $c = reservationContext();
    $draft = $c['drafts'][0];
    $change = app(ProposePaymentIntentChange::class)->handle($c['actor'], $draft, (string) Str::uuid(), $draft->snapshot_digest, 'cancel', 'Withdraw bill.');
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    reserveContextBill($c, $approval);
    expect(fn () => app(ProposePaymentIntentChange::class)->handle($c['actor'], $draft, (string) Str::uuid(), $draft->snapshot_digest, 'cancel', 'Withdraw bill.'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReviewPaymentIntentChange::class)->handle($c['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent check.'))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('tampered held evidence never becomes free capacity', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    DB::table((new PaymentReservation)->getTable())->where('id', $hold->id)->update(['amount_base_units' => 1]);
    expect($c['drafts'][0]->evidence()['funds_reserved'])->toBeTrue()->and($c['drafts'][0]->evidence()['reservation_valid'])->toBeFalse();
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, 1))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('reservation persistence failure rolls back audit and leaves capacity available for safe retry', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    DB::unprepared("CREATE TRIGGER reject_test_reservation BEFORE INSERT ON payment_reservations
        BEGIN SELECT RAISE(ABORT, 'Injected reservation write failure'); END");
    try {
        expect(fn (): PaymentReservation => reserveContextBill($c, $approval))->toThrow(QueryException::class)
            ->and(PaymentReservation::query()->count())->toBe(0)->and(Activity::query()->where('event', 'vendor_payment_reserved')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER reject_test_reservation');
    }
    expect(reserveContextBill($c, $approval)->id)->toBeGreaterThan(0);
});

test('funding reservation models and foreign keys preserve evidence with monetary database guards', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);
    expect(fn () => $window->delete())->toThrow(LogicException::class)
        ->and(fn () => $approval->delete())->toThrow(LogicException::class)
        ->and(fn () => $hold->update(['amount_base_units' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $hold->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table((new PaymentReservation)->getTable())->where('id', $hold->id)->update(['amount_base_units' => 1.5]))->toThrow(QueryException::class)
        ->and(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $c['drafts'][0]->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table((new FundingWindowApproval)->getTable())->where('id', $approval->id)->delete())->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_10_08_214402_create_funding_windows_and_payment_reservations.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});

test('funding reservation migration permits empty rollback and reinstall', function (): void {
    $migration = require database_path('migrations/2026_10_08_214402_create_funding_windows_and_payment_reservations.php');
    $migration->down();
    $migration->up();
    $c = reservationContext();
    expect(reserveContextBill($c, approveReservationWindow($c, prepareReservationWindow($c)))->id)->toBeGreaterThan(0);
});

test('higher policy reserve protects local realized cash as well as Arc balance', function (): void {
    $c = reservationContext('100', '50', '10');
    $policy = app(CreateFinancePolicyVersion::class)->handle($c['actor'], 'v2', new Money(20_000000, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    $current = FinancePolicyActivation::current($c['institution']->id);
    app(ActivateFinancePolicy::class)->handle($c['reviewer'], $policy, $current->id);
    $window = prepareReservationWindow($c);
    expect($window->snapshot['capacity']['cash_base_units'])->toBe('30000000')
        ->and($window->snapshot['capacity']['protected_base_units'])->toBe('20000000');
});

test('fake funding holds stay explicitly simulated and never enable real settlement', function (): void {
    $c = reservationContext();
    config(['lepton.default' => 'fake']);
    $window = prepareReservationWindow($c);
    $hold = reserveContextBill($c, approveReservationWindow($c, $window));
    expect($window->evidence()['is_fake'])->toBeTrue()->and($hold->evidence()['is_fake'])->toBeTrue()
        ->and($hold->evidence()['can_execute'])->toBeFalse()->and(Transaction::query()->count())->toBe(0);
});

test('funding and approval identities preserve exact retries and refuse changed evidence', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $repeat = app(PrepareFundingWindow::class)->handle($c['actor'], $c['snapshot'], $c['wallet'], strtoupper($window->request_key), $window->snapshot['valid_until']);
    expect($repeat->id)->toBe($window->id)->and(Activity::query()->where('event', 'funding_window_prepared')->count())->toBe(1)
        ->and(fn () => app(PrepareFundingWindow::class)->handle($c['reviewer'], $c['snapshot'], $c['wallet'], $window->request_key, $window->snapshot['valid_until']))->toThrow(ValidationException::class);
    $approval = approveReservationWindow($c, $window);
    expect(approveReservationWindow($c, $window)->id)->toBe($approval->id)
        ->and(Activity::query()->where('event', 'funding_window_approved')->count())->toBe(1)
        ->and(fn () => app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Changed feedback.'))->toThrow(ValidationException::class);
});

test('expected draft funding digests prevent stale or substituted reservation requests', function (string $case): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    expect(fn () => app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, str_repeat('0', 64), 'Wrong evidence.'))->toThrow(ValidationException::class);
    $approval = approveReservationWindow($c, $window);
    expect(fn () => app(ReserveVendorPayment::class)->handle($c['actor'], $c['drafts'][0], $approval, (string) Str::uuid(),
        $case === 'draft' ? str_repeat('0', 64) : $c['drafts'][0]->snapshot_digest,
        $case === 'approval' ? str_repeat('0', 64) : $approval->approval_digest))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(0);
})->with(['draft', 'approval']);

test('funding evidence expiry and capacity require exact current approved source not local reference FX', function (string $case): void {
    $c = reservationContext();
    if ($case === 'duration') {
        expect(fn () => app(PrepareFundingWindow::class)->handle($c['actor'], $c['snapshot'], $c['wallet'], (string) Str::uuid(), now()->addMinutes(16)->toIso8601String()))->toThrow(ValidationException::class);
    } elseif ($case === 'local-currency') {
        $s = $c['snapshot']->snapshot;
        $s['currency'] = 'PHP';
        DB::table((new BudgetSnapshot)->getTable())->where('id', $c['snapshot']->id)->update(['currency' => 'PHP',
            'snapshot' => json_encode($s, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($s)]);
        expect(fn (): FundingWindow => prepareReservationWindow($c))->toThrow(ValidationException::class);
    } else {
        $c['wallet']->update(['network' => 'arc-mainnet']);
        expect(fn (): FundingWindow => prepareReservationWindow($c))->toThrow(ValidationException::class);
    }
    expect(FundingWindow::query()->count())->toBe(0);
})->with(['duration', 'local-currency', 'wrong-wallet-network']);

test('corrupt funding or approval evidence blocks holds while preserving original records', function (string $case): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    if ($case === 'window') {
        $snapshot = $window->snapshot;
        $snapshot['capacity']['cash_base_units'] = '999999999';
        DB::table((new FundingWindow)->getTable())->where('id', $window->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    } else {
        DB::table((new FundingWindowApproval)->getTable())->where('id', $approval->id)->update(['verification_reference' => 'Altered approval.']);
    }
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(0);
})->with(['window', 'approval']);

test('reservation access rejects missing role unverified identity or foreign installation before Arc reads', function (string $case): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    if ($case === 'role') {
        /** @var User $actor */
        $actor = User::factory()->create();
        $c['actor'] = $actor;
    } elseif ($case === 'unverified') {
        $c['actor']->forceFill(['email_verified_at' => null])->save();
    } else {
        /** @var Organization $other */
        $other = Organization::factory()->create();
        config(['eduflow.institution_id' => $other->id]);
    }
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval))->toThrow(AuthorizationException::class)
        ->and(PaymentReservation::query()->count())->toBe(0);
})->with(['role', 'unverified', 'foreign']);

test('missing middle hold is detected by immutable cumulative reservation sequence', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $first = reserveContextBill($c, $approval);
    $second = reserveContextBill($c, $approval, 1);
    DB::table((new PaymentReservation)->getTable())->where('id', $first->id)->delete();
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, 1, $second->reservation_key))->toThrow(ValidationException::class);
});

test('independent SQLite workers cannot reserve the same scarce capacity concurrently', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
        Assert::markTestSkipped('pcntl is required for the independent-worker reservation race.');
    }
    $database = tempnam(sys_get_temp_dir(), 'eduflow-reservation-');
    if ($database === false) {
        throw new RuntimeException('Could not create isolated reservation race database.');
    }
    $originalConnection = DB::getDefaultConnection();
    $connection = 'reservation_race';
    config(['database.connections.'.$connection => array_replace(config('database.connections.sqlite'), [
        'database' => $database, 'url' => null, 'busy_timeout' => 5000,
    ])]);
    DB::setDefaultConnection($connection);
    $children = [];
    try {
        Artisan::call('migrate', ['--database' => $connection, '--path' => 'database/migrations', '--no-interaction' => true]);
        $c = reservationContext('40');
        $approval = approveReservationWindow($c, prepareReservationWindow($c));
        DB::disconnect($connection);
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork reservation race worker.');
            }
            if ($pid === 0) {
                file_put_contents($database.'.ready-'.$index, 'ready');
                $deadline = microtime(true) + 10;
                while ((! file_exists($database.'.ready-0') || ! file_exists($database.'.ready-1')) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    reserveContextBill($c, $approval, $index);
                    $result = 'reserved';
                } catch (ValidationException) {
                    $result = 'held';
                } catch (Throwable $exception) {
                    $result = $exception::class.': '.$exception->getMessage();
                }
                file_put_contents($database.'.result-'.$index, $result);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = [file_get_contents($database.'.result-0'), file_get_contents($database.'.result-1')];
        sort($results);
        expect($results)->toBe(['held', 'reserved'])->and(PaymentReservation::query()->count())->toBe(1)
            ->and(Activity::query()->where('event', 'vendor_payment_reserved')->count())->toBe(1);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        foreach ([$database, $database.'.ready-0', $database.'.ready-1', $database.'.result-0', $database.'.result-1', $database.'-journal'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});

test('recorded funding approval and reservation retries need no Arc availability and never recreate authority', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);
    travel(20)->minutes();
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    expect(app(PrepareFundingWindow::class)->handle($c['actor'], $c['snapshot'], $c['wallet'], $window->request_key, $window->snapshot['valid_until'])->id)->toBe($window->id)
        ->and(approveReservationWindow($c, $window)->id)->toBe($approval->id)
        ->and(reserveContextBill($c, $approval, key: $hold->reservation_key)->id)->toBe($hold->id)
        ->and(PaymentReservation::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0);
});

test('reservation identity cannot move to another bill within approved window', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $first = reserveContextBill($c, $approval);
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval, 1, $first->reservation_key))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('funding approval does not let unbound bill or different treasury consume capacity', function (string $case): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    if ($case === 'unbound-bill') {
        $invoice = Invoice::query()->create(['organization_id' => $c['institution']->id, 'vendor_id' => $c['vendor']->id, 'budget_id' => $c['budget']->id,
            'reference' => 'UNBOUND-'.Str::uuid(), 'amount' => '10', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending']);
        $draft = app(PrepareVendorPayment::class)->handle($c['actor'], $invoice, $c['wallet'], new Money(2, CurrencyCode::USDC), (string) Str::uuid());
    } else {
        $wallet = $c['wallet']->replicate();
        $wallet->address = '0x'.str_repeat('3', 40);
        $wallet->save();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->findOrFail($c['drafts'][0]->invoice_id);
        $change = app(ProposePaymentIntentChange::class)->handle($c['actor'], $c['drafts'][0], (string) Str::uuid(), $c['drafts'][0]->snapshot_digest,
            'replace', 'Different treasury requested.', (string) Str::uuid(), $wallet, new Money(2, CurrencyCode::USDC));
        $review = app(ReviewPaymentIntentChange::class)->handle($c['reviewer'], $change, $change->content_digest, 'approve_change', 'Treasury draft correction checked.');
        /** @var PaymentIntent $draft */
        $draft = PaymentIntent::query()->where('change_review_id', $review->id)->firstOrFail();
    }
    expect(fn () => app(ReserveVendorPayment::class)->handle($c['actor'], $draft, $approval, (string) Str::uuid(), $draft->snapshot_digest, $approval->approval_digest))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(0);
})->with(['unbound-bill', 'different-treasury']);

test('cancelled and superseded drafts cannot consume funding but an exact reviewed successor can', function (string $kind): void {
    $c = reservationContext();
    $draft = $c['drafts'][0];
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $change = app(ProposePaymentIntentChange::class)->handle($c['actor'], $draft, (string) Str::uuid(), $draft->snapshot_digest,
        $kind, 'Reviewed draft correction.', $kind === 'replace' ? (string) Str::uuid() : null,
        $kind === 'replace' ? $c['wallet'] : null, $kind === 'replace' ? new Money(3, CurrencyCode::USDC) : null);
    $review = app(ReviewPaymentIntentChange::class)->handle($c['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent correction check.');
    expect(fn (): PaymentReservation => reserveContextBill($c, $approval))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(0);
    if ($kind === 'replace') {
        /** @var PaymentIntent $successor */
        $successor = PaymentIntent::query()->where('change_review_id', $review->id)->firstOrFail();
        $c['drafts'][0] = $successor;
        expect(reserveContextBill($c, $approval)->max_fee_base_units)->toBe(3);
    }
})->with(['cancel', 'replace']);

test('reservation HTTP replay is throttled without multiplying held capacity', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    actingAs($c['actor']);
    $payload = ['reservation_key' => (string) Str::uuid(), 'funding_window_approval_id' => $approval->id,
        'intent_digest' => $c['drafts'][0]->snapshot_digest, 'approval_digest' => $approval->approval_digest];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        postJson(route('finance.payment-reservations.store', $c['drafts'][0]), $payload)->assertSuccessful();
    }
    postJson(route('finance.payment-reservations.store', $c['drafts'][0]), $payload)->assertTooManyRequests();
    expect(PaymentReservation::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0);
});

test('legacy aggregate receipt snapshots cannot be promoted into new funding authority', function (): void {
    $c = reservationContext();
    $content = $c['snapshot']->snapshot;
    $content['schema_version'] = 1;
    unset($content['collections'], $content['opening_funds_exclude_collections']);
    DB::table((new BudgetSnapshot)->getTable())->where('id', $c['snapshot']->id)->update([
        'snapshot' => json_encode($content, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($content),
    ]);
    expect(fn (): FundingWindow => prepareReservationWindow($c))->toThrow(ValidationException::class)
        ->and(FundingWindow::query()->count())->toBe(0);
});

test('funding and reservation HTTP routes bind authenticated identities reject injected amounts and preserve no execution', function (): void {
    $c = reservationContext();
    $payload = ['request_key' => (string) Str::uuid(), 'budget_snapshot_id' => $c['snapshot']->id,
        'wallet_id' => $c['wallet']->id, 'valid_until' => now()->addMinutes(10)->toIso8601String(), 'exclusive_treasury' => true];
    postJson(route('finance.funding-windows.store'), $payload)->assertUnauthorized();
    actingAs($c['actor']);
    postJson(route('finance.funding-windows.store'), $payload + ['capacity' => ['cash_base_units' => '999999999']])->assertUnprocessable();
    $response = postJson(route('finance.funding-windows.store'), $payload)->assertSuccessful()->assertJsonPath('data.can_execute', false);
    /** @var FundingWindow $window */
    $window = FundingWindow::query()->findOrFail($response->json('data.id'));
    $review = ['expected_digest' => $window->snapshot_digest, 'verification_reference' => 'Synthetic independent evidence check.',
        'allocation_and_exclusions_verified' => true, 'exclusive_treasury_verified' => true];
    postJson(route('finance.funding-windows.approve', $window), $review)->assertForbidden();
    actingAs($c['reviewer']);
    $response = postJson(route('finance.funding-windows.approve', $window), $review)->assertSuccessful()->assertJsonPath('data.payment_approved', false);
    $request = ['reservation_key' => (string) Str::uuid(), 'funding_window_approval_id' => $response->json('data.id'),
        'intent_digest' => $c['drafts'][0]->snapshot_digest, 'approval_digest' => $response->json('data.approval_digest')];
    postJson(route('finance.payment-reservations.store', $c['drafts'][0]), $request + ['amount_base_units' => 1])->assertUnprocessable();
    postJson(route('finance.payment-reservations.store', $c['drafts'][0]), $request)->assertSuccessful()
        ->assertJsonPath('data.funds_reserved', true)->assertJsonPath('data.can_execute', false)->assertJsonPath('data.external_funds_locked', false);
    getJson(route('finance.funding-windows.show', $window))->assertSuccessful();
});
