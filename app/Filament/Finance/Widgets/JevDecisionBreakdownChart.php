<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Visual breakdown of JEV advisory decisions: Match, Needs Review, Hold, Exempt.
 */
class JevDecisionBreakdownChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'JEV Decision Breakdown';

    protected ?string $description = 'Distribution of advisory decisions across published institution policy categories.';

    protected ?string $emptyStateHeading = 'No policy evaluations recorded';

    protected ?string $emptyStateDescription = 'JEV decisions appear once cash collections, transactions, or budget snapshots are evaluated.';

    protected ?string $maxHeight = '280px';

    /** @var array<string, string> */
    protected array $colors = [
        'Match' => '#10b981',
        'Needs Review' => '#f59e0b',
        'Hold' => '#ef4444',
        'Exempt' => '#6366f1',
    ];

    #[\Override]
    protected function getType(): string
    {
        return 'doughnut';
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
                    'position' => 'bottom',
                ],
            ],
            'cutout' => '65%',
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
        $breakdown = $analytics->decisionBreakdown($institution->id);

        if ($breakdown['total'] === 0) {
            return [];
        }

        $labels = ['Match', 'Needs Review', 'Hold', 'Exempt'];
        $values = [
            $breakdown['match'],
            $breakdown['needs_review'],
            $breakdown['hold'],
            $breakdown['exempt'],
        ];

        // Filter out zero values so chart renders cleanly
        $filteredLabels = [];
        $filteredValues = [];
        $filteredColors = [];

        foreach ($labels as $index => $label) {
            if ($values[$index] > 0) {
                $filteredLabels[] = "{$label} ({$values[$index]})";
                $filteredValues[] = $values[$index];
                $filteredColors[] = $this->colors[$label];
            }
        }

        if ($filteredValues === []) {
            return [];
        }

        return [
            'labels' => $filteredLabels,
            'datasets' => [[
                'label' => 'Decisions',
                'data' => $filteredValues,
                'backgroundColor' => $filteredColors,
                'borderWidth' => 0,
            ]],
        ];
    }
}
