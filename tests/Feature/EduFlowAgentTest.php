<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Agents\EduFlowAgent;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Enums\TransactionType;
use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\CircleWalletService;
use Illuminate\Support\Facades\Http;
use Mockery\Expectation;
use Mockery\MockInterface;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);
    Http::preventStrayRequests();
    $gateway = new FakeLeptonGateway(treasuryAddress: '0xagentloopwallet', chainCode: 'ARC-TESTNET');
    app()->instance(WalletGateway::class, $gateway);
    app()->instance(ArcNetworkGateway::class, $gateway);
    $this->agent = app(EduFlowAgent::class);

    $this->org = Organization::create([
        'name' => 'Agent Loop Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xagentloopwallet',
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $this->techBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Tech',
        'category' => 'tech',
        'allocated_amount' => 4000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 4000.00,
        'status' => 'active',
    ]);

    $this->aidBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Student Assistance Fund',
        'category' => 'assistance',
        'allocated_amount' => 2000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 2000.00,
        'status' => 'active',
    ]);

    $this->cloudVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'AWS Cloud',
        'wallet_address' => '0xawscloud',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $this->equipmentVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Lab Equipment',
        'wallet_address' => '0xlabvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
});

test('autonomous cycle executes auto-pay, escalations, reserve protection, and student aid', function (): void {
    // 1. Invoice 450 USDC -> Should auto pay
    $inv1 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->cloudVendor->id,
        'budget_id' => $this->techBudget->id,
        'reference' => 'INV-LOOP-450',
        'amount' => 450.00,
        'due_date' => now()->addDays(1),
        'status' => 'pending',
    ]);

    // 2. Invoice 2500 USDC -> Exceeds auto limit -> Should escalate
    $inv2 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-LOOP-2500',
        'amount' => 2500.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    // 3. Invoice 18000 USDC -> Would breach reserve (25,420 - 450 - 18,000 < 10,000) -> Should hold
    $inv3 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-LOOP-18000',
        'amount' => 18000.00,
        'due_date' => now()->addDays(3),
        'status' => 'pending',
    ]);

    // 4. Student assistance request (eligible: enrolled, qualified, 95% attendance)
    $eligibleStudent = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $eligibleStudent->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $eligibleStudent->id,
        'user_id' => $eligibleStudent->user_id,
        'requested_amount' => 100_000000,
        'status' => AssistanceStatus::PENDING,
        'subject' => 'Emergency book grant',
    ]);

    $events = [];
    $cycleResult = $this->agent->runAutonomousCycle($this->org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect(array_column($events, 'title'))->toBe([
        'Starting liquidity forecast',
        'Liquidity forecast available',
        'Pending invoices observed',
        'Invoice policy result recorded',
        'Submitting payment',
        'Payment response recorded',
        'Invoice outcome recorded',
        'Invoice policy result recorded',
        'Invoice outcome recorded',
        'Invoice policy result recorded',
        'Invoice outcome recorded',
        'Pending assistance observed',
        'Checking assistance policies',
        'Assistance checks recorded',
        'Assistance policy result recorded',
        'Submitting payment',
        'Payment response recorded',
        'Assistance outcome recorded',
    ])->and($events[2]['summary'])->toBe('Found 3 pending invoices for policy evaluation.')
        ->and($events[11]['summary'])->toBe('Found 1 pending assistance requests for policy evaluation.')
        ->and($events[1]['summary'])->toContain((string) $cycleResult['forecast']['projected_balance'], $cycleResult['forecast']['health_status']);

    foreach ($events as $event) {
        expect($event)->toHaveKeys(['phase', 'title', 'summary'])
            ->and(array_diff(array_keys($event), ['phase', 'title', 'summary', 'decision_id', 'policy', 'status', 'reference']))->toBe([])
            ->and($event['phase'])->toBeString()
            ->and($event['title'])->toBeString()
            ->and($event['summary'])->toBeString();

        if (isset($event['decision_id'])) {
            $recordedDecision = AgentDecision::query()->findOrFail($event['decision_id']);
            expect($event['decision_id'])->toBeInt()
                ->and($event['policy'])->toBe($recordedDecision->policy_checked)
                ->and($event['reference'])->toBeString()
                ->and($event['status'])->toBeString();

            if (in_array($event['title'], ['Invoice policy result recorded', 'Assistance policy result recorded'], true)) {
                expect($event['summary'])->toBe($recordedDecision->reasoning_summary)
                    ->and($event['status'])->toBe($recordedDecision->decision->value);
            }
        }
    }

    $responses = array_values(array_filter($events, static fn (array $event): bool => $event['title'] === 'Payment response recorded'));
    expect($responses)->toHaveCount(2);
    foreach ($responses as $response) {
        expect($response['summary'])->toBe('Fake-driver simulation response recorded. No live transfer occurred; this is not chain-verified settlement.')
            ->and($response['status'])->toBe('response_recorded');
    }

    expect($events[6])->toMatchArray(['reference' => $inv1->reference, 'status' => 'auto_paid', 'phase' => 'outcome'])
        ->and($events[8])->toMatchArray(['reference' => $inv2->reference, 'status' => 'escalated'])
        ->and($events[10])->toMatchArray(['reference' => $inv3->reference, 'status' => 'held'])
        ->and($events[13]['summary'])->toBe('enrolled: passed; academic_qualified: passed; attendance_ok: passed; has_outstanding_tuition: passed; within_semester_cap: passed; fund_affordable: passed; reserve_protected: passed; within_daily_budget: passed; within_auto_limit: passed.')
        ->and($events[17])->toMatchArray(['reference' => $aid->ticket_number, 'status' => 'resolved', 'phase' => 'outcome'])
        ->and($events[17]['summary'])->toContain('decision status: executed', 'does not verify on-chain settlement');

    // Verify stats
    expect($cycleResult['stats']['auto_paid'])->toBe(2) // 450 invoice + 100 aid
        ->and($cycleResult['stats']['escalated'])->toBe(1) // 2500 equipment invoice
        ->and($cycleResult['stats']['held'])->toBe(1); // 18000 reserve breach invoice

    // Verify Invoice 1 was auto paid
    expect($inv1->fresh()->status)->toBe('auto_paid');
    expect($this->techBudget->fresh()->spent_amount)->toBe(450.00);

    // Verify Invoice 2 was escalated to human approval queue
    expect($inv2->fresh()->status)->toBe('escalated');
    $approval = Approval::where('organization_id', $this->org->id)->first();
    expect($approval)->not->toBeNull()
        ->and($approval->status)->toBe('pending');

    // Verify Invoice 3 was held for reserve safety
    expect($inv3->fresh()->status)->toBe('held');

    // Verify Student Aid was resolved with Arc transaction
    $updatedAid = $aid->fresh();
    expect($updatedAid->status)->toBe(AssistanceStatus::RESOLVED)
        ->and($updatedAid->admin_notes)->toContain('Disbursed 100 USDC on Arc');

    // Verify Wallet balance was reduced accurately (25,420 - 450 - 100 = 24,870)
    expect($this->wallet->fresh()->balance)->toBe(24870.00);
});

