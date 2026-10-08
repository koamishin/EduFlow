<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\PaymentIntentChangeReview;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a document's append-only draft lineage, never payment authority.
 */
final class PaymentIntentLifecycle
{
    /** @return array{state: string, current: PaymentIntent|null, accepted_review: PaymentIntentChangeReview|null} */
    public function resolve(int $institutionId, int $invoiceId): array
    {
        /** @var Collection<int, PaymentIntent> $intents */
        $intents = PaymentIntent::query()->where('invoice_id', $invoiceId)->where('portion', 'full')->orderBy('revision')->limit(10_001)->get();
        /** @var Collection<int, PaymentIntentChange> $changes */
        $changes = PaymentIntentChange::query()->where('invoice_id', $invoiceId)->orderBy('id')->limit(10_001)->get();
        /** @var Collection<int, PaymentIntentChangeReview> $reviews */
        $reviews = PaymentIntentChangeReview::query()->where('invoice_id', $invoiceId)->orderBy('id')->limit(10_001)->get();
        if ($intents->count() > 10_000 || $changes->count() > 10_000 || $reviews->count() > 10_000) {
            $this->refuse();
        }
        if ($intents->isEmpty()) {
            if ($changes->isNotEmpty() || $reviews->isNotEmpty()) {
                $this->refuse();
            }

            return ['state' => 'none', 'current' => null, 'accepted_review' => null];
        }
        $byId = $intents->keyBy('id');
        $byChange = $changes->keyBy('id');
        /** @var array<int, PaymentIntentChangeReview> $accepted */
        $accepted = [];
        foreach ($changes as $change) {
            $source = $byId->get($change->payment_intent_id);
            if ($source === null || ! $change->hasValidEvidence($source)) {
                $this->refuse();
            }
        }
        foreach ($reviews as $review) {
            $source = $byId->get($review->payment_intent_id);
            $change = $byChange->get($review->payment_intent_change_id);
            if ($source === null || $change === null || ! $review->hasValidEvidence($change, $source)) {
                $this->refuse();
            }
            if ($review->decision === 'approve_change') {
                if (isset($accepted[$source->id])) {
                    $this->refuse();
                }
                $accepted[$source->id] = $review;
            }
        }
        $previous = null;
        foreach ($intents as $revision => $intent) {
            if (! $intent->hasIntactDraftIdentity() || $intent->organization_id !== $institutionId || $intent->revision !== $revision) {
                $this->refuse();
            }
            if ($previous === null) {
                if ($intent->predecessor_id !== null || $intent->change_review_id !== null || array_key_exists('recovery', $intent->snapshot)) {
                    $this->refuse();
                }
            } else {
                $review = $accepted[$previous->id] ?? null;
                $change = $review === null ? null : $byChange->get($review->payment_intent_change_id);
                if ($review === null || $change === null || $change->kind !== 'replace' || $change->replacement_snapshot === null
                    || $intent->predecessor_id !== $previous->id || $intent->change_review_id !== $review->id
                    || ! $intent->hasValidSnapshot() || ! hash_equals($intent->snapshot_digest, PaymentIntent::digest($change->replacement_snapshot))) {
                    $this->refuse();
                }
            }
            $previous = $intent;
        }
        $current = $intents->last();
        $review = $accepted[$current->id] ?? null;
        if ($review === null) {
            return ['state' => 'active_draft', 'current' => $current, 'accepted_review' => null];
        }
        $change = $byChange->get($review->payment_intent_change_id);
        if ($change === null || $change->kind !== 'cancel') {
            $this->refuse();
        }

        return ['state' => 'cancelled', 'current' => $current, 'accepted_review' => $review];
    }

    public function requireActive(PaymentIntent $intent): void
    {
        if (Transaction::query()->where('reference_type', (new Invoice)->getMorphClass())->where('reference_id', $intent->invoice_id)->exists()) {
            throw ValidationException::withMessages(['payment' => 'Bill has payment evidence; investigate submission and settlement before draft recovery.']);
        }
        $lifecycle = $this->resolve($intent->organization_id, $intent->invoice_id);
        if ($lifecycle['state'] !== 'active_draft' || $lifecycle['current']?->id !== $intent->id) {
            throw ValidationException::withMessages(['payment' => 'Payment draft is cancelled or superseded; use the reviewed current draft.']);
        }
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['payment' => 'Payment draft recovery history is incomplete or invalid; independent investigation required.']);
    }
}
