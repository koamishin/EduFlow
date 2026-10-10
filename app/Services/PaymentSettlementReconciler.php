<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Throwable;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Decides whether a submitted reference actually became money on Arc.
 *
 * This is the step that makes settlement a fact rather than an intention. A
 * provider reference is recorded by the executor; only `verified` from
 * `ArcSettlementVerifier` — successful execution in a committed block with
 * matching sender, recipient and amount — completes an entry.
 *
 * Every other verdict keeps capacity held and opens an investigation. In
 * particular `pending` is not a failure and `not_found` is not fabrication.
 */
final readonly class PaymentSettlementReconciler
{
    public function __construct(private ArcSettlementVerifier $verifier) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(PaymentSubmissionOutbox $entry): array
    {
        if ($entry->state !== 'submitted' || ($entry->result['provider_reference'] ?? null) === null) {
            return ['verdict' => 'not_applicable', 'settled' => false,
                'reason' => 'Only a submitted entry with a provider reference can be verified.'];
        }

        $attempt = PaymentSubmissionAttempt::query()
            ->where('payment_submission_outbox_id', $entry->id)
            ->where('outcome', 'submitted')
            ->orderByDesc('attempt_number')
            ->first();

        $expected = [
            'chain' => $entry->snapshot['chain'] ?? null,
            'chain_id' => $entry->snapshot['chain_id'] ?? null,
            'sender' => $entry->snapshot['source_address'] ?? '',
            'recipient' => $entry->snapshot['recipient_address'] ?? '',
            'amount_base_units' => (int) ($entry->snapshot['amount_base_units'] ?? 0),
            'max_fee_base_units' => (int) ($entry->snapshot['max_fee_base_units'] ?? 0),
        ];

        try {
            $verdict = $this->verifier->verify($expected, (string) $entry->result['provider_reference']);
        } catch (Throwable $exception) {
            report($exception);

            $verdict = ['verdict' => 'unreadable', 'settled' => false, 'reason' => 'Verifier failed: '.$exception->getMessage()];
        }

        $entry->heartbeat_at = Carbon::now();
        $entry->last_error = $verdict['settled'] ? null : mb_substr((string) $verdict['reason'], 0, 1000);

        // The provider reference is the evidence this verdict is about, so it
        // is carried forward rather than replaced. A completed entry that no
        // longer names what it verified cannot be audited against the chain.
        $reference = $entry->result['provider_reference'] ?? null;

        if ($verdict['settled'] === true) {
            $entry->state = 'completed';
            $entry->stage = 'verified_settled';
        } else {
            // Still owed: capacity stays held and the case stays open.
            $entry->state = 'unknown';
            $entry->stage = 'unverified_'.($verdict['verdict'] ?? 'unknown');
        }

        $entry->result = ['provider_reference' => $reference, ...$verdict,
            'payments_submitted' => 1, 'can_execute' => false];
        $entry->result_digest = PaymentIntent::digest($entry->result);
        $entry->save();

        activity('finance')->performedOn($entry)->event('payment_submission_'.($verdict['settled'] ? 'verified' : 'unverified'))
            ->withProperties([
                'payment_intent_id' => $entry->payment_intent_id,
                'verdict' => $verdict['verdict'] ?? null,
                'attempt_number' => $attempt?->attempt_number,
                'settled' => $verdict['settled'] === true,
                'fabricated' => false,
            ])
            ->log((string) $verdict['reason']);

        return $verdict;
    }

    /**
     * Mirror a verified settlement into the local transaction ledger.
     *
     * This is the only path in the codebase permitted to write a transaction
     * row for a vendor payment, and it writes one *only* for a verified Arc
     * settlement.
     *
     * Two honesty constraints shape the row. The exact amount is kept in
     * `metadata.amount_base_units` as well as the column, so the ledger can be
     * audited against the chain even if the column is ever narrowed again. And
     * a mirror never settles the institution's own payable: it reports that
     * money moved on Arc testnet, it is not a local accounting posting.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function mirrorSettlement(PaymentSubmissionOutbox $entry, array $verdict): ?Transaction
    {
        if (($verdict['settled'] ?? false) !== true) {
            return null;
        }

        $baseUnits = (int) $entry->snapshot['amount_base_units'];

        /** @var PaymentIntent $intent */
        $intent = PaymentIntent::query()->findOrFail($entry->payment_intent_id);

        return Transaction::query()->create([
            'organization_id' => $entry->organization_id,
            'wallet_id' => $intent->wallet_id,
            'type' => TransactionType::VENDOR_PAYMENT->value,
            'recipient_address' => $entry->snapshot['recipient_address'],
            'amount' => Amounts::toDecimalString($baseUnits, 6),
            'currency' => $entry->snapshot['currency'] ?? 'USDC',
            'status' => TransactionStatus::CONFIRMED->value,
            'provider_tx_hash' => $entry->result['provider_reference'] ?? null,
            'network' => $entry->snapshot['chain'],
            'reference_type' => PaymentIntent::class,
            'reference_id' => $entry->payment_intent_id,
            'metadata' => [
                'settlement' => 'verified_arc_testnet_mirror',
                'amount_base_units' => (string) $baseUnits,
                'receipt' => $verdict['receipt'] ?? null,
                'actual_fee_base_units' => $verdict['actual_fee_base_units'] ?? null,
                'local_accounts_changed' => false,
                'settles_local_payable' => false,
            ],
            'executed_at' => Carbon::now(),
        ]);
    }
}