test('progress callbacks stay local to each cycle on a reused agent', function (): void {
    $org = Organization::query()->sole();
    $agent = app(EduFlowAgent::class);
    $firstEvents = [];
    $agent->runAutonomousCycle($org, static function (array $event) use (&$firstEvents): void {
        $firstEvents[] = $event;
    });

    $originalEvents = $firstEvents;
    $agent->runAutonomousCycle($org);
    $secondEvents = [];
    $agent->runAutonomousCycle($org, static function (array $event) use (&$secondEvents): void {
        $secondEvents[] = $event;
    });

    expect($firstEvents)->toBe($originalEvents)
        ->and($secondEvents)->toBe($originalEvents)
        ->and(array_column($secondEvents, 'title'))->toBe([
            'Starting liquidity forecast',
            'Liquidity forecast available',
            'Pending invoices observed',
            'Pending assistance observed',
        ]);
});

test('progress reports exact invoice evidence exclusion without executing a payment', function (): void {
    $org = Organization::query()->sole();
    $techBudget = Budget::query()->where('organization_id', $org->id)->where('category', 'tech')->sole();
    $wallet = $org->primaryWallet();
    expect($wallet)->not->toBeNull();
    $vendor = Vendor::query()->where('organization_id', $org->id)->where('name', 'AWS Cloud')->sole();
    $invoice = Invoice::query()->create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'budget_id' => $techBudget->id,
        'reference' => 'INV-EXACT-PROGRESS',
        'amount' => 450.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);
    InvoiceVersion::factory()->forSource($invoice, User::factory()->create())->create();
    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    app()->instance(CircleWalletService::class, $payments);
    $events = [];

    $result = app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect($events[3])->toBe([
        'phase' => 'policy',
        'title' => 'Invoice requires exact evidence',
        'summary' => $result['processed_invoices'][0]['reason'],
        'reference' => $invoice->reference,
        'status' => 'exact_evidence_required',
    ])->and($result['processed_invoices'][0]['decision'])->toBe('exact_evidence_required')
        ->and($invoice->fresh()->status)->toBe('pending')
        ->and(AgentDecision::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($wallet->fresh()->balance)->toBe(25420.00);
});

