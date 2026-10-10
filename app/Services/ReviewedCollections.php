<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\PaymentIntent;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ReviewedCollections
{
    /** @param list<int> $reviewIds
     * @return list<array<string, int|string>>
     */
    public function capture(int $institutionId, string $currency, array $reviewIds, string $asOf, string $expectedReceived, string $restrictedCash): array
    {
        sort($reviewIds, SORT_NUMERIC);
        $entries = [];
        $received = BigInteger::zero();
        $restricted = BigInteger::zero();
        foreach ($reviewIds as $id) {
            /** @var CollectionBatchReview|null $review */
            $review = CollectionBatchReview::query()->where('organization_id', $institutionId)->whereKey($id)->first();
            /** @var CollectionBatch|null $batch */
            $batch = $review === null ? null : CollectionBatch::query()->where('organization_id', $institutionId)->whereKey($review->collection_batch_id)->first();
            if ($review === null || $batch === null || ! $review->hasValidEvidence($batch) || $review->decision !== 'approve_receipts'
                || $batch->currency !== $currency || $batch->collected_until->gt(Carbon::parse($asOf))) {
                throw ValidationException::withMessages(['collection_review_ids' => 'Intact independently approved receipts in the same currency, received by the snapshot time, are required.']);
            }
            $received = $received->plus($batch->received_minor_units);
            $restricted = $restricted->plus($batch->restricted_minor_units);
            $entries[] = ['review_id' => $review->id, 'review_digest' => $review->review_digest, 'batch_id' => $batch->id,
                'batch_digest' => $batch->snapshot_digest, 'received_minor_units' => (string) $batch->received_minor_units,
                'restricted_minor_units' => (string) $batch->restricted_minor_units];
        }
        if ((string) $received !== $expectedReceived || $restricted->isGreaterThan($restrictedCash)) {
            throw ValidationException::withMessages(['realized_receipts' => 'Realized receipts must equal reviewed gross collections; restricted cash must include their recorded restrictions.']);
        }

        return $entries;
    }

    public function requireBound(BudgetSnapshot $snapshot): void
    {
        if (($snapshot->snapshot['schema_version'] ?? null) === 1) {
            return;
        }
        $entries = $snapshot->snapshot['collections'];
        $ids = array_column($entries, 'review_id');
        /** @var Collection<int, CollectionBatchReview> $linked */
        $linked = $snapshot->collectionReviews()->get();
        if ($linked->count() !== count($ids) || $linked->pluck('id')->sort()->values()->all() !== $ids) {
            throw ValidationException::withMessages(['collections' => 'Stored collection bindings are incomplete; missing evidence cannot become spendable cash.']);
        }
        $current = $this->capture($snapshot->organization_id, $snapshot->currency, $ids, $snapshot->snapshot['as_of'],
            $snapshot->snapshot['amounts']['realized_receipts'], $snapshot->snapshot['amounts']['restricted_cash']);
        if (! hash_equals(PaymentIntent::digest(['collections' => $entries]), PaymentIntent::digest(['collections' => $current]))) {
            throw ValidationException::withMessages(['collections' => 'Bound collection or independent review changed; refresh complete planning evidence.']);
        }
    }
}
