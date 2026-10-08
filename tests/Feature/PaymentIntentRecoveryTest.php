<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\ApproveVendorDestination;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareVendorDestination;
use App\Actions\PrepareVendorPayment;
use App\Actions\ProposePaymentIntentChange;
use App\Actions\ReviewPaymentIntentChange;
use App\Actions\VerifyVendorPaymentDraft;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Approval;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\PaymentIntentChangeReview;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\PaymentIntentLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** @return array{institution: Organization, actor: User, reviewer: User, invoice: Invoice, wallet: Wallet, vendor: Vendor, budget: Budget, draft: PaymentIntent} */
function recoveryContext(): array
{
    config(['eduflow.institution_id' => null, 'lepton.arc.chain' => 'ARC-TESTNET', 'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => null]);
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
    $wallet = Wallet::query()->create(['organization_id' => $institution->id, 'provider' => 'circle', 'network' => 'arc',
        'address' => '0x'.str_repeat('1', 40), 'balance' => '500', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'School Network Supplier',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);
    $destination = app(PrepareVendorDestination::class)->handle($actor, $vendor, 'v1', $vendor->wallet_address, 'ARC-TESTNET', 'synthetic-control-evidence');
    app(ApproveVendorDestination::class)->handle($reviewer, $destination, $destination->content_digest, null, 'synthetic-independent-verification');
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching services', 'category' => 'software',
        'allocated_amount' => '100', 'spent_amount' => '0', 'remaining_amount' => '100', 'status' => 'active']);
    $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'LMS-'.Str::uuid(), 'amount' => '25', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending']);
    $draft = app(PrepareVendorPayment::class)->handle($actor, $invoice, $wallet, new Money(1, CurrencyCode::USDC), (string) Str::uuid());

    return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'wallet' => $wallet,
        'vendor' => $vendor, 'budget' => $budget, 'invoice' => $invoice, 'draft' => $draft];
}

/** @param array{institution: Organization, actor: User, reviewer: User, invoice: Invoice, wallet: Wallet, vendor: Vendor, budget: Budget, draft: PaymentIntent} $context */
function proposeRecovery(array $context, string $kind = 'replace'): PaymentIntentChange
{
    return app(ProposePaymentIntentChange::class)->handle($context['actor'], $context['draft'], (string) Str::uuid(), $context['draft']->snapshot_digest,
        $kind, 'Correct approved bill context.', $kind === 'replace' ? (string) Str::uuid() : null,
        $kind === 'replace' ? $context['wallet'] : null, $kind === 'replace' ? new Money(2, CurrencyCode::USDC) : null);
}

test('reviewed replacement preserves original evidence and creates only one fresh non executable successor', function (): void {
    $context = recoveryContext();
    $original = $context['draft']->fresh()->getAttributes();
    $context['invoice']->update(['due_date' => now()->addDays(2)]);
    $change = proposeRecovery($context);
    expect(PaymentIntent::query()->count())->toBe(1)
        ->and(app(PaymentIntentLifecycle::class)->resolve($context['institution']->id, $context['invoice']->id)['current']->id)->toBe($context['draft']->id);
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent correction check.');
    $successor = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
    $repeat = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent correction check.');
    expect($repeat->id)->toBe($review->id)->and($successor->revision)->toBe(1)->and($successor->change_review_id)->toBe($review->id)
        ->and($successor->intent_key)->toBe($change->replacement_intent_key)->and($successor->intent_key !== $context['draft']->intent_key)->toBeTrue()
        ->and($successor->provider_idempotency_key)->toBe('eduflow:'.$change->replacement_intent_key)
        ->and($successor->prepared_by)->toBe($context['actor']->id)->and($successor->max_fee_base_units)->toBe(2)
        ->and($successor->hasValidSnapshot())->toBeTrue()->and($successor->evidence()['can_execute'])->toBeFalse()
        ->and($successor->evidence()['approved'])->toBeFalse()->and($successor->evidence()['funds_reserved'])->toBeFalse()
        ->and($context['draft']->fresh()->getAttributes())->toBe($original)
        ->and(PaymentIntent::query()->count())->toBe(2)->and(PaymentIntentChangeReview::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'payment_intent_change_reviewed')->count())->toBe(1)
        ->and($context['invoice']->fresh()->status)->toBe('pending')->and($context['wallet']->fresh()->balance)->toBe(500.0)
        ->and($context['budget']->fresh()->remaining_amount)->toBe(100.0)->and(Transaction::query()->count())->toBe(0)
        ->and(Approval::query()->count())->toBe(0)->and(Student::query()->count())->toBe(0)
        ->and(Gate::forUser($context['reviewer'])->allows('execute', $successor))->toBeFalse()
        ->and(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $successor)->id)->toBe($successor->id)
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $context['draft']))->toThrow(ValidationException::class)
        ->and(app(PrepareVendorPayment::class)->handle($context['actor'], $context['invoice'], $context['wallet'], new Money(2, CurrencyCode::USDC), $successor->intent_key)->id)->toBe($successor->id)
        ->and(fn () => app(PrepareVendorPayment::class)->handle($context['actor'], $context['invoice'], $context['wallet'], new Money(1, CurrencyCode::USDC), $context['draft']->intent_key))->toThrow(ValidationException::class);
});