test('progress records assistance setup escalation without attempting a transfer', function (bool $hasFund, bool $hasPolicy): void {
    $org = Organization::query()->sole();
    if ($hasFund) {
        AssistanceFund::query()->create([
            'organization_id' => $org->id,
            'name' => 'Emergency Assistance Fund',
            'balance_base_units' => 10000_000000,
            'reserve_threshold_base_units' => 5000_000000,
            'daily_budget_base_units' => 1000_000000,
            'status' => 'active',
        ]);
    }

    if ($hasPolicy) {
        AssistancePolicyVersion::query()->create([
            'version' => 'v1',
            'organization_id' => null,
            'auto_limit_base_units' => 100_000000,
            'semester_cap_base_units' => 500_000000,
            'min_attendance_rate' => 85.00,
            'required_enrollment_status' => 'enrolled',
            'required_academic_status' => 'qualified',
            'is_active' => true,
        ]);
    }

    $student = Student::factory()->create();
    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 100_000000,
        'status' => AssistanceStatus::PENDING,
    ]);
    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    app()->instance(CircleWalletService::class, $payments);
    $events = [];

    $result = app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $decision = AgentDecision::query()->where('reference_type', AssistanceRequest::class)->where('reference_id', $aid->id)->sole();
    expect(array_column($events, 'title'))->toBe([
        'Starting liquidity forecast',
        'Liquidity forecast available',
        'Pending invoices observed',
        'Pending assistance observed',
        'Checking assistance policies',
        'Assistance setup escalated',
    ])->and($events[5])->toBe([
        'phase' => 'outcome',
        'title' => 'Assistance setup escalated',
        'summary' => $decision->reasoning_summary,
        'decision_id' => $decision->id,
        'policy' => 'AID_SETUP_REQUIRED_V1',
        'reference' => $aid->ticket_number,
        'status' => 'escalated',
    ])->and($result['stats']['escalated'])->toBe(1)
        ->and(Approval::query()->where('agent_decision_id', $decision->id)->sole()->status)->toBe('pending')
        ->and($aid->fresh()->admin_notes)->toContain('Escalated for setup')
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'fund missing' => [false, true],
    'policy missing' => [true, false],
    'fund and policy missing' => [false, false],
]);

