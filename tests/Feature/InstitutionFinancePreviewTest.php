<?php

declare(strict_types=1);

use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\InstitutionFinancePreview;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

use function Pest\Laravel\artisan;

function previewInstitution(): Organization
{
    config(['eduflow.institution_id' => null, 'lepton.arc.treasury' => null, 'lepton.default' => 'fake']);

    /** @var Organization $institution */
    $institution = Organization::factory()->create([
        'minimum_reserve' => '100',
        'max_auto_payment' => '50',
        'max_daily_disbursement' => '200',
    ]);

    return $institution;
}

function previewTreasury(Organization $institution): Wallet
{
    return Wallet::query()->create([
        'organization_id' => $institution->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x'.str_repeat('1', 40),
        'balance' => '500',
        'status' => 'active',
    ]);
}

function previewBill(Organization $institution, string $reference, string $amount = '25'): Invoice
{
    $vendor = Vendor::query()->create([
        'organization_id' => $institution->id,
        'name' => 'Lab Supplier',
        'wallet_address' => '0x'.str_repeat('2', 40),
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    return Invoice::query()->create([
        'organization_id' => $institution->id,
        'vendor_id' => $vendor->id,
        'reference' => $reference,
        'amount' => $amount,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);
}

function previewArc(Wallet $wallet, string $nativeUnits = '500000000000000000000'): FakeLeptonGateway
{
    $arc = new FakeLeptonGateway(treasuryAddress: $wallet->address);
    $arc->stubRpc('eth_getBalance', '0x'.BigInteger::of($nativeUnits)->toBase(16));
    app()->instance(ArcNetworkGateway::class, $arc);
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));

    return $arc;
}

test('institution finance preview works with no students or aid setup and never sends funds', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    $bill = previewBill($institution, 'VENDOR-001');
    previewArc($wallet);

    $report = app(InstitutionFinancePreview::class)->handle();
    $repeat = app(InstitutionFinancePreview::class)->handle();

    expect($repeat['payments_submitted'])->toBe(0)
        ->and($repeat['invoice_reviews'])->toBe($report['invoice_reviews']);

    expect($report['institution_id'])->toBe($institution->id)
        ->and($report['mode'])->toBe('preview_only')
        ->and($report['can_execute'])->toBeFalse()
        ->and($report['funds_reserved'])->toBeFalse()
        ->and($report['payments_submitted'])->toBe(0)
        ->and($report['forecast']['source'])->toBe('legacy_stored_ledger')
        ->and($report['invoice_reviews'][0]['review'])->toBe('auto_approve')
        ->and($report['invoice_reviews'][0]['can_execute'])->toBeFalse()
        ->and($report['treasury']['observation']['state'])->toBe('simulated')
        ->and($report['treasury']['observation']['settlement_verified'])->toBeFalse()
        ->and($bill->fresh()->status)->toBe('pending')
        ->and($wallet->fresh()->balance)->toBe(500.0);

    foreach ([Student::class, TuitionAccount::class, AssistanceFund::class, AssistancePolicyVersion::class, Transaction::class, AgentDecision::class, Approval::class] as $model) {
        expect($model::query()->count())->toBe(0);
    }
});

test('reviews imported operational vendor bills without needing a student assistance request', function (string $reference, string $category, string $amount, string $expectedReview): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    previewArc($wallet);
    $bill = previewBill($institution, $reference, $amount);
    $bill->update(['category' => $category]);

    $report = app(InstitutionFinancePreview::class)->handle();

    expect($report['invoice_reviews'][0]['reference'])->toBe($reference)
        ->and($report['invoice_reviews'][0]['review'])->toBe($expectedReview)
        ->and($report['invoice_reviews'][0]['can_execute'])->toBeFalse()
        ->and($report['payments_submitted'])->toBe(0)
        ->and($bill->fresh()->status)->toBe('pending')
        ->and(Student::query()->count())->toBe(0)
        ->and(AssistanceFund::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'learning-platform renewal' => ['LMS-RENEWAL', 'software', '25', 'auto_approve'],
    'approved laboratory supplies' => ['LAB-SUPPLIES', 'procurement', '40', 'auto_approve'],
    'facilities repair exceeding review threshold' => ['WATER-REPAIR', 'facilities', '60', 'escalate'],
    'event supplier deposit breaching reserve' => ['GRADUATION-DEPOSIT', 'events', '450', 'hold'],
]);

