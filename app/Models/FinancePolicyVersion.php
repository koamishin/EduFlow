<?php

declare(strict_types=1);

namespace App\Models;

use Brick\Math\BigInteger;
use Database\Factories\FinancePolicyVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $version
 * @property int $created_by
 * @property string $currency
 * @property int $minimum_reserve_base_units
 * @property int $max_auto_payment_base_units
 * @property int $max_daily_disbursement_base_units
 * @property int $max_fee_base_units
 * @property string $content_digest
 */
class FinancePolicyVersion extends Model
{
    /** @use HasFactory<FinancePolicyVersionFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'version', 'created_by', 'currency', 'minimum_reserve_base_units', 'max_auto_payment_base_units', 'max_daily_disbursement_base_units', 'max_fee_base_units', 'content_digest'];

    protected $attributes = ['currency' => 'USDC', 'max_auto_payment_base_units' => 0, 'max_daily_disbursement_base_units' => 0, 'max_fee_base_units' => 0];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $policy): void {
            if (! $policy->hasValidContent()) {
                throw new LogicException('Finance policy content or exact monetary bounds are invalid.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Finance policy versions are immutable; create a new version.');
        });
        static::deleting(function (): never {
            throw new LogicException('Finance policy evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'created_by' => 'integer', 'minimum_reserve_base_units' => 'integer', 'max_auto_payment_base_units' => 'integer', 'max_daily_disbursement_base_units' => 'integer', 'max_fee_base_units' => 'integer'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return [
            'schema_version' => 1,
            'institution_id' => $this->organization_id,
            'version' => $this->version,
            'created_by' => $this->created_by,
            'currency' => $this->currency,
            'minimum_reserve_base_units' => (string) $this->minimum_reserve_base_units,
            'max_auto_payment_base_units' => (string) $this->max_auto_payment_base_units,
            'max_daily_disbursement_base_units' => (string) $this->max_daily_disbursement_base_units,
            'max_fee_base_units' => (string) $this->max_fee_base_units,
        ];
    }

    public function hasValidContent(): bool
    {
        foreach (['minimum_reserve_base_units', 'max_auto_payment_base_units', 'max_daily_disbursement_base_units', 'max_fee_base_units'] as $attribute) {
            $value = $this->getAttributes()[$attribute] ?? null;
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value)
                || BigInteger::of($value)->isGreaterThan(PHP_INT_MAX)) {
                return false;
            }
        }

        return $this->organization_id > 0 && $this->created_by > 0 && $this->currency === 'USDC'
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $this->version) === 1
            && $this->max_auto_payment_base_units <= $this->max_daily_disbursement_base_units
            && hash_equals($this->content_digest, PaymentIntent::digest($this->content()));
    }
}