test('independently cancelled bill stays closed to old and new keys without changing original draft', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context, 'cancel');
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Bill withdrawn by finance.');
    $state = app(PaymentIntentLifecycle::class)->resolve($context['institution']->id, $context['invoice']->id);
    expect($state['state'])->toBe('cancelled')->and($state['accepted_review']->id)->toBe($review->id)
        ->and($context['draft']->fresh()->status)->toBe('draft')->and(PaymentIntent::query()->count())->toBe(1)
        ->and(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $context['draft']))->toThrow(ValidationException::class)
        ->and(fn () => app(PrepareVendorPayment::class)->handle($context['actor'], $context['invoice'], $context['wallet'], new Money(1, CurrencyCode::USDC), (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(fn (): PaymentIntentChange => proposeRecovery($context))->toThrow(ValidationException::class)
        ->and($context['invoice']->fresh()->status)->toBe('pending')->and(Transaction::query()->count())->toBe(0);
});

test('rejected replacement never retires draft or creates successor and exact retries preserve audit', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    $repeat = app(ProposePaymentIntentChange::class)->handle($context['actor'], $context['draft'], strtoupper($change->request_key), $context['draft']->snapshot_digest,
        'replace', $change->reason, strtoupper($change->replacement_intent_key), $context['wallet'], new Money(2, CurrencyCode::USDC));
    expect($repeat->id)->toBe($change->id)->and(Activity::query()->where('event', 'payment_intent_change_proposed')->count())->toBe(1);
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'reject', 'Correction not needed.');
    expect($review->retired_payment_intent_id)->toBeNull()->and(PaymentIntent::query()->count())->toBe(1)
        ->and(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $context['draft'])->id)->toBe($context['draft']->id)
        ->and(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Now approve.'))->toThrow(ValidationException::class);
});

test('source maker and proposal maker cannot review even as super admin', function (string $case): void {
    $context = recoveryContext();
    $context['actor']->assignRole(Role::findOrCreate('super_admin', 'web'));
    if ($case === 'source-maker') {
        $context['actor'] = $context['reviewer'];
    }
    $change = proposeRecovery($context);
    /** @var User $sourceMaker */
    $sourceMaker = User::query()->findOrFail($context['draft']->prepared_by);
    $attempt = $case === 'source-maker' ? $sourceMaker : $context['actor'];
    expect(fn () => app(ReviewPaymentIntentChange::class)->handle($attempt, $change, $change->content_digest, 'approve_change', 'Self review.'))->toThrow(AuthorizationException::class)
        ->and(PaymentIntentChangeReview::query()->count())->toBe(0)->and(PaymentIntent::query()->count())->toBe(1);
})->with(['source-maker', 'proposal-maker']);

test('only one of competing recovery proposals can retire current draft', function (): void {
    $context = recoveryContext();
    $first = proposeRecovery($context);
    $second = proposeRecovery($context, 'cancel');
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $first, $first->content_digest, 'approve_change', 'Correction accepted.');
    expect(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $second, $second->content_digest, 'approve_change', 'Competing cancellation.'))->toThrow(ValidationException::class)
        ->and(PaymentIntentChangeReview::query()->count())->toBe(1)->and(PaymentIntent::query()->count())->toBe(2);
});

