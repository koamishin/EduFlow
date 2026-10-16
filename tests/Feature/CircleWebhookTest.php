<?php

declare(strict_types=1);

use App\Jobs\ObserveCircleNotification;
use App\Jobs\ProcessPaymentSubmission;
use App\Models\Organization;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Yukazakiri\Lepton\Contracts\WebhookVerifier;
use Yukazakiri\Lepton\DTOs\VerifiedNotification;

function verifiedCircleEvent(string $type = 'transactions.outbound', string $chain = 'ARC-TESTNET', string $digest = ''): VerifiedNotification
{
    return new VerifiedNotification('01899cf2-d415-7052-a207-f9862157e546', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        $type, ['id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'blockchain' => $chain, 'state' => 'COMPLETE'],
        new DateTimeImmutable('now'), $digest === '' ? hash('sha256', '{}') : $digest);
}

function bindCircleVerifier(?VerifiedNotification $event): void
{
    app()->instance(WebhookVerifier::class, new class($event) implements WebhookVerifier
    {
        public function __construct(private readonly ?VerifiedNotification $event) {}

        public function verify(string $rawPayload, string $signatureBase64, string $keyId, array $allowedSubscriptions): VerifiedNotification
        {
            if (! $this->event instanceof VerifiedNotification) {
                throw new RuntimeException('Invalid signature.');
            }

            return $this->event;
        }
    });
}

beforeEach(function (): void {
    config(['lepton.default' => 'developer', 'lepton.webhooks.enabled' => true, 'lepton.webhooks.subscriptions' => ['a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'],
        'lepton.arc.chain' => 'ARC-TESTNET']);
});

it('rejects unauthenticated Circle notifications before storing or changing financial records', function (): void {
    bindCircleVerifier(null);
    $this->postJson('/api/webhooks/circle', [])->assertUnauthorized();
    $this->postJson('/api/webhooks/circle', [], ['X-Circle-Key-Id' => 'key', 'X-Circle-Signature' => 'invalid'])->assertUnauthorized();
    expect(DB::table('circle_webhook_notifications')->count())->toBe(0);
});

it('keeps Circle notifications disabled unless explicitly configured', function (): void {
    config(['lepton.webhooks.enabled' => false]);
    $this->postJson('/api/webhooks/circle', [])->assertNotFound();
});

it('stores one durable hint and queues observation only without settling payments', function (): void {
    bindCircleVerifier(verifiedCircleEvent());
    Bus::fake();
    $headers = ['X-Circle-Key-Id' => 'key', 'X-Circle-Signature' => 'valid'];
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202)->assertJson(['settlement_verified' => false]);
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202);
    expect(DB::table('circle_webhook_notifications')->count())->toBe(1)
        ->and(DB::table('circle_webhook_notifications')->value('requires_observation'))->toBeTruthy()
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
    Bus::assertDispatched(ObserveCircleNotification::class);
    Bus::assertNotDispatched(ProcessPaymentSubmission::class);
});

it('rejects conflicting replay identities and wrong-network events', function (): void {
    $headers = ['X-Circle-Key-Id' => 'key', 'X-Circle-Signature' => 'valid'];
    bindCircleVerifier(verifiedCircleEvent());
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202);
    bindCircleVerifier(verifiedCircleEvent(digest: hash('sha256', 'different')));
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(409);
    bindCircleVerifier(verifiedCircleEvent(chain: 'ARC'));
    $this->postJson('/api/webhooks/circle', [], $headers)->assertUnprocessable();
});

it('re-queues an owed hint when its first dispatch was lost', function (): void {
    bindCircleVerifier(verifiedCircleEvent());
    Bus::fake();
    $headers = ['X-Circle-Key-Id' => 'key', 'X-Circle-Signature' => 'valid'];

    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202);
    // The hint is still owed, so Circle's redelivery is the retry opportunity.
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202);
    Bus::assertDispatchedTimes(ObserveCircleNotification::class, 2);

    // Once observed, a further repeat delivery does no work at all.
    DB::table('circle_webhook_notifications')->update(['observed_at' => now()]);
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202);
    Bus::assertDispatchedTimes(ObserveCircleNotification::class, 2);
});

it('leaves an unmatched hint owed for recovery instead of creating a payment', function (): void {
    Organization::factory()->create([
        'name' => 'Webhook Academy', 'currency' => 'USDC',
    ]);

    bindCircleVerifier(verifiedCircleEvent());
    $headers = ['X-Circle-Key-Id' => 'key', 'X-Circle-Signature' => 'valid'];
    $this->postJson('/api/webhooks/circle', [], $headers)->assertStatus(202);

    // A webhook can beat the local record it refers to. Observing it must find
    // nothing to do and create nothing: the hint stays owed until a matching
    // submission exists, so recovery can pick it up later.
    (new ObserveCircleNotification('01899cf2-d415-7052-a207-f9862157e546'))
        ->handle(app(InstallationInstitution::class));

    $row = DB::table('circle_webhook_notifications')->first();

    expect($row->observed_at)->toBeNull()
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('re-disqueues owed hints through the recovery command', function (): void {
    Bus::fake();
    DB::table('circle_webhook_notifications')->insert([
        'notification_id' => '01899cf2-d415-7052-a207-f9862157e546',
        'subscription_id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'notification_type' => 'transactions.outbound',
        'payload_digest' => hash('sha256', '{}'),
        'provider_timestamp' => now(),
        'received_at' => now(),
        'provider_transaction_id' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3',
        'chain' => 'ARC-TESTNET',
        'requires_observation' => true,
    ]);

    $this->artisan('app:recover-circle-notifications')->assertSuccessful();

    Bus::assertDispatchedTimes(ObserveCircleNotification::class, 1);
});