test('native observation preserves balances above signed integer range and sub-micro residuals', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    previewArc($wallet, '20000000000000000001');

    $report = app(InstitutionFinancePreview::class)->handle();
    $observation = $report['treasury']['observation'];

    expect($observation['native_units'])->toBe('20000000000000000001')
        ->and($observation['payment_minor_units'])->toBe('20000000')
        ->and($observation['residual_native_units'])->toBe('1')
        ->and($observation['decimal'])->toBe('20.000000000000000001')
        ->and($observation['ledger_drift_native_units'])->toBe('479999999999999999999');
});

test('empty institution preview returns an audited no-op through the CLI without needing a wallet', function (): void {
    previewInstitution();
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));

    artisan('eduflow:finance-preview', ['--no-interaction' => true])
        ->expectsOutputToContain('"activity": "no_op"')
        ->assertSuccessful();

    /** @var Activity $audit */
    $audit = Activity::query()->where('event', 'finance_previewed')->sole();
    expect($audit->properties['payments_submitted'])->toBe(0)
        ->and($audit->properties['activity'])->toBe('no_op')
        ->and(Transaction::query()->count())->toBe(0);
});

test('preview refuses missing or ambiguous installation identity', function (): void {
    config(['eduflow.institution_id' => null]);
    expect(fn () => app(InstitutionFinancePreview::class)->handle())->toThrow(RuntimeException::class, 'Institution context unavailable');

    $institution = previewInstitution();
    Organization::factory()->create();

    expect(fn () => app(InstitutionFinancePreview::class)->handle($institution))->toThrow(RuntimeException::class, 'Institution context unavailable');
});

test('preview refuses foreign and unpersisted institution context', function (): void {
    $institution = previewInstitution();
    $foreign = clone $institution;
    $foreign->id = $institution->id + 1;

    expect(fn () => app(InstitutionFinancePreview::class)->handle($foreign))->toThrow(RuntimeException::class, 'does not match')
        ->and(fn () => app(InstitutionFinancePreview::class)->handle(new Organization))->toThrow(RuntimeException::class, 'does not match');
});

test('preview blocks invalid beneficiaries, budget violations and reserve breaches without mutating invoices', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    previewArc($wallet);
    $invalid = previewBill($institution, 'INVALID-ADDRESS');
    $invalid->vendor->update(['wallet_address' => '0xnot-valid']);
    $held = previewBill($institution, 'RESERVE-HOLD', '450');
    $escalated = previewBill($institution, 'HIGH-VALUE', '60');
    $rejected = previewBill($institution, 'OVER-BUDGET');
    $budget = Budget::query()->create([
        'organization_id' => $institution->id,
        'name' => 'Lab',
        'category' => 'lab',
        'allocated_amount' => '10',
        'spent_amount' => '0',
        'remaining_amount' => '10',
        'status' => 'active',
    ]);
    $rejected->update(['budget_id' => $budget->id]);

    $reviews = collect(app(InstitutionFinancePreview::class)->handle()['invoice_reviews'])->keyBy('invoice_id');
    expect($reviews[$invalid->id]['review'])->toBe('blocked')
        ->and($reviews[$held->id]['review'])->toBe('hold')
        ->and($reviews[$escalated->id]['review'])->toBe('escalate')
        ->and($reviews[$rejected->id]['review'])->toBe('reject');
    expect(Invoice::query()->where('status', 'pending')->count())->toBe(4)
        ->and($budget->fresh()->remaining_amount)->toBe(10.0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('network mismatch prevents inference of an onchain balance', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    $arc = previewArc($wallet);
    $arc->stubRpc('eth_chainId', '0x1');

    $observation = app(InstitutionFinancePreview::class)->handle()['treasury']['observation'];
    expect($observation['state'])->toBe('unavailable')
        ->and($observation['error'])->toContain('chain identity')
        ->and($observation)->not->toHaveKey('native_units');
});

test('configured treasury mismatch refuses observation instead of adopting another address', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    $arc = new FakeLeptonGateway(treasuryAddress: '0x'.str_repeat('3', 40));
    app()->instance(ArcNetworkGateway::class, $arc);

    $observation = app(InstitutionFinancePreview::class)->handle()['treasury']['observation'];
    expect($observation['state'])->toBe('unavailable')
        ->and($observation['error'])->toContain('does not match')
        ->and($wallet->fresh()->address)->toBe('0x'.str_repeat('1', 40));
});

