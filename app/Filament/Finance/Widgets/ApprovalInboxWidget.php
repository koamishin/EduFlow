<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Pages\PaymentReviews;
use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Services\InstallationInstitution;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * One inbox for every decision that is waiting on a human.
 *
 * Cashier receipt review, plan acceptance and payment authorization are
 * different acts held by different people, so they are listed separately and
 * never merged into a single "pending approvals" count that could be read as
 * one authority. Reading this panel approves nothing.
 */
class ApprovalInboxWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.finance.widgets.approval-inbox';

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', FinanceWorkflowRun::class);
    }

    #[\Override]
    protected function getViewData(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [
                'institutionMissing' => true,
                'collections' => [],
                'plans' => [],
                'payments' => [],
                'queues' => [],
            ];
        }

        return [
            'institutionMissing' => false,
            'collections' => $this->collectionItems($institution->id),
            'plans' => $this->planItems($institution->id),
            'payments' => $this->paymentItems($institution->id),
            'queues' => [
                'collections' => Collections::getUrl(panel: 'finance'),
                'plans' => FinanceSupervisor::getUrl(panel: 'finance'),
                'payments' => PaymentReviews::getUrl(panel: 'finance'),
            ],
        ];
    }

    /** @return list<array<string, string|int|null>> */
    private function collectionItems(int $institutionId): array
    {
        return CollectionBatch::query()
            ->where('organization_id', $institutionId)
            ->whereDoesntHave('reviews')
            ->with('preparer')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (CollectionBatch $batch): array => [
                'id' => $batch->id,
                'sourceStream' => $batch->source_stream,
                'sourceReference' => $batch->source_reference,
                'currency' => $batch->currency,
                'received' => Money::formatExact($batch->received_minor_units, $batch->currency),
                'restricted' => Money::formatExact($batch->restricted_minor_units, $batch->currency),
                'interval' => $this->interval($batch),
                'owner' => $batch->preparer?->name ?? 'Unknown',
                'age' => $this->age($batch->created_at),
                'decision' => null,
                'url' => Collections::getUrl(panel: 'finance'),
            ])
            ->all();
    }

    /** @return list<array<string, string|int|null>> */
    private function planItems(int $institutionId): array
    {
        return FinanceWorkflowRun::query()
            ->where('organization_id', $institutionId)
            ->where('state', 'waiting_for_review')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (FinanceWorkflowRun $run): array => [
                'id' => $run->id,
                'kind' => $run->kind,
                'trigger' => $run->trigger_key,
                'currency' => (string) ($run->result['currency'] ?? ''),
                'budgetHeadroom' => Money::formatExact(data_get($run->result, 'headroom.budget_minor_units'), $run->result['currency'] ?? null),
                'cashHeadroom' => Money::formatExact(data_get($run->result, 'headroom.cash_minor_units'), $run->result['currency'] ?? null),
                'bills' => count($run->result['bills'] ?? []),
                'digest' => $run->source_digest,
                'age' => $this->age($run->created_at),
                'url' => FinanceSupervisor::getUrl(panel: 'finance'),
            ])
            ->all();
    }

    /** @return list<array<string, string|int|null>> */
    private function paymentItems(int $institutionId): array
    {
        return PaymentReservation::query()
            ->where('organization_id', $institutionId)
            ->whereDoesntHave('authorization')
            ->with(['intent.invoice', 'intent.preparer', 'intent.financePolicyVersion', 'intent.vendorDestinationVersion'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (PaymentReservation $reservation): array {
                /** @var PaymentIntent $intent */
                $intent = $reservation->intent;
                $invoice = $intent->invoice;

                return [
                    'id' => $reservation->id,
                    'intentId' => $intent->id,
                    'reference' => $invoice?->reference ?? 'Invoice #'.$intent->invoice_id,
                    'amount' => Money::formatExact($reservation->amount_base_units, $intent->currency),
                    'fee' => Money::formatExact($reservation->max_fee_base_units, $intent->currency),
                    'dueDate' => $invoice?->due_date?->toDateString() ?? 'Not recorded',
                    'policyVersion' => $intent->financePolicyVersion?->version ?? 'Not recorded',
                    'destinationVersion' => $intent->vendorDestinationVersion?->version ?? 'Not recorded',
                    'chain' => $intent->chain.' '.$intent->chain_id,
                    'recipient' => $this->shortAddress($intent->recipient_address),
                    'owner' => $intent->preparer?->name ?? 'Unknown',
                    'age' => $this->age($reservation->created_at),
                    'url' => PaymentReviews::getUrl(panel: 'finance'),
                ];
            })
            ->all();
    }

    private function interval(CollectionBatch $batch): string
    {
        return $batch->collected_from->toDateString().' → '.$batch->collected_until->toDateString();
    }

    private function shortAddress(?string $address): string
    {
        if ($address === null || $address === '') {
            return 'Not recorded';
        }

        return strlen($address) > 14 ? substr($address, 0, 6).'…'.substr($address, -4) : $address;
    }

    private function age(mixed $timestamp): string
    {
        if (! $timestamp instanceof Carbon) {
            return 'Unknown';
        }

        return $timestamp->diffForHumans();
    }
}
