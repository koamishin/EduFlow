<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CollectionBatchReview;
use App\Models\FinancePlanReview;
use App\Models\FinanceWorkflowRun;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Invoice;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Analytics engine for JEV advisory decisions and admin approval telemetry.
 *
 * Provides aggregated metrics, concurrence rates, time-to-approval statistics,
 * and trend time series across institution policy evaluations and human review gates.
 */
final readonly class JevAnalyticsService
{
    public function __construct(private InstallationInstitution $institutions) {}

    /**
     * @return array{
     *     total: int,
     *     needs_review: int,
     *     needs_review_rate: float,
     *     match: int,
     *     match_rate: float,
     *     hold: int,
     *     hold_rate: float,
     *     exempt: int,
     *     exempt_rate: float,
     *     in_progress: int,
     *     avg_confidence: float,
     *     categories: array<string, array{id: string, name: string, count: int, avg_confidence: float}>
     * }
     */
    public function decisionBreakdown(?int $organizationId = null, string $period = 'all', ?string $sourceType = null): array
    {
        $evaluations = $this->queryEvaluations($organizationId, $period, $sourceType)->get();

        $total = $evaluations->count();
        if ($total === 0) {
            return [
                'total' => 0,
                'needs_review' => 0,
                'needs_review_rate' => 0.0,
                'match' => 0,
                'match_rate' => 0.0,
                'hold' => 0,
                'hold_rate' => 0.0,
                'exempt' => 0,
                'exempt_rate' => 0.0,
                'in_progress' => 0,
                'avg_confidence' => 0.0,
                'categories' => [],
            ];
        }

        $needsReview = 0;
        $matches = 0;
        $holds = 0;
        $exempts = 0;
        $inProgress = 0;
        $totalConfidence = 0.0;
        $confidenceCount = 0;
        $categoryCounts = [];

        foreach ($evaluations as $eval) {
            $decision = $this->classifyDecision($eval);

            match ($decision) {
                'needs_review' => $needsReview++,
                'match' => $matches++,
                'hold' => $holds++,
                'exempt' => $exempts++,
                'in_progress' => $inProgress++,
                default => $needsReview++,
            };

            $result = $eval->result;
            if (is_array($result) && isset($result['confidence']) && is_numeric($result['confidence'])) {
                $conf = (float) $result['confidence'];
                $totalConfidence += $conf;
                $confidenceCount++;
            }

            if ($decision === 'match' && is_array($result) && ! empty($result['category_id'])) {
                $catId = (string) $result['category_id'];
                $catName = (string) ($result['top_category_name'] ?? $catId);
                $conf = isset($result['confidence']) && is_numeric($result['confidence']) ? (float) $result['confidence'] : 1.0;

                if (! isset($categoryCounts[$catId])) {
                    $categoryCounts[$catId] = [
                        'id' => $catId,
                        'name' => $catName,
                        'count' => 0,
                        'total_confidence' => 0.0,
                    ];
                }

                $categoryCounts[$catId]['count']++;
                $categoryCounts[$catId]['total_confidence'] += $conf;
            }
        }

        $categories = [];
        foreach ($categoryCounts as $catId => $data) {
            $categories[$catId] = [
                'id' => $data['id'],
                'name' => $data['name'],
                'count' => $data['count'],
                'avg_confidence' => round(($data['total_confidence'] / $data['count']) * 100, 1),
            ];
        }

        uasort($categories, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total' => $total,
            'needs_review' => $needsReview,
            'needs_review_rate' => round(($needsReview / $total) * 100, 1),
            'match' => $matches,
            'match_rate' => round(($matches / $total) * 100, 1),
            'hold' => $holds,
            'hold_rate' => round(($holds / $total) * 100, 1),
            'exempt' => $exempts,
            'exempt_rate' => round(($exempts / $total) * 100, 1),
            'in_progress' => $inProgress,
            'avg_confidence' => $confidenceCount > 0 ? round(($totalConfidence / $confidenceCount) * 100, 1) : 0.0,
            'categories' => $categories,
        ];
    }

    /**
     * @return array{
     *     total_evaluated: int,
     *     total_reviewed: int,
     *     total_approved: int,
     *     total_rejected: int,
     *     total_held: int,
     *     pending_review: int,
     *     approval_rate: float,
     *     concurrence_count: int,
     *     concurrence_rate: float,
     *     override_count: int,
     *     override_rate: float,
     *     avg_time_to_approval_seconds: int,
     *     avg_time_to_approval_human: string,
     *     fastest_approval_seconds: int|null,
     *     slowest_approval_seconds: int|null
     * }
     */
    public function approvalAnalytics(?int $organizationId = null, string $period = 'all', ?string $sourceType = null): array
    {
        $evaluations = $this->queryEvaluations($organizationId, $period, $sourceType)->get();
        $totalEvaluated = $evaluations->count();

        if ($totalEvaluated === 0) {
            return [
                'total_evaluated' => 0,
                'total_reviewed' => 0,
                'total_approved' => 0,
                'total_rejected' => 0,
                'total_held' => 0,
                'pending_review' => 0,
                'approval_rate' => 0.0,
                'concurrence_count' => 0,
                'concurrence_rate' => 0.0,
                'override_count' => 0,
                'override_rate' => 0.0,
                'avg_time_to_approval_seconds' => 0,
                'avg_time_to_approval_human' => 'N/A',
                'fastest_approval_seconds' => null,
                'slowest_approval_seconds' => null,
            ];
        }

        $totalReviewed = 0;
        $totalApproved = 0;
        $totalRejected = 0;
        $totalHeld = 0;
        $pendingReview = 0;
        $concurrenceCount = 0;
        $overrideCount = 0;
        $timeDurations = [];

        foreach ($evaluations as $eval) {
            $approval = $this->resolveStaffApproval($eval);

            if (! $approval['reviewed']) {
                $pendingReview++;

                continue;
            }

            $totalReviewed++;

            match ($approval['status']) {
                'approved' => $totalApproved++,
                'rejected' => $totalRejected++,
                'held' => $totalHeld++,
                default => null,
            };

            if ($approval['concurrence'] === true) {
                $concurrenceCount++;
            } else {
                $overrideCount++;
            }

            if ($approval['time_to_approval_seconds'] !== null) {
                $timeDurations[] = $approval['time_to_approval_seconds'];
            }
        }

        $avgSeconds = $timeDurations !== [] ? (int) round(array_sum($timeDurations) / count($timeDurations)) : 0;
        $fastest = $timeDurations !== [] ? min($timeDurations) : null;
        $slowest = $timeDurations !== [] ? max($timeDurations) : null;

        return [
            'total_evaluated' => $totalEvaluated,
            'total_reviewed' => $totalReviewed,
            'total_approved' => $totalApproved,
            'total_rejected' => $totalRejected,
            'total_held' => $totalHeld,
            'pending_review' => $pendingReview,
            'approval_rate' => $totalReviewed > 0 ? round(($totalApproved / $totalReviewed) * 100, 1) : 0.0,
            'concurrence_count' => $concurrenceCount,
            'concurrence_rate' => $totalReviewed > 0 ? round(($concurrenceCount / $totalReviewed) * 100, 1) : 0.0,
            'override_count' => $overrideCount,
            'override_rate' => $totalReviewed > 0 ? round(($overrideCount / $totalReviewed) * 100, 1) : 0.0,
            'avg_time_to_approval_seconds' => $avgSeconds,
            'avg_time_to_approval_human' => $this->formatDuration($avgSeconds, $totalReviewed),
            'fastest_approval_seconds' => $fastest,
            'slowest_approval_seconds' => $slowest,
        ];
    }

    /**
     * @return array<string, array{
     *     date: string,
     *     label: string,
     *     matches: int,
     *     needs_review: int,
     *     holds: int,
     *     exempts: int,
     *     total: int,
     *     approved: int,
     *     overridden: int
     * }>
     */
    public function decisionTrends(?int $organizationId = null, int $days = 14, ?string $sourceType = null): array
    {
        $startDate = now()->subDays($days - 1)->startOfDay();
        $query = $this->queryEvaluations($organizationId, 'all', $sourceType)
            ->where('created_at', '>=', $startDate);

        $evaluations = $query->get();

        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $label = now()->subDays($i)->format('M d');
            $buckets[$day] = [
                'date' => $day,
                'label' => $label,
                'matches' => 0,
                'needs_review' => 0,
                'holds' => 0,
                'exempts' => 0,
                'total' => 0,
                'approved' => 0,
                'overridden' => 0,
            ];
        }

        foreach ($evaluations as $eval) {
            $day = $eval->created_at->toDateString();
            if (! isset($buckets[$day])) {
                continue;
            }

            $buckets[$day]['total']++;
            $decision = $this->classifyDecision($eval);

            match ($decision) {
                'match' => $buckets[$day]['matches']++,
                'needs_review' => $buckets[$day]['needs_review']++,
                'hold' => $buckets[$day]['holds']++,
                'exempt' => $buckets[$day]['exempts']++,
                default => null,
            };

            $approval = $this->resolveStaffApproval($eval);
            if ($approval['reviewed']) {
                if ($approval['status'] === 'approved') {
                    $buckets[$day]['approved']++;
                }
                if ($approval['is_override']) {
                    $buckets[$day]['overridden']++;
                }
            }
        }

        return $buckets;
    }

    /**
     * Classify an evaluation run into a canonical JEV decision state.
     */
    public function classifyDecision(InstitutionPolicyEvaluation $eval): string
    {
        if (in_array($eval->state, ['queued', 'running'], true)) {
            return 'in_progress';
        }

        $result = $eval->result;

        // 1. Budget allocation plan target decisions
        if ($eval->source_type === 'budget_snapshot' && is_array($result)) {
            $lines = $result['lines'] ?? [];
            if (is_array($lines) && $lines !== []) {
                $decisions = array_column($lines, 'decision');
                if (in_array('hold', $decisions, true) && ! in_array('propose_human_review', $decisions, true)) {
                    return 'hold';
                }
                if (in_array('exempt', $decisions, true) && ! in_array('propose_human_review', $decisions, true)) {
                    return 'exempt';
                }
                if (! in_array('hold', $decisions, true) && in_array('propose_human_review', $decisions, true)) {
                    return 'match';
                }

                return 'needs_review';
            }
        }

        // 2. Explicit needs_review flags or failure checkpoints
        if ($eval->state === 'needs_review' || (is_array($result) && ($result['needs_review'] ?? false) === true)) {
            $explanation = is_array($result) ? (string) ($result['explanation'] ?? '') : '';
            $explanationLower = strtolower($explanation);

            if (str_contains($explanationLower, 'conflict')
                || str_contains($explanationLower, 'safety threshold')
                || str_contains($explanationLower, 'held for human review')
                || str_contains($explanationLower, 'hold')) {
                return 'hold';
            }

            return 'needs_review';
        }

        // 3. Matched valid category
        if (is_array($result) && ! empty($result['category_id']) && $result['category_id'] !== 'needs_review') {
            if ($result['category_id'] === 'exempt') {
                return 'exempt';
            }

            return 'match';
        }

        return 'needs_review';
    }

    /**
     * Resolve whether staff reviewed the evaluation and concurred or overrode.
     *
     * @return array{
     *     reviewed: bool,
     *     status: 'approved'|'rejected'|'held'|'pending_review',
     *     decision: string|null,
     *     reviewer_id: int|null,
     *     reviewer_name: string|null,
     *     reviewed_at: CarbonInterface|null,
     *     time_to_approval_seconds: int|null,
     *     concurrence: bool|null,
     *     is_override: bool
     * }
     */
    public function resolveStaffApproval(InstitutionPolicyEvaluation $eval): array
    {
        // 1. Check Collection reviews
        if ($eval->source_type === 'collection') {
            /** @var CollectionBatchReview|null $review */
            $review = CollectionBatchReview::query()
                ->where('collection_batch_id', $eval->source_id)
                ->latest('id')
                ->first();

            if ($review !== null) {
                $isApproved = $review->decision === 'approve_receipts';
                $status = $isApproved ? 'approved' : ($review->decision === 'hold' ? 'held' : 'rejected');
                $jevDecision = $this->classifyDecision($eval);

                // Concurrence: JEV matched and staff approved, or JEV flagged hold/review and staff held/rejected
                $concurrence = ($jevDecision === 'match' && $isApproved)
                    || (in_array($jevDecision, ['needs_review', 'hold'], true) && ! $isApproved);

                $approvalTime = $review->created_at ?? now();
                $baselineTime = $eval->completed_at ?? $eval->created_at;
                $duration = (int) $approvalTime->diffInSeconds($baselineTime, true);

                $reviewer = User::query()->find($review->reviewed_by);

                return [
                    'reviewed' => true,
                    'status' => $status,
                    'decision' => $review->decision,
                    'reviewer_id' => $review->reviewed_by,
                    'reviewer_name' => $reviewer?->name,
                    'reviewed_at' => $review->created_at,
                    'time_to_approval_seconds' => $duration,
                    'concurrence' => $concurrence,
                    'is_override' => ! $concurrence,
                ];
            }
        }

        // 2. Check Budget Snapshot / Plan reviews
        if ($eval->source_type === 'budget_snapshot') {
            /** @var FinanceWorkflowRun|null $run */
            $run = FinanceWorkflowRun::query()
                ->where('organization_id', $eval->organization_id)
                ->where('budget_snapshot_id', $eval->source_id)
                ->latest('id')
                ->first();

            if ($run !== null) {
                /** @var FinancePlanReview|null $review */
                $review = FinancePlanReview::query()
                    ->where('finance_workflow_run_id', $run->id)
                    ->latest('id')
                    ->first();

                if ($review !== null) {
                    $isApproved = $review->decision === 'accept_plan';
                    $status = $isApproved ? 'approved' : ($review->decision === 'request_correction' ? 'held' : 'rejected');
                    $jevDecision = $this->classifyDecision($eval);

                    $concurrence = ($jevDecision === 'match' && $isApproved)
                        || (in_array($jevDecision, ['needs_review', 'hold'], true) && ! $isApproved);

                    $approvalTime = $review->created_at ?? now();
                    $baselineTime = $eval->completed_at ?? $eval->created_at;
                    $duration = (int) $approvalTime->diffInSeconds($baselineTime, true);

                    $reviewer = User::query()->find($review->reviewed_by);

                    return [
                        'reviewed' => true,
                        'status' => $status,
                        'decision' => $review->decision,
                        'reviewer_id' => $review->reviewed_by,
                        'reviewer_name' => $reviewer?->name,
                        'reviewed_at' => $review->created_at,
                        'time_to_approval_seconds' => $duration,
                        'concurrence' => $concurrence,
                        'is_override' => ! $concurrence,
                    ];
                }
            }
        }

        // 3. Check Transaction reviews and authorizations
        if ($eval->source_type === 'transaction') {
            /** @var Transaction|null $transaction */
            $transaction = Transaction::query()->find($eval->source_id);
            if ($transaction !== null) {
                /** @var PaymentAuthorization|null $auth */
                $auth = null;
                if ($transaction->reference_type === PaymentIntent::class) {
                    $auth = PaymentAuthorization::query()
                        ->where('payment_intent_id', $transaction->reference_id)
                        ->latest('id')
                        ->first();
                } elseif ($transaction->reference_type === Invoice::class) {
                    $auth = PaymentAuthorization::query()
                        ->where('invoice_id', $transaction->reference_id)
                        ->latest('id')
                        ->first();
                }

                if ($auth !== null) {
                    $isApproved = $auth->decision === 'approve_payment';
                    $status = $isApproved ? 'approved' : ($auth->decision === 'hold_payment' ? 'held' : 'rejected');
                    $jevDecision = $this->classifyDecision($eval);

                    $concurrence = ($jevDecision === 'match' && $isApproved)
                        || (in_array($jevDecision, ['needs_review', 'hold'], true) && ! $isApproved);

                    $approvalTime = $auth->created_at ?? now();
                    $baselineTime = $eval->completed_at ?? $eval->created_at;
                    $duration = (int) $approvalTime->diffInSeconds($baselineTime, true);

                    $reviewer = User::query()->find($auth->reviewed_by);

                    return [
                        'reviewed' => true,
                        'status' => $status,
                        'decision' => $auth->decision,
                        'reviewer_id' => $auth->reviewed_by,
                        'reviewer_name' => $reviewer?->name,
                        'reviewed_at' => $auth->created_at,
                        'time_to_approval_seconds' => $duration,
                        'concurrence' => $concurrence,
                        'is_override' => ! $concurrence,
                    ];
                }
            }
        }

        return [
            'reviewed' => false,
            'status' => 'pending_review',
            'decision' => null,
            'reviewer_id' => null,
            'reviewer_name' => null,
            'reviewed_at' => null,
            'time_to_approval_seconds' => null,
            'concurrence' => null,
            'is_override' => false,
        ];
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     source_type: string,
     *     source_id: int,
     *     label: string,
     *     reason: string,
     *     jev_decision: string,
     *     category_name: string|null,
     *     confidence_percent: int|null,
     *     staff_status: string,
     *     reviewer_name: string|null,
     *     time_to_approval: string|null,
     *     concurrence: bool|null,
     *     is_override: bool,
     *     created_at: CarbonInterface|null
     * }>
     */
    public function recentAuditLog(?int $organizationId = null, int $limit = 20): Collection
    {
        $evaluations = $this->queryEvaluations($organizationId, 'all')
            ->latest('id')
            ->limit($limit)
            ->get();

        return $evaluations->map(function (InstitutionPolicyEvaluation $eval): array {
            $jevDecision = $this->classifyDecision($eval);
            $approval = $this->resolveStaffApproval($eval);
            $result = $eval->result;

            $confidence = is_array($result) && isset($result['confidence']) && is_numeric($result['confidence'])
                ? (int) round(((float) $result['confidence']) * 100)
                : null;

            $catName = is_array($result)
                ? ($result['top_category_name'] ?? $result['category_id'] ?? null)
                : null;

            $timeHuman = $approval['time_to_approval_seconds'] !== null
                ? $this->formatSeconds($approval['time_to_approval_seconds'])
                : null;

            return [
                'id' => $eval->id,
                'source_type' => $eval->source_type,
                'source_id' => $eval->source_id,
                'label' => $eval->input['label'] ?? ucfirst($eval->source_type).' #'.$eval->source_id,
                'reason' => $eval->reason,
                'jev_decision' => $jevDecision,
                'category_name' => $catName,
                'confidence_percent' => $confidence,
                'staff_status' => $approval['status'],
                'reviewer_name' => $approval['reviewer_name'],
                'time_to_approval' => $timeHuman,
                'concurrence' => $approval['concurrence'],
                'is_override' => $approval['is_override'],
                'created_at' => $eval->created_at,
            ];
        });
    }

    /**
     * @return Builder<InstitutionPolicyEvaluation>
     */
    private function queryEvaluations(?int $organizationId = null, string $period = 'all', ?string $sourceType = null): Builder
    {
        $orgId = $organizationId ?? $this->institutions->current()?->id;

        $query = InstitutionPolicyEvaluation::query();

        if ($orgId !== null) {
            $query->where('organization_id', $orgId);
        }

        if ($sourceType !== null && in_array($sourceType, ['collection', 'transaction', 'budget_snapshot'], true)) {
            $query->where('source_type', $sourceType);
        }

        return match ($period) {
            'today' => $query->where('created_at', '>=', now()->startOfDay()),
            '7_days' => $query->where('created_at', '>=', now()->subDays(7)->startOfDay()),
            '14_days' => $query->where('created_at', '>=', now()->subDays(14)->startOfDay()),
            '30_days' => $query->where('created_at', '>=', now()->subDays(30)->startOfDay()),
            default => $query,
        };
    }

    private function formatDuration(int $seconds, int $count): string
    {
        if ($count === 0 || $seconds === 0) {
            return $count === 0 ? 'N/A (No reviews)' : '< 1 min';
        }

        return $this->formatSeconds($seconds);
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = round($minutes / 60, 1);
        if ($hours < 24) {
            return $hours.'h';
        }

        $days = round($hours / 24, 1);

        return $days.'d';
    }
}
