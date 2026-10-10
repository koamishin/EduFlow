<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Models\FinancePolicyActivation;
use App\Services\InstallationInstitution;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The bounded automatic lane, reported as it actually stands.
 *
 * An approved recurring mandate is a separate authorization from a per-item
 * payment approval, and this installation has neither: there is no mandate
 * record and no executor. The panel therefore shows the deterministic
 * ceilings that would govern a future mandate while reporting the automatic
 * outcome counts as the zeros they are, rather than as an empty or
 * reassuring-looking zero.
 *
 * Nothing here is linked: recurring mandates are approved through the finance
 * policy workflow, which has no screen yet. Pointing these stats at an
 * unrelated page would be worse than leaving them inert.
 */
class AutonomyLaneWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Automatic payment lane';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'Two authorization levels: staff first approve institution policy and any recurring mandate, and only then may eligible occurrences run without a further approval. Neither exists yet, so the initial allowance is zero. A model may propose or explain; it cannot infer that a bill is recurring.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Automatic lane', 'Not configured')
                ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
                ->color('warning')];
        }

        $activation = FinancePolicyActivation::current($institution->id);
        $policy = $activation?->policyVersion;

        $mandateStat = Stat::make('Recurring mandate', 'None approved')
            ->description('Zero allowance. No mandate owner, expiry, due window, frequency or cumulative limit is on record, so no occurrence can run unattended.')
            ->descriptionIcon(Heroicon::NoSymbol)
            ->color('gray');

        $perPayment = Stat::make('Per-payment ceiling', $policy !== null
            ? Money::formatExact($policy->max_auto_payment_base_units, 'USDC')
            : 'No active policy')
            ->description($policy !== null
                ? sprintf('Policy %s · activated %s. A ceiling, not an authorization.', $policy->version, $activation?->created_at?->toDateString() ?? 'unrecorded')
                : 'No policy activation exists. Every amount currently escalates to a human.')
            ->descriptionIcon(Heroicon::Scale)
            ->color($policy !== null ? 'info' : 'warning');

        $dailyStat = Stat::make('Daily disbursement limit', $policy !== null
            ? Money::formatExact($policy->max_daily_disbursement_base_units, 'USDC')
            : 'No active policy')
            ->description($policy !== null
                ? 'Counts settled spending and outstanding reservations together so concurrent proposals cannot reuse the same capacity.'
                : 'No cumulative limit is in force.')
            ->descriptionIcon(Heroicon::CalendarDays)
            ->color($policy !== null ? 'info' : 'warning');

        $feeStat = Stat::make('Fee ceiling', $policy !== null
            ? Money::formatExact($policy->max_fee_base_units, 'USDC')
            : 'No active policy')
            ->description('Bounded separately from principal; fees are preflighted before any submission.')
            ->descriptionIcon(Heroicon::Banknotes)
            ->color($policy !== null ? 'info' : 'warning');

        $outcomes = Stat::make('Automatic outcomes', 'Nothing automatic')
            ->description('0 auto-authorized · 0 submitted · 0 verified · 0 held. Automatic work stays visible here even when it needs no approval notification — today there is none to show.')
            ->descriptionIcon(Heroicon::SignalSlash)
            ->color('gray');

        return [$mandateStat, $perPayment, $dailyStat, $feeStat, $outcomes];
    }
}