test('payment response progress distinguishes simulations from unverified provider receipts', function (?bool $isFake): void {
    $org = Organization::query()->sole();
    $vendor = Vendor::query()->where('organization_id', $org->id)->where('name', 'AWS Cloud')->sole();
    $invoice = Invoice::query()->create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-RESPONSE-PROGRESS',
        'amount' => 450.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);
    /** @var list<array<string, mixed>> $events */
    $events = [];
    /** @var MockInterface&CircleWalletService $payments */
    $payments = Mockery::mock(CircleWalletService::class);
    /** @var Expectation $paymentExpectation */
    $paymentExpectation = $payments->shouldReceive('executePayment');
    $paymentExpectation->once()->andReturnUsing(function () use (&$events, $invoice, $isFake): Transaction {
        $event = $events !== [] ? $events[array_key_last($events)] : [];
        expect($event)->toMatchArray([
            'title' => 'Submitting payment',
            'reference' => $invoice->reference,
            'status' => 'submitting',
        ])->and($invoice->fresh()->status)->toBe('pending');

        return new Transaction([
            'provider_tx_hash' => '0x'.str_repeat('a', 64),
            'metadata' => [
                'is_fake' => $isFake,
                'provider_trace' => 'sk-private-response --rpc-url=https://private.example/?token=rpc-secret',
            ],
        ]);
    });
    app()->instance(CircleWalletService::class, $payments);
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldNotReceive('rpc');
    app()->instance(ArcNetworkGateway::class, $arc);

    app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $invoiceEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $invoice->reference));
    expect(array_column($invoiceEvents, 'title'))->toBe([
        'Invoice policy result recorded',
        'Submitting payment',
        'Payment response recorded',
        'Invoice outcome recorded',
    ])->and($invoiceEvents[2]['summary'])->toBe(match ($isFake) {
        true => 'Fake-driver simulation response recorded. No live transfer occurred; this is not chain-verified settlement.',
        false => 'Unverified live provider response recorded. On-chain settlement has not been verified.',
        default => 'Provider response recorded without simulation provenance. On-chain settlement has not been verified.',
    })
        ->and($invoiceEvents[3]['summary'])->toContain('Local invoice status: auto_paid', 'does not verify on-chain settlement')
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('sk-private-response', 'rpc-secret', 'provider_trace', '--rpc-url');
})->with([
    'fake receipt' => [true],
    'live provider receipt' => [false],
    'receipt without provenance' => [null],
]);