test('replacement evidence is rechecked against current bill treasury policy and destination', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    match ($case) {
        'invoice' => $context['invoice']->update(['category' => 'hardware']),
        'wallet' => $context['wallet']->update(['address' => '0x'.str_repeat('3', 40)]),
        'budget' => $context['budget']->update(['status' => 'inactive']),
        'vendor' => $context['vendor']->update(['wallet_address' => '0x'.str_repeat('4', 40)]),
        'chain' => config(['lepton.arc.chain' => 'ARC', 'lepton.arc.chain_id' => 5042]),
        default => throw new InvalidArgumentException('Unknown drift case.'),
    };
    expect(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Review stale evidence.'))->toThrow(ValidationException::class)
        ->and(PaymentIntentChangeReview::query()->count())->toBe(0)->and(PaymentIntent::query()->count())->toBe(1);
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'reject', 'Evidence has changed.');
    expect(PaymentIntentChangeReview::query()->count())->toBe(1);
})->with(['invoice', 'wallet', 'budget', 'vendor', 'chain']);

test('new policy activation can be recovered through exact reviewed replacement', function (): void {
    $context = recoveryContext();
    $policy = app(CreateFinancePolicyVersion::class)->handle($context['actor'], 'v2', new Money(1, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(10, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, $context['draft']->finance_policy_activation_id);
    $change = proposeRecovery($context);
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Policy change checked.');
    $successor = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
    expect($successor->finance_policy_version_id)->toBe($policy->id)
        ->and(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $successor)->id)->toBe($successor->id);
});

test('policy or destination rotation after proposal requires a fresh replacement review', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    if ($case === 'policy') {
        $policy = app(CreateFinancePolicyVersion::class)->handle($context['actor'], 'v2', new Money(1, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(10, CurrencyCode::USDC));
        app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, $context['draft']->finance_policy_activation_id);
    } else {
        $destination = app(PrepareVendorDestination::class)->handle($context['actor'], $context['vendor'], 'v2', $context['vendor']->wallet_address, 'ARC-TESTNET', 'renewed-synthetic-control-evidence');
        app(ApproveVendorDestination::class)->handle($context['reviewer'], $destination, $destination->content_digest,
            $context['draft']->vendor_destination_approval_id, 'renewed-synthetic-independent-verification');
    }
    expect(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Stale context.'))->toThrow(ValidationException::class)
        ->and(PaymentIntentChangeReview::query()->count())->toBe(0)->and(PaymentIntent::query()->count())->toBe(1);
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'reject', 'Current evidence replaced.');
    $fresh = proposeRecovery($context);
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $fresh, $fresh->content_digest, 'approve_change', 'Current evidence independently reviewed.');
    $successor = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
    expect(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $successor)->id)->toBe($successor->id);
})->with(['policy', 'destination']);

test('recovery key cannot be reused for changed feedback different actor or replacement identity', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    expect(fn () => app(ProposePaymentIntentChange::class)->handle($case === 'actor' ? $context['reviewer'] : $context['actor'],
        $context['draft'], $change->request_key, $context['draft']->snapshot_digest, 'replace',
        $case === 'reason' ? 'Changed reason.' : $change->reason, $case === 'key' ? (string) Str::uuid() : $change->replacement_intent_key,
        $context['wallet'], new Money(2, CurrencyCode::USDC)))->toThrow(ValidationException::class)
        ->and(PaymentIntentChange::query()->count())->toBe(1);
})->with(['actor', 'reason', 'key']);

test('replacement keys are reserved across documents even while proposal is pending', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    $invoice = $context['invoice']->replicate();
    $invoice->reference = 'OTHER-'.Str::uuid();
    $invoice->save();
    expect(fn () => app(PrepareVendorPayment::class)->handle($context['actor'], $invoice, $context['wallet'], new Money(1, CurrencyCode::USDC), $change->replacement_intent_key))->toThrow(ValidationException::class)
        ->and(fn () => app(ProposePaymentIntentChange::class)->handle($context['actor'], $context['draft'], (string) Str::uuid(), $context['draft']->snapshot_digest,
            'replace', 'Reuse current key.', $context['draft']->intent_key, $context['wallet'], new Money(2, CurrencyCode::USDC)))->toThrow(ValidationException::class);
});

