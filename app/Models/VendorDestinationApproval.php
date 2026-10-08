<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $vendor_id
 * @property int $vendor_destination_version_id
 * @property int $approved_by
 * @property int|null $previous_approval_id
 * @property string|null $previous_approval_digest
 * @property string $verification_reference
 * @property string $destination_digest
 * @property string $approval_digest
 * @property VendorDestinationVersion|null $destinationVersion
 */
class VendorDestinationApproval extends Model
{
    protected $fillable = ['organization_id', 'vendor_id', 'vendor_destination_version_id', 'approved_by', 'previous_approval_id',
        'previous_approval_digest', 'verification_reference', 'destination_digest', 'approval_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            /** @var VendorDestinationVersion|null $destination */
            $destination = VendorDestinationVersion::query()->find($approval->vendor_destination_version_id);
            $current = self::current($approval->organization_id, $approval->vendor_id);
            if ($destination === null || ! $approval->hasValidEvidence($destination)
                || $current?->id !== $approval->previous_approval_id || $current?->approval_digest !== $approval->previous_approval_digest
                || ($current instanceof VendorDestinationApproval && ! $current->hasValidHistory())) {
                throw new LogicException('Destination approval needs intact history and a separate reviewer.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Vendor destination approvals are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Vendor destination approval evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'vendor_id' => 'integer', 'vendor_destination_version_id' => 'integer',
            'approved_by' => 'integer', 'previous_approval_id' => 'integer'];
    }

    /** @return array<string, int|string|null> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'vendor_id' => $this->vendor_id,
            'destination_version_id' => $this->vendor_destination_version_id, 'approved_by' => $this->approved_by,
            'previous_approval_id' => $this->previous_approval_id, 'previous_approval_digest' => $this->previous_approval_digest,
            'verification_reference' => $this->verification_reference, 'destination_digest' => $this->destination_digest];
    }

    public function hasValidEvidence(VendorDestinationVersion $destination): bool
    {
        return $destination->hasValidContent() && $destination->id === $this->vendor_destination_version_id
            && $destination->organization_id === $this->organization_id && $destination->vendor_id === $this->vendor_id
            && $this->approved_by > 0 && $this->approved_by !== $destination->prepared_by
            && trim($this->verification_reference) !== '' && mb_strlen($this->verification_reference) <= 255
            && (($this->previous_approval_id === null && $this->previous_approval_digest === null)
                || ($this->previous_approval_id > 0 && is_string($this->previous_approval_digest)
                    && preg_match('/^[0-9a-f]{64}$/D', $this->previous_approval_digest) === 1))
            && hash_equals($destination->content_digest, $this->destination_digest)
            && hash_equals($this->approval_digest, PaymentIntent::digest($this->content()));
    }

    public function hasValidHistory(): bool
    {
        if (! $this->exists) {
            return false;
        }
        /** @var Collection<int, self> $history */
        $history = self::query()->where('organization_id', $this->organization_id)->where('vendor_id', $this->vendor_id)
            ->where('id', '<=', $this->id)->with('destinationVersion')->orderBy('id')->limit(10_001)->get();
        if ($history->isEmpty() || $history->count() > 10_000) {
            return false;
        }
        $previous = null;
        foreach ($history as $approval) {
            $destination = $approval->destinationVersion;
            if ($destination === null || ! $approval->hasValidEvidence($destination)
                || $approval->previous_approval_id !== $previous?->id || $approval->previous_approval_digest !== $previous?->approval_digest) {
                return false;
            }
            $previous = $approval;
        }

        return $previous?->id === $this->id && hash_equals($previous->approval_digest, $this->approval_digest)
            && hash_equals($this->approval_digest, PaymentIntent::digest($this->content()));
    }

    /** @return BelongsTo<VendorDestinationVersion, $this> */
    public function destinationVersion(): BelongsTo
    {
        return $this->belongsTo(VendorDestinationVersion::class, 'vendor_destination_version_id');
    }

    public static function current(int $institutionId, int $vendorId): ?self
    {
        /** @var self|null $approval */
        $approval = self::query()->where('organization_id', $institutionId)->where('vendor_id', $vendorId)->orderByDesc('id')->first();

        return $approval;
    }
}