test('progress preserves earlier outcomes when a later payment throws with an unknown outcome', function (string $failedType, bool $receiptRecorded): void {
    $org = Organization::query()->sole();
    $vendor = Vendor::query()->where('organization_id', $org->id)->where('name', 'AWS Cloud')->sole();
    $techBudget = Budget::query()->where('organization_id', $org->id)->where('category', 'tech')->sole();
    $wallet = $org->primaryWallet();
    expect($wallet)->not->toBeNull();
    $firstInvoice = Invoice::query()->create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'budget_id' => $techBudget->id,
        'reference' => 'INV-FIRST-PROGRESS',
        'amount' => 450.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);

    if ($failedType === Invoice::class) {
        $failedRequest = Invoice::query()->create([
            'organization_id' => $org->id,
            'vendor_id' => $vendor->id,
            'reference' => 'INV-FAILED-PROGRESS',
            'amount' => 100.00,
            'due_date' => now()->addDays(2),
            'status' => 'pending',
        ]);
        $failedReference = $failedRequest->reference;
    } else {
        $student = Student::factory()->create([
            'enrollment_status' => 'enrolled',
            'academic_status' => 'qualified',
            'attendance_rate' => 95.00,
        ]);
        TuitionAccount::factory()->create([
            'student_id' => $student->id,
            'academic_term_id' => AcademicTerm::factory()->create()->id,
            'total_amount' => 300_000000,
            'paid_amount' => 0,
        ]);
        AssistanceFund::query()->create([
            'organization_id' => $org->id,
            'name' => 'Emergency Assistance Fund',
            'balance_base_units' => 10000_000000,
            'reserve_threshold_base_units' => 5000_000000,
            'daily_budget_base_units' => 1000_000000,
            'status' => 'active',
        ]);
        AssistancePolicyVersion::query()->create([
            'version' => 'v1',
            'organization_id' => null,
            'auto_limit_base_units' => 100_000000,
            'semester_cap_base_units' => 500_000000,
            'min_attendance_rate' => 85.00,
            'required_enrollment_status' => 'enrolled',
            'required_academic_status' => 'qualified',
            'is_active' => true,
        ]);
        $failedRequest = AssistanceRequest::factory()->create([
            'student_id' => $student->id,
            'user_id' => $student->user_id,
            'requested_amount' => 100_000000,
            'status' => AssistanceStatus::PENDING,
        ]);
        $failedReference = $failedRequest->ticket_number;
    }

    /** @var list<array<string, mixed>> $events */
    $events = [];
    $callCount = 0;
    $fakePayments = app(CircleWalletService::class);
    $failure = new RuntimeException('sk-private-payment-failure --rpc-url=https://private.example/?token=rpc-secret');
    /** @var MockInterface&CircleWalletService $payments */
    $payments = Mockery::mock(CircleWalletService::class);
    /** @var Expectation $paymentExpectation */
    $paymentExpectation = $payments->shouldReceive('executePayment');
    $paymentExpectation->twice()->andReturnUsing(function (
        Wallet $wallet,
        string $recipientAddress,
        float $amount,
        TransactionType $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
    ) use (&$events, &$callCount, $firstInvoice, $failedReference, $fakePayments, $receiptRecorded, $failure): Transaction {
        $callCount++;
        $event = $events !== [] ? $events[array_key_last($events)] : [];
        expect($event)->toMatchArray([
            'phase' => 'execute',
            'title' => 'Submitting payment',
            'summary' => "Policy approved {$amount} USDC for submission. Payment outcome is not yet known.",
            'reference' => $callCount === 1 ? $firstInvoice->reference : $failedReference,
            'status' => 'submitting',
        ])->and(AgentDecision::query()->findOrFail($event['decision_id'])->status)->toBe('pending');

        if ($callCount === 2 && ! $receiptRecorded) {
            throw $failure;
        }

        $transaction = $fakePayments->executePayment($wallet, $recipientAddress, $amount, $type, $referenceType, $referenceId, $metadata);
        if ($callCount === 2) {
            throw $failure;
        }

        return $transaction;
    });
    app()->instance(CircleWalletService::class, $payments);
    $agent = app(EduFlowAgent::class);
    $onProgress = static function (array $event) use (&$events): void {
        $events[] = $event;
    };

    expect(fn () => $agent->runAutonomousCycle($org, $onProgress))->toThrow(RuntimeException::class);

    $failedEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $failedReference));
    expect(array_column($failedEvents, 'title'))->toBe($failedType === Invoice::class
        ? ['Invoice policy result recorded', 'Submitting payment']
        : ['Checking assistance policies', 'Assistance checks recorded', 'Assistance policy result recorded', 'Submitting payment'])
        ->and($events[array_key_last($events)]['status'])->toBe('submitting')
        ->and($events[array_key_last($events)]['summary'])->toBe('Policy approved 100 USDC for submission. Payment outcome is not yet known.')
        ->and($firstInvoice->fresh()->status)->toBe('auto_paid')
        ->and($failedRequest->fresh()->getRawOriginal('status'))->toBe('pending')
        ->and(AgentDecision::query()->where('reference_type', $failedType)->where('reference_id', $failedRequest->id)->sole()->status)->toBe('pending')
        ->and($techBudget->fresh()->spent_amount)->toBe(450.00)
        ->and($wallet->fresh()->balance)->toBe($receiptRecorded ? 24870.00 : 24970.00)
        ->and(Transaction::query()->count())->toBe($receiptRecorded ? 2 : 1)
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('sk-private-payment-failure', 'rpc-secret', '--rpc-url', 'rollback', 'rolled back');

    $firstEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $firstInvoice->reference));
    expect(array_column($firstEvents, 'title'))->toBe([
        'Invoice policy result recorded',
        'Submitting payment',
        'Payment response recorded',
        'Invoice outcome recorded',
    ]);
})->with([
    'invoice provider throws before receipt' => [Invoice::class, false],
    'assistance provider throws before receipt' => [AssistanceRequest::class, false],
    'invoice provider throws after local receipt' => [Invoice::class, true],
    'assistance provider throws after local receipt' => [AssistanceRequest::class, true],
]);

