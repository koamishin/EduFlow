<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Finance\Widgets\JevApprovalConcurrenceChart;
use App\Filament\Finance\Widgets\JevCategoryDistributionChart;
use App\Filament\Finance\Widgets\JevDecisionBreakdownChart;
use App\Filament\Finance\Widgets\JevDecisionsTrendChart;
use App\Filament\Finance\Widgets\JevDecisionSummaryWidget;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class JevAnalyticsDashboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Financial Operations';

    protected static ?string $navigationLabel = 'JEV Analytics Dashboard';

    protected static ?string $title = 'JEV Advisory & Admin Approval Analytics';

    protected static ?string $slug = 'advisory-analytics';

    protected static ?int $navigationSort = 2;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.jev-analytics-dashboard';

    #[\Override]
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        if (Gate::forUser($user)->allows('viewAny', InstitutionPolicyEvaluation::class)) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'super_admin', 'finance_officer', 'finance_supervisor']);
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return 'Institution context unavailable — run app:install.';
        }

        return sprintf(
            '%s · JEV advisory decisions, safety holds, and dual-custody admin concurrence rates',
            $institution->name,
        );
    }

    /**
     * @return array<class-string>
     */
    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            JevDecisionSummaryWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    #[\Override]
    protected function getFooterWidgets(): array
    {
        return [
            JevDecisionBreakdownChart::class,
            JevApprovalConcurrenceChart::class,
            JevDecisionsTrendChart::class,
            JevCategoryDistributionChart::class,
        ];
    }

    #[\Override]
    public function getFooterWidgetsColumns(): int|array
    {
        return 2;
    }

    /**
     * @return array<Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('evaluations')
                ->label('Live Policy Evaluations')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('finance.policy-evaluations.index')),

            Action::make('spaAnalytics')
                ->label('Interactive Telemetry View')
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray')
                ->url(fn (): string => route('finance.jev-analytics')),

            Action::make('financeSupervisor')
                ->label('Finance Supervisor')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->url(fn (): string => FinanceSupervisor::getUrl(panel: 'finance')),
        ];
    }

    public function table(Table $table): Table
    {
        $analytics = app(JevAnalyticsService::class);

        return $table
            ->heading('Recent JEV Advisory Evaluations & Staff Audit Trail')
            ->description('Every policy evaluation assessed by JEV, including safety holds and subsequent independent human reviews.')
            ->query($this->getTableQuery())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#ID')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Evaluated at')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),

                TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'collection' => 'Collection Batch',
                        'transaction' => 'Ledger Tx',
                        'budget_snapshot' => 'Budget Snapshot',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'collection' => 'info',
                        'transaction' => 'primary',
                        'budget_snapshot' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('input.label')
                    ->label('Evidence Context')
                    ->state(fn (InstitutionPolicyEvaluation $record): string => $record->input['label'] ?? (ucwords(str_replace('_', ' ', (string) $record->source_type)).' #'.$record->source_id))
                    ->limit(35)
                    ->tooltip(fn (InstitutionPolicyEvaluation $record): string => $record->input['label'] ?? $record->reason)
                    ->description(fn (InstitutionPolicyEvaluation $record): string => $record->reason),

                TextColumn::make('jev_decision')
                    ->label('JEV Decision')
                    ->state(fn (InstitutionPolicyEvaluation $record): string => $analytics->classifyDecision($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'match' => 'Match',
                        'needs_review' => 'Needs Review',
                        'hold' => 'Safety Hold',
                        'exempt' => 'Exempt',
                        'in_progress' => 'In Progress',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'match' => 'success',
                        'needs_review' => 'warning',
                        'hold' => 'danger',
                        'exempt' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('category_name')
                    ->label('Policy Category / Confidence')
                    ->state(function (InstitutionPolicyEvaluation $record): string {
                        $result = $record->result;
                        if (! is_array($result)) {
                            return '—';
                        }
                        $cat = $result['top_category_name'] ?? $result['category_id'] ?? null;
                        if ($cat === null && isset($result['lines']) && is_array($result['lines'])) {
                            $categories = array_values(array_filter(array_unique(array_column($result['lines'], 'category_id'))));
                            if ($categories !== []) {
                                $cat = implode(', ', $categories);
                            }
                        }
                        $conf = isset($result['confidence']) && is_numeric($result['confidence'])
                            ? round(((float) $result['confidence']) * 100).'%'
                            : null;

                        if ($cat && $conf) {
                            return "{$cat} ({$conf})";
                        }
                        if ($cat) {
                            return (string) $cat;
                        }

                        return 'Needs Human Review';
                    }),

                TextColumn::make('staff_status')
                    ->label('Admin Status')
                    ->state(fn (InstitutionPolicyEvaluation $record): string => $analytics->resolveStaffApproval($record)['status'])
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Staff Approved',
                        'rejected' => 'Staff Rejected',
                        'held' => 'Held by Staff',
                        'pending_review' => 'Awaiting Review',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'held' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('concurrence')
                    ->label('Concurrence')
                    ->state(function (InstitutionPolicyEvaluation $record) use ($analytics): string {
                        $approval = $analytics->resolveStaffApproval($record);
                        if (! $approval['reviewed']) {
                            return 'Pending';
                        }

                        return $approval['concurrence'] ? 'Concurred' : 'Override';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Concurred' => 'success',
                        'Override' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('time_to_approval')
                    ->label('Time to Review')
                    ->state(function (InstitutionPolicyEvaluation $record) use ($analytics): string {
                        $approval = $analytics->resolveStaffApproval($record);
                        if (! $approval['reviewed'] || $approval['time_to_approval_seconds'] === null) {
                            return '—';
                        }

                        $seconds = $approval['time_to_approval_seconds'];
                        if ($seconds < 60) {
                            return "{$seconds}s";
                        }
                        $mins = (int) round($seconds / 60);
                        if ($mins < 60) {
                            return "{$mins}m";
                        }

                        return round($mins / 60, 1).'h';
                    }),
            ])
            ->filters([
                SelectFilter::make('source_type')
                    ->label('Source Type')
                    ->options([
                        'collection' => 'Collection Batch',
                        'transaction' => 'Ledger Transaction',
                        'budget_snapshot' => 'Budget Snapshot',
                    ]),

                SelectFilter::make('state')
                    ->label('State')
                    ->options([
                        'completed' => 'Completed',
                        'needs_review' => 'Needs Review',
                        'running' => 'Running',
                        'queued' => 'Queued',
                    ]),
            ]);
    }

    protected function getTableQuery(): Builder
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return InstitutionPolicyEvaluation::query()->whereRaw('1 = 0');
        }

        return InstitutionPolicyEvaluation::query()
            ->where('organization_id', $institution->id);
    }
}
