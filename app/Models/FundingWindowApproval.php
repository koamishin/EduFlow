<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $funding_window_id
 * @property int $approved_by
 * @property string $verification_reference
 * @property string $window_digest
 * @property string $approval_digest
 */
class FundingWindowApproval extends Model
{
    protected $fillable = ['organization_id', 'funding_window_id', 'approved_by', 'verification_reference', 'window_digest', 'approval_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            /** @var FundingWindow|null $window */
            $window = FundingWindow::query()->find($approval->funding_window_id);
            if ($window === null || ! $approval->hasValidEvidence($window)) {
                throw new LogicException('Funding approval requires independent review of intact exact evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Funding approvals are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Funding approval evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'funding_window_id' => 'integer', 'approved_by' => 'integer'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'funding_window_id' => $this->funding_window_id,
            'approved_by' => $this->approved_by, 'verification_reference' => $this->verification_reference, 'window_digest' => $this->window_digest];
    }

    public function hasValidEvidence(FundingWindow $window): bool
    {
        /** @var BudgetSnapshot|null $budget */
        $budget = BudgetSnapshot::query()->find($window->budget_snapshot_id);

        return $window->exists && $window->hasValidSnapshot() && $window->id === $this->funding_window_id
            && $window->organization_id === $this->organization_id && $budget !== null && $budget->hasValidSnapshot()
            && $budget->organization_id === $this->organization_id && $budget->budget_id === $window->budget_id
            && ($window->snapshot['budget_snapshot_digest'] ?? null) === $budget->snapshot_digest && $this->approved_by > 0
            && $this->approved_by !== $window->prepared_by && $this->approved_by !== $budget->prepared_by
            && trim($this->verification_reference) !== '' && mb_strlen($this->verification_reference) <= 255
            && hash_equals($window->snapshot_digest, $this->window_digest)
            && hash_equals($this->approval_digest, PaymentIntent::digest($this->content()));
    }
}
