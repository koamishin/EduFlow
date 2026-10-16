<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class JevDecisionSummaryWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '15s';

    #[\Override]
    protected function getHeading(): ?string
    {
        return 'JEV Advisory & Admin Approval Telemetry';
    }

    #[\Override]
    protected function getDescription(): ?string
    {
        return 'Telemetry on JEV policy evaluations, fail-closed safety holds, and staff concurrence rates under dual-custody governance.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();
        if ($institution === null) {
            return [];
        }

        $analytics = app(JevAnalyticsService::class);
        $breakdown = $analytics->decisionBreakdown($institution->id);
        $approvals = $analytics->approvalAnalytics($institution->id);

        $totalEvaluations = $breakdown['total'];
        $needsReviewCount = $breakdown['needs_review'] + $breakdown['hold'];
        $needsReviewRate = $totalEvaluations > 0
            ? round(($needsReviewCount / $totalEvaluations) * 100, 1)
            : 0.0;

        return [
            Stat::make('Total JEV Evaluations', (string) $totalEvaluations)
                ->description($breakdown['match'].' matches · '.$breakdown['in_progress'].' in-progress')
                ->color('gray'),

            Stat::make('Needs Review & Holds', $needsReviewCount.' ('.$needsReviewRate.'%)')
                ->description($breakdown['hold'].' safety holds · '.$breakdown['needs_review'].' ambiguous')
                ->color($needsReviewCount > 0 ? 'warning' : 'success'),

            Stat::make('Policy Matches', $breakdown['match'].' ('.$breakdown['match_rate'].'%)')
                ->description('Avg confidence: '.$breakdown['avg_confidence'].'%')
                ->color('success'),

            Stat::make('Staff Approval Rate', $approvals['approval_rate'].'%')
                ->description($approvals['total_approved'].' approved of '.$approvals['total_reviewed'].' reviewed')
                ->color($approvals['approval_rate'] >= 80 ? 'success' : 'warning'),

            Stat::make('Staff Concurrence Rate', $approvals['concurrence_rate'].'%')
                ->description($approvals['concurrence_count'].' agreed · '.$approvals['override_count'].' overrides')
                ->color($approvals['concurrence_rate'] >= 80 ? 'success' : 'warning'),

            Stat::make('Avg Time to Approval', $approvals['avg_time_to_approval_human'])
                ->description($approvals['pending_review'].' currently awaiting review')
                ->color('gray'),
        ];
    }
}
