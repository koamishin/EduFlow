<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\ApproveFundingWindow;
use App\Actions\ApproveVendorDestination;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareFundingWindow;
use App\Actions\PrepareRecurringMandate;
use App\Actions\PrepareVendorDestination;
use App\Actions\ReviewInvoiceVersion;
use App\Actions\ReviewRecurringMandate;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyVersion;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\RecurringMandate;
use App\Models\RecurringMandateReview;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationVersion;
use App\Models\Wallet;
use App\Services\MandateReleaseEvaluator;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

if (! function_exists('mandateContext')) {
    /** @return array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, destination: VendorDestinationVersion, policy: FinancePolicyVersion} */
    function mandateContext(string $ceiling = '5000000'): array
    {
        config(['eduflow.institution_id' => null, 'eduflow.mandate.runtime_enabled' => false,
            'eduflow.mandate.allow_nonzero_allowance' => true,
            'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET', 'lepton.arc.chain_id' => 5042002,
            'lepton.arc.treasury' => null]);

        /** @var Organization $institution */
        $institution = Organization::factory()->create();
        /** @var User $actor */
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
        /** @var User $reviewer */
        $reviewer = User::factory()->create();
        $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

        /** @var User $policymaker */
        $policymaker = User::factory()->create();
        $policymaker->assignRole(Role::findOrCreate('finance_officer', 'web'));
        $policy = app(CreateFinancePolicyVersion::class)->handle($policymaker, 'v1',
            Money::fromDecimal('10', CurrencyCode::USDC), new Money(0, CurrencyCode::USDC),
            new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
        app(ActivateFinancePolicy::class)->handle($reviewer, $policy, null);

        $wallet = Wallet::query()->create(['organization_id' => $institution->id, 'provider' => 'circle',
            'network' => 'arc-testnet', 'address' => '0x'.str_repeat('1', 40), 'balance' => '999999', 'status' => 'active']);
        $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Mandate IT Supplier',
            'status' => 'verified', 'risk_level' => 'low', 'wallet_address' => '0x'.str_repeat('2', 40)]);

        $version = app(PrepareVendorDestination::class)->handle($actor, $vendor, 'v1', $vendor->wallet_address,
            'ARC-TESTNET', 'synthetic-control-check');
        app(ApproveVendorDestination::class)->handle($reviewer, $version, $version->content_digest, null, 'synthetic-independent-check');

        $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'IT department',
            'category' => 'software', 'allocated_amount' => '999999', 'spent_amount' => '0',
            'remaining_amount' => '999999', 'status' => 'active']);

        return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'budget' => $budget,
            'wallet' => $wallet, 'vendor' => $vendor, 'destination' => $version, 'policy' => $policy];
    }
}

if (! function_exists('mandateInput')) {
    /**
     * @param  array<string, mixed>  $c
     * @return array{
     *     budget_id: int, vendor_id: int, vendor_destination_version_id: int, wallet_id: int,
     *     finance_policy_version_id: int, obligation_reference: string, obligation_digest: string,
     *     starts_at: string, ends_at: string, due_window_days: int,
     *     per_occurrence_ceiling_base_units: int|string, fee_ceiling_base_units: int|string,
     *     daily_limit_base_units: int|string, period_limit_base_units: int|string, reason: string
     * }
     */
    function mandateInput(array $c, string $ceiling = '5000000'): array
    {
        return [
            'budget_id' => $c['budget']->id,
            'vendor_id' => $c['vendor']->id,
            'vendor_destination_version_id' => $c['destination']->id,
            'wallet_id' => $c['wallet']->id,
            'finance_policy_version_id' => $c['policy']->id,
            'obligation_reference' => 'CONTRACT-2026-HOSTING',
            'obligation_digest' => hash('sha256', 'synthetic-approved-contract'),
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
            'due_window_days' => 7,
            'per_occurrence_ceiling_base_units' => $ceiling,
            'fee_ceiling_base_units' => '1000000',
            'daily_limit_base_units' => '10000000',
            'period_limit_base_units' => '50000000',
            'reason' => 'Approved hosting contract, billed monthly.',
        ];
    }
}

if (! function_exists('prepareMandate')) {
    function prepareMandate(array $c, string $ceiling = '5000000'): RecurringMandate
    {
        return app(PrepareRecurringMandate::class)->handle($c['actor'], mandateInput($c, $ceiling), (string) Str::uuid());
    }
}

if (! function_exists('approveMandate')) {
    function approveMandate(array $c, RecurringMandate $mandate): RecurringMandateReview
    {
        return app(ReviewRecurringMandate::class)->handle($c['reviewer'], $mandate, $mandate->snapshot_digest,
            'approve_mandate', 'Contract, allocation and caps independently reviewed.');
    }
}

