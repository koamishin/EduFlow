<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FinancePlanReview;
use App\Models\FinanceWorkflowRun;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\DepartmentBudgetPlanner;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ReviewFinancePlan
{
    public function __construct(private InstallationInstitution $institutions, private DepartmentBudgetPlanner $planner) {}

    public function handle(User $reviewer, FinanceWorkflowRun $run, string $expectedDigest, string $decision, string $reason): FinancePlanReview
    {
        Gate::forUser($reviewer)->authorize('view', $run);
        if (! in_array($decision, ['accept_plan', 'reject_plan', 'request_correction'], true) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['decision' => 'Explicit plan decision and reason required. This cannot authorize payment.']);
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $run, $expectedDigest, $decision, $reason, $institution): FinancePlanReview {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var FinanceWorkflowRun $stored */
            $stored = FinanceWorkflowRun::query()->where('organization_id', $institution->id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            /** @var FinancePlanReview|null $existing */
            $existing = FinancePlanReview::query()->where('finance_workflow_run_id', $stored->id)->first();
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($stored) || $existing->reviewed_by !== $reviewer->id || $existing->decision !== $decision
                    || $existing->reason !== $reason || $existing->plan_digest !== $expectedDigest) {
                    throw ValidationException::withMessages(['decision' => 'Plan review already recorded with different evidence or feedback.']);
                }

                return $existing;
            }
            Gate::forUser($reviewer)->authorize('review', $stored);
            if (! $stored->hasValidResult() || $stored->result_digest !== $expectedDigest || $stored->budgetSnapshot === null
                || ! hash_equals($expectedDigest, PaymentIntent::digest($this->planner->handle($reviewer, $stored->budgetSnapshot)))) {
                throw ValidationException::withMessages(['expected_digest' => 'Exact proposal or current evidence changed; capture and review a fresh plan.']);
            }
            $review = new FinancePlanReview(['organization_id' => $institution->id, 'finance_workflow_run_id' => $stored->id,
                'reviewed_by' => $reviewer->id, 'decision' => $decision, 'reason' => $reason, 'plan_digest' => $expectedDigest]);
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();
            $stored->update(['state' => 'completed', 'next_attempt_at' => null]);
            activity('finance')->causedBy($reviewer)->performedOn($review)->event('finance_plan_reviewed')
                ->withProperties(['run_id' => $stored->id, 'decision' => $decision, 'payment_approved' => false, 'can_execute' => false])
                ->log('Background budget proposal reviewed; no payment, reservation or allocation authority');

            return $review;
        }, 3);
    }
}
