<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Breakdown of policy categories matched by JEV.
 */
class JevCategoryDistributionChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Matched Policy Categories';

    protected ?string $description = 'Volume and category distribution of successful JEV classifications.';

    protected ?string $emptyStateHeading = 'No category matches yet';

    protected ?string $emptyStateDescription = 'Categories will appear here once JEV matches evidence against published policy clauses.';

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
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
                'y' => [
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
        $breakdown = $analytics->decisionBreakdown($institution->id);

        if ($breakdown['match'] === 0 || $breakdown['categories'] === []) {
            return [];
        }

        $labels = [];
        $values = [];

        foreach ($breakdown['categories'] as $category) {
            $labels[] = "{$category['name']} ({$category['avg_confidence']}%)";
            $values[] = $category['count'];
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Matches',
                'data' => $values,
                'backgroundColor' => '#6366f1',
                'borderRadius' => 4,
            ]],
        ];
    }
}