test('recovery validates exact expected proposal and source digests', function (): void {
    $context = recoveryContext();
    expect(fn () => app(ProposePaymentIntentChange::class)->handle($context['actor'], $context['draft'], (string) Str::uuid(), str_repeat('0', 64), 'cancel', 'Cancel.'))->toThrow(ValidationException::class);
    $change = proposeRecovery($context, 'cancel');
    expect(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, str_repeat('0', 64), 'approve_change', 'Wrong digest.'))->toThrow(ValidationException::class)
        ->and(PaymentIntentChangeReview::query()->count())->toBe(0);
});

test('successor chains support later correction and terminal cancellation without resurrecting ancestors', function (): void {
    $context = recoveryContext();
    for ($revision = 1; $revision <= 2; $revision++) {
        $change = proposeRecovery($context);
        app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent check.');
        $context['draft'] = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
        expect($context['draft']->revision)->toBe($revision);
    }
    $cancel = proposeRecovery($context, 'cancel');
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $cancel, $cancel->content_digest, 'approve_change', 'Withdraw latest draft.');
    expect(app(PaymentIntentLifecycle::class)->resolve($context['institution']->id, $context['invoice']->id)['state'])->toBe('cancelled')
        ->and(PaymentIntent::query()->count())->toBe(3)->and(Transaction::query()->count())->toBe(0);
});

test('proposal review and lineage tampering fail closed before verification or another recovery', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent check.');
    $successor = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
    if ($case === 'proposal') {
        DB::table((new PaymentIntentChange)->getTable())->where('id', $change->id)->update(['reason' => 'Altered feedback.']);
    } elseif ($case === 'review') {
        DB::table((new PaymentIntentChangeReview)->getTable())->where('id', $review->id)->update(['reason' => 'Altered review.']);
    } elseif ($case === 'lineage') {
        DB::table((new PaymentIntent)->getTable())->where('id', $successor->id)->update(['revision' => 3]);
    } else {
        DB::table((new PaymentIntent)->getTable())->where('id', $successor->id)->delete();
    }
    expect(fn () => app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $context['draft']))->toThrow(ValidationException::class)
        ->and(fn (): PaymentIntentChange => proposeRecovery($context, 'cancel'))->toThrow(ValidationException::class);
})->with(['proposal', 'review', 'lineage', 'missing-successor']);

test('recovery models are immutable and evidence foreign keys prevent erasure', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context, 'cancel');
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Withdraw.');
    expect(fn () => $change->update(['reason' => 'Change.']))->toThrow(LogicException::class)
        ->and(fn () => $change->delete())->toThrow(LogicException::class)
        ->and(fn () => $review->update(['reason' => 'Change.']))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $context['draft']->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table((new PaymentIntentChange)->getTable())->where('id', $change->id)->delete())->toThrow(QueryException::class);
});

test('database preserves one root and enforces structural recovery and monetary bounds', function (string $case): void {
    $context = recoveryContext();
    $row = $context['draft']->getAttributes();
    unset($row['id']);
    $key = (string) Str::uuid();
    $row['intent_key'] = $key;
    $row['provider_idempotency_key'] = 'eduflow:'.$key;
    match ($case) {
        'second-root' => null,
        'orphan-successor' => $row['revision'] = 1,
        'negative-revision' => $row['revision'] = -1,
        'fractional-revision' => $row['revision'] = 1.5,
        'fractional-money' => $row['amount_base_units'] = 1.5,
        default => throw new InvalidArgumentException('Unknown bounds case.'),
    };
    expect(fn () => DB::table((new PaymentIntent)->getTable())->insert($row))->toThrow(QueryException::class);
})->with(['second-root', 'orphan-successor', 'negative-revision', 'fractional-revision', 'fractional-money']);

test('recovery migration preserves legacy root digests restores bounds and refuses populated rollback', function (): void {
    $context = recoveryContext();
    $before = $context['draft']->fresh()->getAttributes();
    $migration = require database_path('migrations/2026_10_08_113557_add_reviewed_recovery_to_payment_intents.php');
    $migration->down();
    $migration->up();
    expect($context['draft']->fresh()->getAttributes())->toBe($before)
        ->and(fn () => DB::table((new PaymentIntent)->getTable())->where('id', $context['draft']->id)->update(['amount_base_units' => 1.5]))->toThrow(QueryException::class);
    proposeRecovery($context, 'cancel');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});

