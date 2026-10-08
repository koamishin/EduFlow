<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VendorDestinationVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $vendor_id
 * @property string $version
 * @property int $prepared_by
 * @property string $address
 * @property string $chain
 * @property int $chain_id
 * @property string $control_evidence
 * @property string $content_digest
 */
class VendorDestinationVersion extends Model
{
    /** @use HasFactory<VendorDestinationVersionFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'vendor_id', 'version', 'prepared_by', 'address', 'chain', 'chain_id', 'control_evidence', 'content_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $destination): void {
            /** @var Vendor|null $vendor */
            $vendor = Vendor::query()->find($destination->vendor_id);
            if ($vendor === null || $vendor->organization_id !== $destination->organization_id || ! $destination->hasValidContent()) {
                throw new LogicException('Vendor destination needs intact institution evidence and exact network identity.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Vendor destination versions are immutable; prepare a reviewed replacement.');
        });
        static::deleting(function (): never {
            throw new LogicException('Vendor destination evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'vendor_id' => 'integer', 'prepared_by' => 'integer', 'chain_id' => 'integer'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'vendor_id' => $this->vendor_id,
            'version' => $this->version, 'prepared_by' => $this->prepared_by, 'address' => $this->address,
            'chain' => $this->chain, 'chain_id' => $this->chain_id, 'control_evidence' => $this->control_evidence];
    }

    public function hasValidContent(): bool
    {
        return $this->organization_id > 0 && $this->vendor_id > 0 && $this->prepared_by > 0
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $this->version) === 1
            && preg_match('/^0x[0-9a-f]{40}$/D', $this->address) === 1 && $this->address !== '0x'.str_repeat('0', 40)
            && in_array([$this->chain, $this->chain_id], [['ARC', 5042], ['ARC-TESTNET', 5042002]], true)
            && trim($this->control_evidence) !== '' && mb_strlen($this->control_evidence) <= 255
            && hash_equals($this->content_digest, PaymentIntent::digest($this->content()));
    }
}
