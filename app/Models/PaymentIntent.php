<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use Database\Factories\PaymentIntentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use TypeError;

/**
 * @property int $id
 * @property string $intent_key
 * @property int $organization_id
 * @property int $invoice_id
 * @property int $wallet_id
 * @property int $vendor_id
 * @property int|null $budget_id
 * @property int $prepared_by
 * @property int|null $finance_policy_version_id
 * @property int|null $finance_policy_activation_id
 * @property int|null $vendor_destination_version_id
 * @property int|null $vendor_destination_approval_id
 * @property int|null $invoice_version_id
 * @property int|null $invoice_version_review_id
 * @property string $portion
 * @property string $status
 * @property string $currency
 * @property int $amount_base_units
 * @property int $max_fee_base_units
 * @property string $chain
 * @property int $chain_id
 * @property string $source_address
 * @property string $recipient_address
 * @property string $provider_idempotency_key
 * @property string $snapshot_digest
 * @property array<string, mixed> $snapshot
 */
class PaymentIntent extends Model
{
    /** @use HasFactory<PaymentIntentFactory> */
    use HasFactory;

    protected $fillable = [
        'intent_key', 'organization_id', 'invoice_id', 'wallet_id', 'vendor_id',
        'budget_id', 'prepared_by', 'finance_policy_version_id', 'finance_policy_activation_id',
        'vendor_destination_version_id', 'vendor_destination_approval_id', 'invoice_version_id', 'invoice_version_review_id', 'portion', 'status', 'currency',
        'amount_base_units', 'max_fee_base_units', 'chain', 'chain_id',
        'source_address', 'recipient_address', 'provider_idempotency_key',
        'snapshot_digest', 'snapshot',
    ];

