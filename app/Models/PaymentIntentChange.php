<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use TypeError;

/**
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $invoice_id
 * @property int $payment_intent_id
 * @property int $proposed_by
 * @property string $kind
 * @property string $reason
 * @property string $source_digest
 * @property string|null $replacement_intent_key
 * @property array<string, mixed>|null $replacement_snapshot
 * @property string $content_digest
 */
class PaymentIntentChange extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'invoice_id', 'payment_intent_id', 'proposed_by',
        'kind', 'reason', 'source_digest', 'replacement_intent_key', 'replacement_snapshot', 'content_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $change): void {
            /** @var PaymentIntent|null $source */
            $source = PaymentIntent::query()->find($change->payment_intent_id);
            if ($source === null || ! $change->hasValidEvidence($source)) {
                throw new LogicException('A draft change requires intact bound source and replacement evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Payment draft change proposals are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Payment draft change evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'invoice_id' => 'integer', 'payment_intent_id' => 'integer',
            'proposed_by' => 'integer', 'replacement_snapshot' => 'array'];
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        return ['schema_version' => 1, 'request_key' => $this->request_key, 'institution_id' => $this->organization_id,
            'invoice_id' => $this->invoice_id, 'payment_intent_id' => $this->payment_intent_id,
            'proposed_by' => $this->proposed_by, 'kind' => $this->kind, 'reason' => $this->reason,
            'source_digest' => $this->source_digest, 'replacement_intent_key' => $this->replacement_intent_key,
            'replacement_snapshot' => $this->replacement_snapshot];
    }

    public function hasValidEvidence(PaymentIntent $source): bool
    {
        try {
            if (! $source->exists || $source->id !== $this->payment_intent_id || $source->organization_id !== $this->organization_id
                || $source->invoice_id !== $this->invoice_id || $source->status !== 'draft' || $this->proposed_by <= 0
                || ! Str::isUuid($this->request_key) || $this->request_key !== strtolower($this->request_key)
                || trim($this->reason) === '' || mb_strlen($this->reason) > 1000
                || ! hash_equals($source->snapshot_digest, $this->source_digest)
                || ! hash_equals($this->content_digest, PaymentIntent::digest($this->content()))) {
                return false;
            }
            // Nullable legacy review evidence may be replaced, never inferred as payment authority.
            if (! $source->hasIntactDraftIdentity()) {
                return false;
            }
            if ($this->kind === 'cancel') {
                return $this->replacement_intent_key === null && $this->replacement_snapshot === null;
            }
            if ($this->kind !== 'replace' || ! is_string($this->replacement_intent_key) || ! Str::isUuid($this->replacement_intent_key)
                || $this->replacement_intent_key === $source->intent_key || ! is_array($this->replacement_snapshot)) {
                return false;
            }
            $replacement = PaymentIntent::fromSnapshot($this->replacement_snapshot);
            $recovery = $replacement->snapshot['recovery'] ?? null;

            return $replacement->hasValidSnapshot() && $replacement->intent_key === $this->replacement_intent_key
                && $replacement->organization_id === $source->organization_id && $replacement->invoice_id === $source->invoice_id
                && $replacement->prepared_by === $this->proposed_by && $replacement->revision === $source->revision + 1
                && $replacement->predecessor_id === $source->id && is_array($recovery)
                && ($recovery['request_key'] ?? null) === $this->request_key && ($recovery['source_digest'] ?? null) === $this->source_digest;
        } catch (JsonException|TypeError) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        /** @var PaymentIntent|null $source */
        $source = PaymentIntent::query()->find($this->payment_intent_id);
        /** @var PaymentIntentChangeReview|null $review */
        $review = PaymentIntentChangeReview::query()->where('payment_intent_change_id', $this->id)->first();

        return ['id' => $this->id, 'content' => $this->content(), 'content_digest' => $this->content_digest,
            'content_valid' => $source !== null && $this->hasValidEvidence($source),
            'review' => $review?->evidence(), 'payment_approved' => false, 'funds_reserved' => false, 'can_execute' => false];
    }
}
