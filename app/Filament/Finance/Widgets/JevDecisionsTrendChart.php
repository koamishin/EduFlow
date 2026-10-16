<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Daily trend of JEV decisions and staff approvals over the last 14 days.
 */
class JevDecisionsTrendChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = '14-Day Evaluation & Approval Trends';

    protected ?string $description = 'Daily volume of JEV matches, safety holds, and human approvals.';

    protected ?string $emptyStateHeading = 'No recent evaluation trends';

    protected ?string $emptyStateDescription = 'Evaluations over the past 14 days will appear here as time trends.';

    protected ?string $maxHeight = '280px';

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'y' => [
                    'stacked' => true,
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
                'x' => [
                    'stacked' => true,
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getData(): array
    {
        $institution = app(InstallationInstitution::class)->current();
        if ($institution === null) {
            return [];
        }

        $analytics = app(JevAnalyticsService::class);
        $trends = $analytics->decisionTrends($institution->id, 14);

        $labels = [];
        $matches = [];
        $needsReview = [];
        $holds = [];
        $approvals = [];

        $hasData = false;
        foreach ($trends as $bucket) {
            $labels[] = $bucket['label'];
            $matches[] = $bucket['matches'];
            $needsReview[] = $bucket['needs_review'];
            $holds[] = $bucket['holds'];
            $approvals[] = $bucket['approved'];

            if ($bucket['total'] > 0 || $bucket['approved'] > 0) {
                $hasData = true;
            }
        }

        if (! $hasData) {
            return [];
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Matches',
                    'backgroundColor' => '#10b981',
                    'data' => $matches,
                    'borderRadius' => 2,
                ],
                [
                    'label' => 'Needs Review',
                    'backgroundColor' => '#f59e0b',
                    'data' => $needsReview,
                    'borderRadius' => 2,
                ],
                [
                    'label' => 'Safety Holds',
                    'backgroundColor' => '#ef4444',
                    'data' => $holds,
                    'borderRadius' => 2,
                ],
                [
                    'label' => 'Staff Approved',
                    'backgroundColor' => '#38bdf8',
                    'data' => $approvals,
                    'borderRadius' => 2,
                ],
            ],
        ];
    }
}