test('finance officer can approve escalated transaction', function (): void {
    $inv = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-ESCALATE-TEST',
        'amount' => 2500.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    // Trigger cycle to escalate
    $this->agent->runAutonomousCycle($this->org);

    $approval = Approval::first();
    expect($approval)->not->toBeNull();

    $financeOfficer = User::factory()->create(['name' => 'Finance Director Jane']);

    // Human approves the escalated transaction!
    $approved = $this->agent->approveEscalation($approval, $financeOfficer, 'Approved after verifying departmental budget.');

    expect($approved)->toBeTrue()
        ->and($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->approver_id)->toBe($financeOfficer->id)
        ->and($inv->fresh()->status)->toBe('paid');
});

test('autonomous cycle splits 150 USDC aid into 100 auto plus 50 escalated', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
        'subject' => 'Emergency assistance 150',
    ]);

    $events = [];
    $result = $this->agent->runAutonomousCycle($this->org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $aidEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $aid->ticket_number));
    expect(array_column($aidEvents, 'title'))->toBe([
        'Checking assistance policies',
        'Assistance checks recorded',
        'Assistance policy result recorded',
        'Submitting payment',
        'Payment response recorded',
        'Assistance outcome recorded',
    ])->and($aidEvents[1]['summary'])->toContain('within_auto_limit: failed')
        ->and($aidEvents[2]['status'])->toBe('partial_approval')
        ->and($aidEvents[2]['policy'])->toBe('BOUNDED_EMERGENCY_AID_V1')
        ->and($aidEvents[2]['summary'])->toBe('100 USDC approved automatically. Remaining 50 USDC escalated for review.')
        ->and($aidEvents[3]['summary'])->toBe('Policy approved 100 USDC for submission. Payment outcome is not yet known.')
        ->and($aidEvents[5]['status'])->toBe('in_progress')
        ->and($aidEvents[5]['summary'])->toContain('decision status: escalated', 'does not verify on-chain settlement');

    $updated = $aid->fresh();

    expect($updated->status instanceof AssistanceStatus ? $updated->status->value : $updated->status)->toBe(AssistanceStatus::IN_PROGRESS->value)
        ->and($result['stats']['auto_paid'])->toBe(1)
        ->and($result['stats']['escalated'])->toBe(1)
        ->and($this->wallet->fresh()->balance)->toBe(25320.00)
        ->and($fund->fresh()->balance_base_units)->toBe(9900_000000);

    $decision = AgentDecision::where('reference_id', $aid->id)
        ->where('reference_type', AssistanceRequest::class)
        ->first();

    expect($decision)->not->toBeNull()
        ->and($decision->decision)->toBe(AgentDecisionType::PARTIAL_APPROVAL)
        ->and($decision->input_snapshot['locked_quote']['quote'])->toBe('PHP');

    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull()->and($approval->status)->toBe('pending');

    $officer = User::factory()->create(['name' => 'Finance Officer']);
    app(ApproveEscalatedRequest::class)->handle($aid, $decision, $officer, $fund, 'Remainder approved.');

    expect($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::RESOLVED->value)
        ->and($this->wallet->fresh()->balance)->toBe(25270.00)
        ->and($approval->fresh()->status)->toBe('approved');
});

/**
 * The agent used to build a recipient from a hash of the user id
 * ("0xstudent_<md5>"), which the Circle CLI rejects as an invalid destination.
 * A fabricated address is worse than a rejected one: if it were ever accepted,
 * the money would be unrecoverable. So the agent must never invent a payout
 * target, and must escalate instead.
 */
