<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $finance_workflow_run_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $reason
 * @property string $plan_digest
 * @property string $review_digest
 */
class FinancePlanReview extends Model
{
    protected $fillable = ['organization_id', 'finance_workflow_run_id', 'reviewed_by', 'decision', 'reason', 'plan_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var FinanceWorkflowRun|null $run */
            $run = FinanceWorkflowRun::query()->find($review->finance_workflow_run_id);
            if ($run === null || ! $review->hasValidEvidence($run)) {
                throw new LogicException('Finance plan review requires intact non-executable proposal and independent staff.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Finance plan reviews are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Finance plan review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'finance_workflow_run_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'run_id' => $this->finance_workflow_run_id,
            'reviewed_by' => $this->reviewed_by, 'decision' => $this->decision, 'reason' => $this->reason, 'plan_digest' => $this->plan_digest];
    }

    public function hasValidEvidence(FinanceWorkflowRun $run): bool
    {
        return $run->hasValidResult() && $run->id === $this->finance_workflow_run_id && $run->organization_id === $this->organization_id
            && $run->budgetSnapshot !== null && $this->reviewed_by > 0 && $this->reviewed_by !== $run->budgetSnapshot->prepared_by
            && in_array($this->decision, ['accept_plan', 'reject_plan', 'request_correction'], true) && trim($this->reason) !== ''
            && mb_strlen($this->reason) <= 1000 && $this->plan_digest === $run->result_digest
            && hash_equals($this->review_digest, PaymentIntent::digest($this->content()));
    }
}