test('authenticated recovery HTTP routes bind actor evidence and refuse injected authority', function (): void {
    $context = recoveryContext();
    $payload = ['request_key' => (string) Str::uuid(), 'expected_digest' => $context['draft']->snapshot_digest, 'kind' => 'cancel', 'reason' => 'Withdraw bill.'];
    $url = route('finance.payment-intent-changes.store', $context['draft']);
    postJson($url, $payload)->assertUnauthorized();
    actingAs($context['actor']);
    postJson($url, $payload + ['proposed_by' => $context['reviewer']->id])->assertUnprocessable()->assertJsonValidationErrors('proposed_by');
    $response = postJson($url, $payload)->assertSuccessful()->assertJsonPath('data.content.proposed_by', $context['actor']->id)->assertJsonPath('data.can_execute', false);
    $change = PaymentIntentChange::query()->findOrFail($response->json('data.id'));
    $reviewUrl = route('finance.payment-intent-changes.review', $change);
    $reviewPayload = ['expected_digest' => $change->content_digest, 'decision' => 'approve_change', 'reason' => 'Independent approval.'];
    postJson($reviewUrl, $reviewPayload)->assertForbidden();
    actingAs($context['reviewer']);
    postJson($reviewUrl, $reviewPayload + ['replacement_snapshot' => ['amount_base_units' => 1]])->assertUnprocessable();
    postJson($reviewUrl, $reviewPayload)->assertSuccessful()->assertJsonPath('data.payment_approved', false)->assertJsonPath('data.can_execute', false);
    getJson(route('finance.payment-intent-changes.show', $change))->assertSuccessful()->assertJsonPath('lifecycle.state', 'cancelled');
});

test('recovery HTTP replacement computes exact snapshot server side and requires complete intent fields', function (): void {
    $context = recoveryContext();
    $payload = ['request_key' => (string) Str::uuid(), 'expected_digest' => $context['draft']->snapshot_digest, 'kind' => 'replace', 'reason' => 'Correct fee ceiling.'];
    $url = route('finance.payment-intent-changes.store', $context['draft']);
    actingAs($context['actor']);
    postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors(['replacement_intent_key', 'wallet_id', 'max_fee']);
    $payload += ['replacement_intent_key' => (string) Str::uuid(), 'wallet_id' => $context['wallet']->id, 'max_fee' => '0.000002'];
    $response = postJson($url, $payload)->assertSuccessful()->assertJsonPath('data.content.replacement_snapshot.max_fee_base_units', '2');
    $change = PaymentIntentChange::query()->findOrFail($response->json('data.id'));
    actingAs($context['reviewer']);
    postJson(route('finance.payment-intent-changes.review', $change), [
        'expected_digest' => $change->content_digest, 'decision' => 'approve_change', 'reason' => 'Exact correction checked.',
    ])->assertSuccessful()->assertJsonPath('data.successor_intent_key', $payload['replacement_intent_key'])->assertJsonPath('data.can_execute', false);
});

test('review successor and audit commit atomically when draft persistence fails', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    DB::unprepared("CREATE TRIGGER reject_test_successor BEFORE INSERT ON payment_intents
        WHEN NEW.revision > 0 BEGIN SELECT RAISE(ABORT, 'Injected successor write failure'); END");
    try {
        expect(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent check.'))->toThrow(QueryException::class)
            ->and(PaymentIntentChangeReview::query()->count())->toBe(0)->and(PaymentIntent::query()->count())->toBe(1)
            ->and(Activity::query()->whereIn('event', ['payment_intent_change_reviewed', 'vendor_payment_replacement_prepared'])->count())->toBe(0)
            ->and(app(PaymentIntentLifecycle::class)->resolve($context['institution']->id, $context['invoice']->id)['state'])->toBe('active_draft');
    } finally {
        DB::unprepared('DROP TRIGGER reject_test_successor');
    }
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent check.');
    expect(PaymentIntent::query()->count())->toBe(2)->and(PaymentIntentChangeReview::query()->count())->toBe(1);
});

test('recovery can retire an intact upgraded draft without fabricating missing review evidence', function (): void {
    $context = recoveryContext();
    DB::table((new PaymentIntent)->getTable())->where('id', $context['draft']->id)->update([
        'finance_policy_version_id' => null, 'finance_policy_activation_id' => null,
        'vendor_destination_version_id' => null, 'vendor_destination_approval_id' => null,
    ]);
    $context['draft'] = $context['draft']->fresh();
    expect($context['draft']->hasValidSnapshot())->toBeFalse();
    $change = proposeRecovery($context);
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Current policy and destination independently checked.');
    $successor = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
    expect($context['draft']->fresh()->finance_policy_activation_id)->toBeNull()
        ->and($successor->finance_policy_activation_id)->toBeGreaterThan(0)
        ->and(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $successor)->id)->toBe($successor->id);
});