test('a student with no payout address is escalated, never paid a fabricated address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 100_000000,
        'status' => AssistanceStatus::SUBMITTED,
        'subject' => 'Emergency assistance without a payout address',
    ]);

    $events = [];
    $result = $this->agent->runAutonomousCycle($this->org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $aidEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $aid->ticket_number));
    expect(array_column($aidEvents, 'title'))->toBe([
        'Checking assistance policies',
        'Assistance checks recorded',
        'Assistance policy result recorded',
        'Assistance payout address escalated',
    ])->and($aidEvents[3])->toMatchArray([
        'phase' => 'outcome',
        'policy' => 'STUDENT_PAYOUT_ADDRESS_MISSING_V1',
        'status' => 'escalated',
    ])->and($aidEvents[3]['summary'])->toContain('no valid payout address');

    $decision = AgentDecision::where('reference_id', $aid->id)
        ->where('reference_type', AssistanceRequest::class)
        ->firstOrFail();

    // Escalated with a distinct policy code so the cause is legible in the UI.
    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->policy_checked)->toBe('STUDENT_PAYOUT_ADDRESS_MISSING_V1')
        ->and((float) $decision->approved_amount)->toBe(0.0)
        ->and($decision->requires_approval)->toBeTrue();

    // Nothing moved, and nothing was invented.
    expect(Transaction::where('reference_type', AssistanceRequest::class)->where('reference_id', $aid->id)->count())->toBe(0)
        ->and($this->wallet->fresh()->balance)->toBe(25420.00)
        ->and($fund->fresh()->balance_base_units)->toBe(10000_000000)
        ->and($result['stats']['escalated'])->toBe(1)
        ->and($aid->fresh()->admin_notes)->toContain('no valid payout address');
});

test('a malformed payout address is treated as missing', function (): void {
    $student = Student::factory()->withInvalidPayoutAddress()->create();

    expect($student->payout_address)->toBe('0xnot-a-real-address')
        ->and($student->hasValidPayoutAddress())->toBeFalse();
});

test('a well-formed payout address is accepted in any casing', function (string $address): void {
    $student = Student::factory()->create(['payout_address' => $address]);

    expect($student->hasValidPayoutAddress())->toBeTrue();
})->with([
    '0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182',
    '0x4B1C0D2E3F4A5B6C7D8E9F0A1B2C3D4E5F607182',
    '0x4b1C0D2e3F4a5B6C7d8E9f0A1b2C3d4E5F607182',
]);

test('addresses that are not 40 hex digits are rejected', function (?string $address): void {
    $student = Student::factory()->create(['payout_address' => $address]);

    expect($student->hasValidPayoutAddress())->toBeFalse();
})->with([
    'too short' => ['0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f60718'],
    'too long' => ['0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6071822'],
    'no prefix' => ['4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182'],
    'non hex' => ['0xzzzz0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182'],
    'the old fabricated form' => ['0xstudent_eccbc87e4b5ce2fe'],
    'null' => [null],
]);

test('finance officer can approve student assistance escalation via agent', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
    ]);

    $this->agent->runAutonomousCycle($this->org);

    $decision = AgentDecision::where('reference_id', $aid->id)->first();
    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull();

    $officer = User::factory()->create(['name' => 'Finance Director']);
    $success = $this->agent->approveEscalation($approval, $officer, 'Approved remaining 50 USDC');

    expect($success)->toBeTrue()
        ->and($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::RESOLVED->value)
        ->and($approval->fresh()->status)->toBe('approved')
        ->and($this->wallet->fresh()->balance)->toBe(25270.00);
});

test('finance officer can reject student assistance escalation via agent', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
    ]);

    $this->agent->runAutonomousCycle($this->org);

    $decision = AgentDecision::where('reference_id', $aid->id)->first();
    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull();

    $officer = User::factory()->create(['name' => 'Finance Director']);
    $success = $this->agent->rejectEscalation($approval, $officer, 'Budget allocation exhausted for this category');

    expect($success)->toBeTrue()
        ->and($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::CLOSED->value)
        ->and($approval->fresh()->status)->toBe('rejected')
        ->and($decision->fresh()->status)->toBe('rejected')
        ->and($this->wallet->fresh()->balance)->toBe(25320.00); // 100 auto-paid stands, no additional 50 moved
});
