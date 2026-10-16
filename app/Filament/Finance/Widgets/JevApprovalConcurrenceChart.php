<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Chart showing Admin / Staff approval actions and concurrence vs manual overrides.
 */
class JevApprovalConcurrenceChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Staff Approval & Concurrence';

    protected ?string $description = 'How often staff approves JEV decisions and whether staff concurred with JEV assessment.';

    protected ?string $emptyStateHeading = 'No staff reviews recorded';

    protected ?string $emptyStateDescription = 'Staff approvals appear once independent reviewers approve or modify collections and plans.';

    protected ?string $maxHeight = '280px';

    /** @var array<string, string> */
    protected array $colors = [
        'Staff Concurred' => '#10b981',
        'Manual Override' => '#f59e0b',
        'Staff Rejected' => '#ef4444',
        'Pending Review' => '#a1a1aa',
    ];

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
                    'display' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
                'x' => [
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
        $approvals = $analytics->approvalAnalytics($institution->id);

        if ($approvals['total_evaluated'] === 0) {
            return [];
        }

        $items = [
            'Staff Concurred' => $approvals['concurrence_count'],
            'Manual Override' => $approvals['override_count'],
            'Staff Rejected' => $approvals['total_rejected'],
            'Pending Review' => $approvals['pending_review'],
        ];

        $labels = [];
        $values = [];
        $backgrounds = [];

        foreach ($items as $label => $val) {
            if ($val > 0) {
                $labels[] = "{$label} ({$val})";
                $values[] = $val;
                $backgrounds[] = $this->colors[$label];
            }
        }

        if ($values === []) {
            return [];
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Reviews',
                'data' => $values,
                'backgroundColor' => $backgrounds,
                'borderRadius' => 4,
            ]],
        ];
    }
}