test('non-USDC legacy payables cannot be interpreted as an executable USDC payment', function (): void {
    $institution = previewInstitution();
    $institution->update(['currency' => 'PHP']);
    $wallet = previewTreasury($institution);
    previewArc($wallet);
    previewBill($institution, 'LOCAL-001');

    $report = app(InstitutionFinancePreview::class)->handle();
    expect($report['forecast'])->toBeNull()
        ->and($report['invoice_reviews'][0]['review'])->toBe('blocked')
        ->and($report['invoice_reviews'][0]['reason'])->toContain('conversion')
        ->and($report['invoice_reviews'][0]['amount']['currency'])->toBe('USDC')
        ->and($report['invoice_reviews'][0]['currency_interpretation'])->toBe('legacy_USDC_unconverted')
        ->and($report['policy']['reporting_currency'])->toBe('PHP')
        ->and($report['policy']['minimum_reserve']['currency'])->toBe('USDC');
});

test('preview refuses cross-institution vendor ownership even if invoice is local', function (): void {
    $institution = previewInstitution();
    /** @var Organization $foreign */
    $foreign = Organization::factory()->create();
    $wallet = previewTreasury($institution);
    previewArc($wallet);
    $bill = previewBill($institution, 'FOREIGN-VENDOR');
    $bill->vendor->update(['organization_id' => $foreign->id]);
    $resolver = Mockery::mock(InstallationInstitution::class);
    $resolver->allows(['require' => $institution]);
    app()->instance(InstallationInstitution::class, $resolver);

    $review = app(InstitutionFinancePreview::class)->handle()['invoice_reviews'][0];
    expect($review['review'])->toBe('blocked')
        ->and($review['reason'])->toContain('another institution');
});

test('preview does not hide missing or malformed Arc observations', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    $arc = previewArc($wallet);
    $arc->stubRpc('eth_getBalance', 'not-a-hex-quantity');

    $observation = app(InstitutionFinancePreview::class)->handle()['treasury']['observation'];
    expect($observation['state'])->toBe('unavailable')
        ->and($observation)->not->toHaveKey('native_units')
        ->and($wallet->fresh()->balance)->toBe(500.0);
});

test('CLI rejects invalid horizon without moving funds or logging a successful preview', function (string $days): void {
    artisan('eduflow:finance-preview', ['--days' => $days, '--no-interaction' => true])
        ->expectsOutputToContain('between 1 and 90 days')
        ->assertFailed();

    expect(Activity::query()->where('event', 'finance_previewed')->count())->toBe(0);
})->with(['0', '91', '3.5', 'invalid']);

test('invoice previews have a bounded batch and report truncation without changing payment state', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    previewArc($wallet);
    $first = previewBill($institution, 'BATCH-0');
    for ($index = 1; $index <= 100; $index++) {
        Invoice::query()->create([
            'organization_id' => $institution->id,
            'vendor_id' => $first->vendor_id,
            'reference' => 'BATCH-'.$index,
            'amount' => '1',
            'due_date' => now()->addDay(),
            'status' => 'pending',
        ]);
    }

    $report = app(InstitutionFinancePreview::class)->handle();
    expect($report['invoice_reviews'])->toHaveCount(100)
        ->and($report['truncated'])->toBeTrue()
        ->and(Invoice::query()->where('status', 'pending')->count())->toBe(101)
        ->and(Transaction::query()->count())->toBe(0);
});

test('legacy float-valued storage is not silently presented as exact money', function (): void {
    $institution = previewInstitution();
    $wallet = previewTreasury($institution);
    previewArc($wallet);
    DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 0.123456]);

    expect(fn () => app(InstitutionFinancePreview::class)->handle())->toThrow(RuntimeException::class, 'exact decimal string or integer');
});