    protected $attributes = ['portion' => 'full', 'status' => 'draft', 'currency' => 'USDC'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $intent): void {
            if ($intent->status !== 'draft' || $intent->currency !== 'USDC' || $intent->portion !== 'full'
                || $intent->amount_base_units <= 0 || $intent->max_fee_base_units < 0 || ! $intent->hasValidSnapshot()) {
                throw new LogicException('Only valid, non-executable payment drafts may be created.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Payment intent drafts are immutable; changed documents need a reviewed successor workflow.');
        });
        static::deleting(function (): never {
            throw new LogicException('Payment intent evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer', 'invoice_id' => 'integer',
            'wallet_id' => 'integer', 'vendor_id' => 'integer', 'budget_id' => 'integer',
            'prepared_by' => 'integer', 'finance_policy_version_id' => 'integer', 'finance_policy_activation_id' => 'integer',
            'vendor_destination_version_id' => 'integer', 'vendor_destination_approval_id' => 'integer',
            'invoice_version_id' => 'integer', 'invoice_version_review_id' => 'integer', 'amount_base_units' => 'integer',
            'max_fee_base_units' => 'integer', 'chain_id' => 'integer', 'snapshot' => 'array',
        ];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $snapshot = $this->getAttribute('snapshot');

            if (! is_array($snapshot) || array_is_list($snapshot)) {
                return false;
            }

            foreach (['invoice', 'treasury', 'vendor', 'policy', 'vendor_destination'] as $section) {
                if (! isset($snapshot[$section]) || ! is_array($snapshot[$section]) || array_is_list($snapshot[$section])) {
                    return false;
                }
            }

            if (! array_key_exists('budget', $snapshot)
                || ($snapshot['budget'] !== null && (! is_array($snapshot['budget']) || array_is_list($snapshot['budget'])))) {
                return false;
            }

            return $this->matchesSnapshot($snapshot) && $this->hasValidPolicySnapshot($snapshot['policy'])
                            && $this->hasValidDestinationSnapshot($snapshot['vendor_destination']) && $this->hasValidInvoiceEvidence($snapshot);
        } catch (JsonException|TypeError) {
            return false;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function matchesSnapshot(array $snapshot): bool
    {
        return $this->status === 'draft'
            && $this->currency === 'USDC' && $this->portion === 'full'
            && $this->amount_base_units > 0 && $this->max_fee_base_units >= 0
            && $this->amount_base_units <= PHP_INT_MAX - $this->max_fee_base_units
            && Str::isUuid($this->intent_key) && $this->intent_key === strtolower($this->intent_key)
            && $this->provider_idempotency_key === 'eduflow:'.$this->intent_key
            && in_array([$this->chain, $this->chain_id], [['ARC', 5042], ['ARC-TESTNET', 5042002]], true)
            && preg_match('/^0x[0-9a-f]{40}$/D', $this->source_address) === 1
            && preg_match('/^0x[0-9a-f]{40}$/D', $this->recipient_address) === 1
            && $this->source_address !== '0x'.str_repeat('0', 40)
            && $this->recipient_address !== '0x'.str_repeat('0', 40)
            && $this->source_address !== $this->recipient_address
            && hash_equals($this->snapshot_digest, self::digest($snapshot))
            && ($this->snapshot['schema_version'] ?? null) === 1
            && ($this->snapshot['intent_key'] ?? null) === $this->intent_key
            && ($this->snapshot['prepared_by'] ?? null) === $this->prepared_by
            && ($this->snapshot['institution_id'] ?? null) === $this->organization_id
            && ($this->snapshot['invoice']['id'] ?? null) === $this->invoice_id
            && ($this->snapshot['invoice']['vendor_id'] ?? null) === $this->vendor_id
            && ($this->snapshot['invoice']['budget_id'] ?? null) === $this->budget_id
            && ($this->snapshot['invoice']['amount_base_units'] ?? null) === (string) $this->amount_base_units
            && ($this->snapshot['treasury']['wallet_id'] ?? null) === $this->wallet_id
            && ($this->snapshot['treasury']['source_address'] ?? null) === $this->source_address
            && ($this->snapshot['vendor']['id'] ?? null) === $this->vendor_id
            && ($this->snapshot['vendor']['recipient_address'] ?? null) === $this->recipient_address
            && ($this->snapshot['budget']['id'] ?? null) === $this->budget_id
            && ($this->snapshot['chain'] ?? null) === $this->chain
            && ($this->snapshot['chain_id'] ?? null) === $this->chain_id
            && ($this->snapshot['currency'] ?? null) === $this->currency
            && ($this->snapshot['portion'] ?? null) === $this->portion
            && ($this->snapshot['max_fee_base_units'] ?? null) === (string) $this->max_fee_base_units;
    }

    /** @param array<string, mixed> $policy */
    private function hasValidPolicySnapshot(array $policy): bool
    {
        $content = $policy['content'] ?? null;
        if (! is_array($content) || array_is_list($content) || ! is_string($policy['content_digest'] ?? null)
            || ! is_string($policy['activation_digest'] ?? null) || ! is_int($policy['approved_by'] ?? null)) {
            return false;
        }

        return $this->finance_policy_version_id > 0 && $this->finance_policy_activation_id > 0
            && ($policy['source'] ?? null) === 'finance_policy_version'
            && ($policy['version_id'] ?? null) === $this->finance_policy_version_id
            && ($policy['activation_id'] ?? null) === $this->finance_policy_activation_id
            && ($content['schema_version'] ?? null) === 1
            && ($content['institution_id'] ?? null) === $this->organization_id
            && ($content['currency'] ?? null) === $this->currency
            && is_int($content['created_by'] ?? null) && $content['created_by'] > 0
            && $policy['approved_by'] > 0 && $policy['approved_by'] !== $content['created_by']
            && preg_match('/^[0-9a-f]{64}$/D', $policy['activation_digest']) === 1
            && hash_equals($policy['content_digest'], self::digest($content));
    }

    /** @param array<string, mixed> $destination */
    private function hasValidDestinationSnapshot(array $destination): bool
    {
        $content = $destination['content'] ?? null;
        $approval = $destination['approval'] ?? null;
        if (! is_array($content) || ! is_array($approval) || ! is_string($destination['content_digest'] ?? null)
            || ! is_string($destination['approval_digest'] ?? null)) {
            return false;
        }

        return $this->vendor_destination_version_id > 0 && $this->vendor_destination_approval_id > 0
            && ($destination['version_id'] ?? null) === $this->vendor_destination_version_id
            && ($destination['approval_id'] ?? null) === $this->vendor_destination_approval_id
            && ($content['institution_id'] ?? null) === $this->organization_id && ($content['vendor_id'] ?? null) === $this->vendor_id
            && ($content['address'] ?? null) === $this->recipient_address && ($content['chain'] ?? null) === $this->chain
            && ($content['chain_id'] ?? null) === $this->chain_id
            && ($approval['destination_version_id'] ?? null) === $this->vendor_destination_version_id
            && ($approval['institution_id'] ?? null) === $this->organization_id && ($approval['vendor_id'] ?? null) === $this->vendor_id
            && is_int($approval['approved_by'] ?? null) && $approval['approved_by'] > 0
            && is_int($content['prepared_by'] ?? null) && $content['prepared_by'] > 0 && $approval['approved_by'] !== $content['prepared_by']
            && ($approval['destination_digest'] ?? null) === $destination['content_digest']
            && hash_equals($destination['content_digest'], self::digest($content))
            && hash_equals($destination['approval_digest'], self::digest($approval));
    }

    /** @param array<string, mixed> $snapshot */
    private function hasValidInvoiceEvidence(array $snapshot): bool
    {
        if (! array_key_exists('invoice_evidence', $snapshot)) {
            return false;
        }
        $evidence = $snapshot['invoice_evidence'];
        if ($evidence === null) {
            return $this->invoice_version_id === null && $this->invoice_version_review_id === null;
        }
        if (! is_array($evidence) || ! is_array($evidence['snapshot'] ?? null) || ! is_array($evidence['review'] ?? null)
            || ! is_string($evidence['snapshot_digest'] ?? null) || ! is_string($evidence['review_digest'] ?? null)) {
            return false;
        }
        $source = $evidence['snapshot'];
        $review = $evidence['review'];

        return $this->invoice_version_id > 0 && $this->invoice_version_review_id > 0
            && ($evidence['version_id'] ?? null) === $this->invoice_version_id && ($evidence['review_id'] ?? null) === $this->invoice_version_review_id
            && ($source['institution_id'] ?? null) === $this->organization_id && ($source['document']['invoice_id'] ?? null) === $this->invoice_id
            && ($source['document']['vendor_id'] ?? null) === $this->vendor_id && ($source['document']['budget_id'] ?? null) === $this->budget_id
            && ($source['source']['currency'] ?? null) === 'USDC' && ($source['source']['minor_units'] ?? null) === (string) $this->amount_base_units
            && ($review['invoice_version_id'] ?? null) === $this->invoice_version_id && ($review['decision'] ?? null) === 'approve_evidence'
            && ($review['institution_id'] ?? null) === $this->organization_id && ($review['bill_digest'] ?? null) === $evidence['snapshot_digest']
            && hash_equals($evidence['snapshot_digest'], self::digest($source)) && hash_equals($evidence['review_digest'], self::digest($review));
    }

    /** @param array<string, mixed> $snapshot */
    public static function digest(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonicalize($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'intent_key' => $this->intent_key,
            'institution_id' => $this->organization_id,
            'invoice_id' => $this->invoice_id,
            'finance_policy_version_id' => $this->finance_policy_version_id,
            'finance_policy_activation_id' => $this->finance_policy_activation_id,
            'status' => $this->status,
            'amount' => (new Money($this->amount_base_units, CurrencyCode::USDC))->jsonSerialize(),
            'max_fee' => (new Money($this->max_fee_base_units, CurrencyCode::USDC))->jsonSerialize(),
            'chain' => $this->chain,
            'chain_id' => $this->chain_id,
            'snapshot_digest' => $this->snapshot_digest,
            'snapshot_valid' => $this->hasValidSnapshot(),
            'approved' => false,
            'funds_reserved' => false,
            'can_execute' => false,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