if (! function_exists('releasingFacts')) {
    /**
     * Facts as the scheduler would read them, with every gate open.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    function releasingFacts(array $c, RecurringMandate $mandate, array $overrides = []): array
    {
        return $overrides + [
            'at' => CarbonImmutable::now(),
            'occurrence_key' => '2026-10',
            'occurrence_digest' => app(MandateReleaseEvaluator::class)->occurrenceDigest($mandate, '2026-10'),
            'bill_amount_base_units' => BigInteger::of(4_000000),
            'max_fee_base_units' => BigInteger::of(500000),

            'destination_digest' => $c['destination']->content_digest,
            'policy_digest' => $c['policy']->content_digest,
            'available_cash_base_units' => BigInteger::of(100_000000),
            'consuming_holds_base_units' => BigInteger::zero(),
            'daily_released_base_units' => BigInteger::zero(),
            'period_released_base_units' => BigInteger::zero(),
            'is_fake' => false,
            'stop_switch' => false,
            'funding_window_valid' => true,
        ];
    }
}

if (! function_exists('scannerBills')) {
    /**
     * @param  array<string, mixed>  $c
     */
    function scannerBills(array $c, string $amount = '4.000000', int $dueInDays = 0): int
    {
        $invoice = Invoice::query()->create([
            'organization_id' => $c['institution']->id,
            'vendor_id' => $c['vendor']->id,
            'budget_id' => $c['budget']->id,
            'reference' => 'MANDATE-'.Str::uuid(),
            'amount' => '999999',
            'due_date' => now()->addDays($dueInDays),
            'category' => 'software',
            'status' => 'pending',
        ]);

        $bill = app(CaptureInvoiceVersion::class)->handle($c['actor'], $invoice, [
            'capture_key' => (string) Str::uuid(),
            'source_amount' => $amount,
            'source_currency' => 'USDC',
            'source_evidence' => 'synthetic-mandate-bill',
            'business_approval_reference' => 'synthetic-contract-period',
            'department' => 'IT department',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(14)->toDateString(),
            'source_per_usdc' => '1',
            'rate_source' => 'identity-reference',
            'rate_observed_at' => now()->subMinute()->toIso8601String(),
            'rounding' => 'down',
        ]);

        app(ReviewInvoiceVersion::class)->handle($c['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Synthetic source checked.');

        return $bill->id;
    }
}

if (! function_exists('scannerBalance')) {
    function scannerBalance(string $native = '100000000000000000007'): void
    {
        $arc = Mockery::mock(ArcNetworkGateway::class);

        if (! $arc instanceof ArcNetworkGateway) {
            throw new RuntimeException('Mockery did not build an Arc gateway.');
        }

        $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
        $arc->shouldReceive('chainId')->andReturn(5042002);
        mockExpectation($arc, 'rpc')->andReturnUsing(fn (string $method): mixed => match ($method) {
            'eth_chainId' => '0x'.BigInteger::of(5042002)->toBase(16),
            'eth_getBalance' => '0x'.BigInteger::of($native)->toBase(16),
            'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64),
                'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16)],
            default => throw new RuntimeException('Unexpected Arc read: '.$method),
        });
        app()->instance(ArcNetworkGateway::class, $arc);
    }
}

if (! function_exists('scannerLiveMandate')) {
    /** @param  array<string, mixed>  $c */
    function scannerLiveMandate(array $c, RecurringMandate $mandate): RecurringMandate
    {
        $snapshot = BudgetSnapshot::query()->where('organization_id', $c['institution']->id)
            ->where('budget_id', $c['budget']->id)->latest('id')->firstOrFail();

        scannerBalance();
        $window = app(PrepareFundingWindow::class)->handle($c['actor'], $snapshot, $c['wallet'],
            (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
        app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic exclusive funds checked.');

        return $mandate->fresh();
    }
}

if (! function_exists('prepareAndApprove')) {
    /**
     * @param  array<string, mixed>  $c
     */
    function prepareAndApprove(array $c, string $ceiling = '5000000', string $daily = '10000000', string $period = '50000000'): RecurringMandate
    {
        $input = mandateInput($c, $ceiling);
        $input['daily_limit_base_units'] = $daily;
        $input['period_limit_base_units'] = $period;

        $mandate = app(PrepareRecurringMandate::class)->handle($c['actor'], $input, (string) Str::uuid());
        approveMandate($c, $mandate);

        return $mandate->fresh();
    }
}

if (! function_exists('captureClosedSet')) {
    /**
     * @param  array<string, mixed>  $c
     * @param  array<int, int>  $billIds
     */
    function captureClosedSet(array $c, array $billIds): void
    {
        app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], [
            'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'IT department',
            'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
            'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
            'bill_ids' => $billIds, 'allocation' => '100', 'already_spent' => '0',
            'other_budget_commitments' => '0', 'opening_funds' => '100', 'realized_receipts' => '0', 'actual_outflows' => '0',
            'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
            'budget_evidence' => 'synthetic-allocation', 'cash_evidence' => 'synthetic-opening',
            'commitment_evidence' => 'synthetic-obligations', 'commitments_exclude_selected_bills' => true,
            'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true, 'collection_review_ids' => [],
        ]);
    }
}

if (! function_exists('approveAndFund')) {
    /**
     * @param  array<string, mixed>  $c
     * @param  array<int, int>  $billIds
     */
    function approveAndFund(array $c, string $ceiling = '5000000', array $billIds = [],
        string $daily = '10000000', string $period = '50000000'): RecurringMandate
    {
        $mandate = prepareAndApprove($c, $ceiling, $daily, $period);

        $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], [
            'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'IT department',
            'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
            'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
            'bill_ids' => $billIds, 'allocation' => '100', 'already_spent' => '0',
            'other_budget_commitments' => '0', 'opening_funds' => '100', 'realized_receipts' => '0', 'actual_outflows' => '0',
            'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
            'budget_evidence' => 'synthetic-allocation', 'cash_evidence' => 'synthetic-opening',
            'commitment_evidence' => 'synthetic-obligations', 'commitments_exclude_selected_bills' => true,
            'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true, 'collection_review_ids' => [],
        ]);

        scannerBalance();
        $window = app(PrepareFundingWindow::class)->handle($c['actor'], $snapshot, $c['wallet'],
            (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
        app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic exclusive funds checked.');

        return $mandate->fresh();
    }
}
