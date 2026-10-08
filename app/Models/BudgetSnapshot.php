<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $capture_key
 * @property int $organization_id
 * @property int $budget_id
 * @property int $prepared_by
 * @property string $currency
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 */
class BudgetSnapshot extends Model
{
    public const array AMOUNT_FIELDS = ['allocation', 'already_spent', 'other_budget_commitments', 'opening_funds',
        'realized_receipts', 'actual_outflows', 'restricted_cash', 'protected_reserve', 'other_cash_commitments'];

    protected $fillable = ['capture_key', 'organization_id', 'budget_id', 'prepared_by', 'currency', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $evidence): void {
            /** @var Budget|null $budget */
            $budget = Budget::query()->find($evidence->budget_id);
            if ($budget === null || $budget->organization_id !== $evidence->organization_id || ! $evidence->hasValidSnapshot()) {
                throw new LogicException('Budget evidence needs exact intact institution context.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Budget snapshots are immutable; capture new evidence.');
        });
        static::deleting(function (): never {
            throw new LogicException('Budget evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_id' => 'integer', 'prepared_by' => 'integer', 'snapshot' => 'array'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $content = $this->getAttribute('snapshot');
            if (! is_array($content) || array_is_list($content) || ! is_array($content['amounts'] ?? null)
                || ! is_array($content['bills'] ?? null) || ! array_is_list($content['bills'])
                || ! is_array($content['department'] ?? null) || ! is_array($content['evidence'] ?? null)) {
                return false;
            }
            if (($content['schema_version'] ?? null) !== 1 || ($content['purpose'] ?? null) !== 'department_budget_planning'
                || ($content['capture_key'] ?? null) !== $this->capture_key || ! Str::isUuid($this->capture_key)
                || $this->capture_key !== strtolower($this->capture_key)
                || ($content['institution_id'] ?? null) !== $this->organization_id || $this->organization_id < 1
                || ($content['budget_id'] ?? null) !== $this->budget_id || $this->budget_id < 1
                || ($content['prepared_by'] ?? null) !== $this->prepared_by || $this->prepared_by < 1
                || ($content['currency'] ?? null) !== $this->currency || CurrencyCode::tryFrom($this->currency) === null
                || ($content['commitments_exclude_selected_bills'] ?? null) !== true
                || ($content['cash_buckets_disjoint'] ?? null) !== true
                || ! is_string($content['budget_fingerprint'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $content['budget_fingerprint'])) {
                return false;
            }
            foreach (self::AMOUNT_FIELDS as $field) {
                $units = $content['amounts'][$field] ?? null;
                if (! is_string($units) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $units)
                    || BigInteger::of($units)->isGreaterThan(PHP_INT_MAX)) {
                    return false;
                }
            }
            foreach (['budget', 'cash', 'commitments'] as $field) {
                if (! is_string($content['evidence'][$field] ?? null) || trim($content['evidence'][$field]) === '') {
                    return false;
                }
            }
            if (! is_string($content['department']['name'] ?? null) || trim($content['department']['name']) === ''
                || count($content['bills']) < 1 || count($content['bills']) > 100) {
                return false;
            }
            $ids = [];
            foreach ($content['bills'] as $bill) {
                if (! is_array($bill) || ! is_int($bill['id'] ?? null) || $bill['id'] < 1 || in_array($bill['id'], $ids, true)
                    || ! is_string($bill['digest'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $bill['digest'])
                    || ! is_int($bill['review_id'] ?? null) || $bill['review_id'] < 1
                    || ! is_string($bill['review_digest'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $bill['review_digest'])) {
                    return false;
                }
                $ids[] = $bill['id'];
            }
            $asOf = Carbon::createFromFormat('!Y-m-d\TH:i:sP', $content['as_of']);
            $validUntil = Carbon::createFromFormat('!Y-m-d\TH:i:sP', $content['valid_until']);
            $start = Carbon::createFromFormat('!Y-m-d', $content['department']['period_start']);
            $end = Carbon::createFromFormat('!Y-m-d', $content['department']['period_end']);

            return $asOf instanceof Carbon && $validUntil instanceof Carbon && $start instanceof Carbon && $end instanceof Carbon
                && $asOf->lt($validUntil) && $start->lte($end)
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($content));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{budget_minor_units: string, cash_minor_units: string} */
    public function headroom(): array
    {
        if (! $this->hasValidSnapshot()) {
            throw new LogicException('Invalid budget evidence cannot supply planning headroom.');
        }
        $amounts = $this->snapshot['amounts'];
        $budget = BigInteger::of($amounts['allocation'])->minus($amounts['already_spent'])->minus($amounts['other_budget_commitments']);
        $cash = BigInteger::of($amounts['opening_funds'])->plus($amounts['realized_receipts'])->minus($amounts['actual_outflows'])
            ->minus($amounts['restricted_cash'])->minus($amounts['protected_reserve'])->minus($amounts['other_cash_commitments']);

        return ['budget_minor_units' => (string) $budget, 'cash_minor_units' => (string) $cash];
    }
}
