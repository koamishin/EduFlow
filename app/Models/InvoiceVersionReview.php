<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $invoice_version_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $reason
 * @property string $bill_digest
 * @property string $review_digest
 */
class InvoiceVersionReview extends Model
{
    protected $fillable = ['organization_id', 'invoice_version_id', 'reviewed_by', 'decision', 'reason', 'bill_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var InvoiceVersion|null $bill */
            $bill = InvoiceVersion::query()->whereKey($review->invoice_version_id)->first();
            if ($bill === null || ! $review->hasValidEvidence($bill)) {
                throw new LogicException('Evidence review needs intact source evidence and a separate reviewer.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Invoice evidence review is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Invoice evidence review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'invoice_version_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'invoice_version_id' => $this->invoice_version_id,
            'reviewed_by' => $this->reviewed_by, 'decision' => $this->decision, 'reason' => $this->reason, 'bill_digest' => $this->bill_digest];
    }

    public function hasValidEvidence(InvoiceVersion $bill): bool
    {
        return $bill->hasValidSnapshot() && $bill->id === $this->invoice_version_id && $bill->organization_id === $this->organization_id
            && $this->reviewed_by > 0 && $this->reviewed_by !== $bill->prepared_by
            && in_array($this->decision, ['approve_evidence', 'reject', 'hold'], true)
            && trim($this->reason) !== '' && mb_strlen($this->reason) <= 1000
            && hash_equals($bill->snapshot_digest, $this->bill_digest)
            && hash_equals($this->review_digest, PaymentIntent::digest($this->content()));
    }
}
