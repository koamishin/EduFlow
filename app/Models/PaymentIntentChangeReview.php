<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $invoice_id
 * @property int $payment_intent_change_id
 * @property int $payment_intent_id
 * @property int|null $retired_payment_intent_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $reason
 * @property string $proposal_digest
 * @property string $review_digest
 */
class PaymentIntentChangeReview extends Model
{
    protected $fillable = ['organization_id', 'invoice_id', 'payment_intent_change_id', 'payment_intent_id',
        'retired_payment_intent_id', 'reviewed_by', 'decision', 'reason', 'proposal_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var PaymentIntentChange|null $change */
            $change = PaymentIntentChange::query()->find($review->payment_intent_change_id);
            /** @var PaymentIntent|null $source */
            $source = PaymentIntent::query()->find($review->payment_intent_id);
            if ($change === null || $source === null || ! $review->hasValidEvidence($change, $source)) {
                throw new LogicException('Draft recovery review requires intact evidence and an independent reviewer.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Payment draft change reviews are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Payment draft review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'invoice_id' => 'integer', 'payment_intent_change_id' => 'integer',
            'payment_intent_id' => 'integer', 'retired_payment_intent_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    /** @return array<string, int|string|null> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'invoice_id' => $this->invoice_id,
            'change_id' => $this->payment_intent_change_id, 'payment_intent_id' => $this->payment_intent_id,
            'retired_payment_intent_id' => $this->retired_payment_intent_id, 'reviewed_by' => $this->reviewed_by,
            'decision' => $this->decision, 'reason' => $this->reason, 'proposal_digest' => $this->proposal_digest];
    }

    public function hasValidEvidence(PaymentIntentChange $change, PaymentIntent $source): bool
    {
        return $change->exists && $change->hasValidEvidence($source) && $change->id === $this->payment_intent_change_id
            && $this->payment_intent_id === $source->id && $this->organization_id === $source->organization_id
            && $this->invoice_id === $source->invoice_id && $this->reviewed_by > 0
            && $this->reviewed_by !== $change->proposed_by && $this->reviewed_by !== $source->prepared_by
            && trim($this->reason) !== '' && mb_strlen($this->reason) <= 1000
            && (($this->decision === 'approve_change' && $this->retired_payment_intent_id === $source->id)
                || ($this->decision === 'reject' && $this->retired_payment_intent_id === null))
            && hash_equals($change->content_digest, $this->proposal_digest)
            && hash_equals($this->review_digest, PaymentIntent::digest($this->content()));
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        /** @var PaymentIntent|null $successor */
        $successor = PaymentIntent::query()->where('change_review_id', $this->id)->first();

        return ['id' => $this->id, 'content' => $this->content(), 'review_digest' => $this->review_digest,
            'successor_id' => $successor?->id, 'successor_intent_key' => $successor?->intent_key,
            'payment_approved' => false, 'funds_reserved' => false, 'can_execute' => false];
    }
}
