<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Picks up one durably owed submission.
 *
 * **This job submits nothing.** No executor ships in this build, so the worker
 * re-checks its own authority, records exactly one append-only attempt, and
 * concludes the entry as `blocked` with an explicit reason. Every property
 * that a real executor must satisfy — dense attempt numbering, a stable
 * provider idempotency key, an append-only outcome, no transaction row — is
 * therefore already exercised, and the only thing left to write later is the
 * gateway call itself.
 *
 * Writing a fake "submitted" result here would be the single most damaging
 * thing this class could do: the plan is explicit that a stored hash is a
 * claim, not proof, and that unknown outcomes are reconciled rather than
 * retried.
 */
class ProcessPaymentSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $outboxId) {}

    public function handle(): void
    {
        /** @var PaymentSubmissionOutbox|null $entry */
        $entry = PaymentSubmissionOutbox::query()->find($this->outboxId);

        if ($entry === null) {
            return;
        }

        $entry->heartbeat_at = Carbon::now();
        $entry->attempts = $entry->attempts + 1;
        $entry->state = 'running';
        $entry->stage = 'revalidating_authority';
        $entry->save();

        try {
            // Authority is re-read here, never trusted from the enqueue moment.
            if (! $entry->hasValidSnapshot() || ! $entry->bindsCurrentAuthorization()) {
                $this->conclude($entry, 'blocked', 'authorization_stale',
                    'Authorization evidence is no longer intact or current. Fresh review is required; nothing is submitted.');

                return;
            }

            $authorization = $entry->authorization?->evidence() ?? [];

            if (($authorization['payment_approved'] ?? false) !== true) {
                $this->conclude($entry, 'blocked', 'approval_expired',
                    'Approval is no longer current. An expired authorization is never submitted on its original authority.');

                return;
            }

            if (($authorization['is_fake'] ?? false) === true) {
                $this->conclude($entry, 'blocked', 'simulation_evidence',
                    'Bound evidence is from the fake driver. Simulation is never submitted to a network rail.');

                return;
            }

            if (($entry->snapshot['chain'] ?? null) !== 'ARC-TESTNET' || ($entry->snapshot['chain_id'] ?? null) !== 5042002) {
                $this->conclude($entry, 'blocked', 'non_testnet_rail',
                    'Only explicitly configured Arc testnet execution is permitted; mainnet remains blocked.');

                return;
            }

            // Stop switch: blocks new submissions at executor time, and does
            // not erase the evidence or release the hold.
            if (config('eduflow.submission.stop_switch', false) === true) {
                $this->conclude($entry, 'blocked', 'stop_switch',
                    'Submission stop switch is engaged. Queued work is held; in-flight reconciliation and evidence are preserved.');

                return;
            }

            $this->conclude($entry, 'blocked', 'no_executor_shipped',
                'No isolated submission executor exists in this build. The payment remains authorized, durably owed and unsent.');
        } catch (Throwable $exception) {
            $this->conclude($entry, 'unknown', 'worker_exception',
                'Worker failed before a provider outcome was known: '.$exception->getMessage(), null, $exception);
        }
    }

    private function conclude(PaymentSubmissionOutbox $entry, string $state, string $stage, string $error,
        ?array $response = null, ?Throwable $exception = null): void
    {
        $attempt = new PaymentSubmissionAttempt([
            'organization_id' => $entry->organization_id,
            'payment_submission_outbox_id' => $entry->id,
            'attempt_number' => $entry->attempts,
            'provider_idempotency_key' => $entry->provider_idempotency_key,
            'outcome' => $state === 'unknown' ? 'unknown' : 'blocked',
            'provider_reference' => null,
            'request_snapshot' => [
                'request_key' => $entry->request_key,
                'payment_intent_id' => $entry->payment_intent_id,
                'amount_base_units' => $entry->snapshot['amount_base_units'] ?? null,
                'chain' => $entry->snapshot['chain'] ?? null,
                'recipient_address' => $entry->snapshot['recipient_address'] ?? null,
            ],
            'response_snapshot' => $response,
            'observed_at' => Carbon::now(),
        ]);
        $attempt->save();

        $entry->state = $state;
        $entry->stage = $stage;
        $entry->last_error = mb_substr($error, 0, 1000);
        $entry->next_attempt_at = null;
        $entry->result = ['stage' => $stage, 'attempt_number' => $attempt->attempt_number,
            'outcome' => $attempt->outcome, 'provider_reference' => null,
            'settled' => false, 'payments_submitted' => 0, 'can_execute' => false];
        $entry->result_digest = PaymentIntent::digest($entry->result);
        $entry->save();

        activity('finance')->performedOn($entry)->event('payment_submission_'.$attempt->outcome)
            ->withProperties([
                'payment_intent_id' => $entry->payment_intent_id,
                'attempt_number' => $attempt->attempt_number,
                'stage' => $stage,
                'can_execute' => false,
                'payments_submitted' => 0,
            ])
            ->log($error);

        if ($exception !== null) {
            report($exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    /**
     * Nothing in this build may ever produce a transaction row.
     */
    public static function submittedCount(): int
    {
        return Transaction::query()->count();
    }
}
