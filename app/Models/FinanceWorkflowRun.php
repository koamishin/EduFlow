<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $budget_snapshot_id
 * @property int|null $collection_batch_id
 * @property string $kind
 * @property string $trigger_key
 * @property string $source_digest
 * @property string $state
 * @property int $attempts
 * @property array<string, mixed>|null $result
 * @property string|null $result_digest
 * @property string|null $last_error
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $created_at
 * @property BudgetSnapshot|null $budgetSnapshot
 * @property CollectionBatch|null $collectionBatch
 */
class FinanceWorkflowRun extends Model
{
    protected $fillable = ['organization_id', 'budget_snapshot_id', 'collection_batch_id', 'kind', 'trigger_key', 'source_digest',
        'state', 'attempts', 'result', 'result_digest', 'last_error', 'heartbeat_at', 'next_attempt_at', 'dispatched_at'];

    protected $attributes = ['state' => 'queued', 'attempts' => 0];

    #[\Override]
    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->isDirty(['organization_id', 'budget_snapshot_id', 'collection_batch_id', 'kind', 'trigger_key', 'source_digest'])
                || ($run->getRawOriginal('result_digest') !== null && $run->isDirty(['result', 'result_digest']))) {
                throw new LogicException('Workflow identity and recorded planning results cannot be rewritten.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Finance workflow evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_snapshot_id' => 'integer', 'collection_batch_id' => 'integer', 'attempts' => 'integer',
            'result' => 'array', 'heartbeat_at' => 'datetime', 'next_attempt_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function hasValidSource(): bool
    {
        if ($this->kind === 'budget_plan') {
            $source = $this->budgetSnapshot;

            return $source !== null && $this->collection_batch_id === null && $source->organization_id === $this->organization_id
                && $this->trigger_key === 'budget:'.$source->id && $source->snapshot_digest === $this->source_digest && $source->hasValidSnapshot();
        }
        $source = $this->collectionBatch;

        return $this->kind === 'collection_review' && $source !== null && $this->budget_snapshot_id === null
            && $source->organization_id === $this->organization_id && $this->trigger_key === 'collection:'.$source->id
            && $source->snapshot_digest === $this->source_digest && $source->hasValidSnapshot();
    }

    public function hasValidResult(): bool
    {
        if (! is_array($this->result) || ! is_string($this->result_digest) || ($this->result['can_execute'] ?? null) !== false
            || ! $this->hasValidSource() || ! hash_equals($this->result_digest, PaymentIntent::digest($this->result))) {
            return false;
        }

        return $this->kind === 'budget_plan'
            ? ($this->result['snapshot_id'] ?? null) === $this->budget_snapshot_id && ($this->result['snapshot_digest'] ?? null) === $this->source_digest
            : ($this->result['collection_batch_id'] ?? null) === $this->collection_batch_id && ($this->result['source_digest'] ?? null) === $this->source_digest;
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function budgetSnapshot(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class);
    }

    /** @return BelongsTo<CollectionBatch, $this> */
    public function collectionBatch(): BelongsTo
    {
        return $this->belongsTo(CollectionBatch::class);
    }
}
