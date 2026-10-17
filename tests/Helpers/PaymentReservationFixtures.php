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
use App\Actions\ReserveVendorPayment;
use App\Actions\ReviewInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Illuminate\Support\Str;
use Mockery\Expectation;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

if (! function_exists('reservationContext')) {
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
}

if (! function_exists('stubReservationBalance')) {
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
}

if (! function_exists('prepareReservationWindow')) {
    /** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} $c */
    function prepareReservationWindow(array $c): FundingWindow
    {
        return app(PrepareFundingWindow::class)->handle($c['actor'], $c['snapshot'], $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
    }
}

if (! function_exists('approveReservationWindow')) {
    /** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} $c */
    function approveReservationWindow(array $c, FundingWindow $window): FundingWindowApproval
    {
        return app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic allocation and exclusive treasury checked.');
    }
}

if (! function_exists('reserveContextBill')) {
    /** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, bills: list<InvoiceVersion>, drafts: list<PaymentIntent>, snapshot: BudgetSnapshot} $c */
    function reserveContextBill(array $c, FundingWindowApproval $approval, int $index = 0, ?string $key = null): PaymentReservation
    {
        $draft = $c['drafts'][$index];

        return app(ReserveVendorPayment::class)->handle($c['actor'], $draft, $approval, $key ?? (string) Str::uuid(), $draft->snapshot_digest, $approval->approval_digest);
    }
}