test('source identity and malformed replacement cannot be laundered through recovery', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    if ($case === 'source-identity') {
        DB::table((new PaymentIntent)->getTable())->where('id', $context['draft']->id)->update(['recipient_address' => '0x'.str_repeat('4', 40)]);
    } else {
        DB::table((new PaymentIntentChange)->getTable())->where('id', $change->id)->update(['replacement_snapshot' => json_encode(['invoice' => 'wrong-shape'], JSON_THROW_ON_ERROR)]);
    }
    expect(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Review.'))->toThrow(ValidationException::class)
        ->and(PaymentIntent::query()->count())->toBe(1)->and(PaymentIntentChangeReview::query()->count())->toBe(0);
})->with(['source-identity', 'malformed-replacement']);

test('review cannot forge independent identities even after recomputing its digest', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context, 'cancel');
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Withdraw.');
    $review->reviewed_by = $context['actor']->id;
    DB::table((new PaymentIntentChangeReview)->getTable())->where('id', $review->id)->update([
        'reviewed_by' => $context['actor']->id, 'review_digest' => PaymentIntent::digest($review->content()),
    ]);
    expect(fn () => app(PaymentIntentLifecycle::class)->resolve($context['institution']->id, $context['invoice']->id))->toThrow(ValidationException::class);
});

test('canonical object ordering does not invalidate reviewed replacement or retries', function (): void {
    $context = recoveryContext();
    $change = proposeRecovery($context);
    $reordered = array_reverse($change->replacement_snapshot, true);
    $reordered['recovery'] = array_reverse($reordered['recovery'], true);
    DB::table((new PaymentIntentChange)->getTable())->where('id', $change->id)->update(['replacement_snapshot' => json_encode($reordered, JSON_THROW_ON_ERROR)]);
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Independent check.');
    $successor = PaymentIntent::query()->where('predecessor_id', $context['draft']->id)->firstOrFail();
    expect(app(VerifyVendorPaymentDraft::class)->handle($context['actor'], $successor)->id)->toBe($successor->id);
});

test('proposal and review replay remains evidence only after later correction or document changes', function (): void {
    $context = recoveryContext();
    $first = proposeRecovery($context);
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $first, $first->content_digest, 'approve_change', 'Independent check.');
    $source = $context['draft'];
    $context['draft'] = PaymentIntent::query()->where('predecessor_id', $source->id)->firstOrFail();
    $cancel = proposeRecovery($context, 'cancel');
    app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $cancel, $cancel->content_digest, 'approve_change', 'Withdraw.');
    $context['invoice']->update(['status' => 'paid']);
    $repeat = app(ProposePaymentIntentChange::class)->handle($context['actor'], $source, $first->request_key, $source->snapshot_digest,
        'replace', $first->reason, $first->replacement_intent_key, $context['wallet'], new Money(2, CurrencyCode::USDC));
    expect($repeat->id)->toBe($first->id)
        ->and(app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $first, $first->content_digest, 'approve_change', 'Independent check.')->id)->toBe($review->id)
        ->and(app(PaymentIntentLifecycle::class)->resolve($context['institution']->id, $context['invoice']->id)['state'])->toBe('cancelled')
        ->and(PaymentIntent::query()->count())->toBe(2)->and(Transaction::query()->count())->toBe(0);
});

test('database guards reject malformed recovery modes and duplicate accepted retirement', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context, 'cancel');
    $review = app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Withdraw.');
    if ($case === 'proposal-shape') {
        expect(fn () => DB::table((new PaymentIntentChange)->getTable())->where('id', $change->id)->update(['kind' => 'replace']))->toThrow(QueryException::class);
    } elseif ($case === 'review-shape') {
        expect(fn () => DB::table((new PaymentIntentChangeReview)->getTable())->where('id', $review->id)->update(['decision' => 'reject']))->toThrow(QueryException::class);
    } else {
        $other = $change->fresh()->getAttributes();
        unset($other['id']);
        $other['request_key'] = (string) Str::uuid();
        $otherId = DB::table((new PaymentIntentChange)->getTable())->insertGetId($other);
        $row = $review->fresh()->getAttributes();
        unset($row['id']);
        $row['payment_intent_change_id'] = $otherId;
        expect(fn () => DB::table((new PaymentIntentChangeReview)->getTable())->insert($row))->toThrow(QueryException::class);
    }
})->with(['proposal-shape', 'review-shape', 'duplicate-retirement']);

test('HTTP recovery refuses unverified users foreign records and finance officer review and throttles writes', function (string $case): void {
    $context = recoveryContext();
    $change = proposeRecovery($context, 'cancel');
    if ($case === 'unverified') {
        $context['actor']->forceFill(['email_verified_at' => null])->save();
        actingAs($context['actor']);
        getJson(route('finance.payment-intent-changes.show', $change))->assertForbidden();
    } elseif ($case === 'foreign') {
        /** @var Organization $other */
        $other = Organization::factory()->create();
        config(['eduflow.institution_id' => $other->id]);
        actingAs($context['reviewer']);
        getJson(route('finance.payment-intent-changes.show', $change))->assertForbidden();
    } elseif ($case === 'finance-review') {
        /** @var User $other */
        $other = User::factory()->create();
        $other->assignRole(Role::findOrCreate('finance_officer', 'web'));
        actingAs($other);
        postJson(route('finance.payment-intent-changes.review', $change), ['expected_digest' => $change->content_digest,
            'decision' => 'approve_change', 'reason' => 'Review.'])->assertForbidden();
    } else {
        actingAs($context['actor']);
        $payload = ['request_key' => $change->request_key, 'expected_digest' => $context['draft']->snapshot_digest,
            'kind' => 'cancel', 'reason' => $change->reason];
        for ($attempt = 0; $attempt < 10; $attempt++) {
            postJson(route('finance.payment-intent-changes.store', $context['draft']), $payload)->assertSuccessful();
        }
        postJson(route('finance.payment-intent-changes.store', $context['draft']), $payload)->assertTooManyRequests();
    }
})->with(['unverified', 'foreign', 'finance-review', 'throttle']);

test('any recorded legacy payment outcome blocks new recovery without pretending a failed receipt is safe to resend', function (string $status): void {
    $context = recoveryContext();
    $change = proposeRecovery($context, 'cancel');
    Transaction::query()->create(['organization_id' => $context['institution']->id, 'wallet_id' => $context['wallet']->id,
        'type' => 'vendor_payment', 'recipient_address' => $context['vendor']->wallet_address, 'amount' => '25', 'currency' => 'USDC',
        'status' => $status, 'network' => 'arc-testnet', 'reference_type' => Invoice::class, 'reference_id' => $context['invoice']->id]);
    expect(fn (): PaymentIntentChange => proposeRecovery($context))->toThrow(ValidationException::class)
        ->and(fn () => app(ReviewPaymentIntentChange::class)->handle($context['reviewer'], $change, $change->content_digest, 'approve_change', 'Cancel draft.'))->toThrow(ValidationException::class)
        ->and(PaymentIntentChangeReview::query()->count())->toBe(0)->and(PaymentIntent::query()->count())->toBe(1)
        ->and($context['invoice']->fresh()->status)->toBe('pending');
})->with(['pending', 'confirmed', 'failed']);

test('foreign or ambiguous installation and unauthorized staff cannot recover drafts', function (string $case): void {
    $context = recoveryContext();
    $actor = $context['actor'];
    if ($case === 'role') {
        $actor = User::factory()->create();
    } elseif ($case === 'foreign') {
        /** @var Organization $other */
        $other = Organization::factory()->create();
        config(['eduflow.institution_id' => $other->id]);
    } else {
        Organization::factory()->create();
    }
    expect(fn () => app(ProposePaymentIntentChange::class)->handle($actor, $context['draft'], (string) Str::uuid(), $context['draft']->snapshot_digest, 'cancel', 'Withdraw.'))->toThrow(AuthorizationException::class)
        ->and(PaymentIntentChange::query()->count())->toBe(0);
})->with(['role', 'foreign', 'ambiguous']);
