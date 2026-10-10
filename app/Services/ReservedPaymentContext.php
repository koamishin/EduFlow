<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\VerifyVendorPaymentDraft;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final readonly class ReservedPaymentContext
{
    public function __construct(private VerifyVendorPaymentDraft $drafts, private ArcBalanceObservation $balances,
        private FundingWindowContext $funding) {}

    /** @return array{window: FundingWindow, balance_observation: array<string, mixed>} */
    public function requireCurrent(User $actor, PaymentIntent $intent, PaymentReservation $reservation): array
    {
        $this->drafts->handle($actor, $intent);
        /** @var FundingWindowApproval $approval */
        $approval = FundingWindowApproval::query()->where('organization_id', $intent->organization_id)
            ->whereKey($reservation->funding_window_approval_id)->firstOrFail();
        /** @var FundingWindow $window */
        $window = FundingWindow::query()->where('organization_id', $intent->organization_id)->whereKey($approval->funding_window_id)->firstOrFail();
        if (! $approval->hasValidEvidence($window) || ! $reservation->hasValidEvidence($intent, $approval)
            || $intent->wallet_id !== $window->wallet_id || $intent->budget_id !== $window->budget_id) {
            throw ValidationException::withMessages(['payment' => 'Exact independently reviewed funding and matching reservation are required.']);
        }
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $intent->organization_id)->whereKey($intent->wallet_id)->firstOrFail();
        $balance = $this->balances->capture($wallet);
        $current = $this->funding->requireCurrent($actor, $window, $balance);
        $bound = false;
        foreach ($current['budget']->snapshot['bills'] as $bill) {
            if ($bill['id'] === $intent->invoice_version_id && $bill['review_id'] === $intent->invoice_version_review_id
                && $bill['digest'] === ($intent->snapshot['invoice_evidence']['snapshot_digest'] ?? null)) {
                $bound = true;
            }
        }
        if (! $bound) {
            throw ValidationException::withMessages(['payment' => 'Reserved payment is not in the funding window closed bill set.']);
        }
        /** @var Collection<int, PaymentReservation> $holds */
        $holds = PaymentReservation::query()->where('organization_id', $intent->organization_id)->orderBy('id')->limit(10_001)->get();
        if ($holds->count() > 10_000) {
            throw ValidationException::withMessages(['payment' => 'Reservation history exceeds reviewed bounds.']);
        }
        /** @var Collection<int, PaymentIntent> $intents */
        $intents = PaymentIntent::query()->where('organization_id', $intent->organization_id)
            ->whereIn('id', $holds->pluck('payment_intent_id'))->get()->keyBy('id');
        $total = BigInteger::zero();
        $found = false;
        foreach ($holds as $hold) {
            $heldIntent = $intents->get($hold->payment_intent_id);
            if (! $heldIntent instanceof PaymentIntent || ! $hold->hasValidEvidence($heldIntent, $approval)
                || ($hold->snapshot['prior_reserved_base_units'] ?? null) !== (string) $total) {
                throw ValidationException::withMessages(['payment' => 'Held capacity evidence is incomplete; uncertain commitments cannot become spending room.']);
            }
            $total = $total->plus($hold->amount_base_units)->plus($hold->max_fee_base_units);
            if ($total->isGreaterThan($window->snapshot['capacity']['budget_base_units'])
                || $total->isGreaterThan($window->snapshot['capacity']['cash_base_units'])
                || ($hold->snapshot['remaining_budget_base_units'] ?? null)
                    !== (string) BigInteger::of($window->snapshot['capacity']['budget_base_units'])->minus($total)) {
                throw ValidationException::withMessages(['payment' => 'Held capacity sequence or approved limits changed.']);
            }
            $found = $found || $hold->id === $reservation->id;
        }
        if (! $found || $total->isGreaterThan($current['available_cash'])) {
            throw ValidationException::withMessages(['payment' => 'Current unprotected Arc cash cannot cover every held bill and fee cap.']);
        }

        return ['window' => $window, 'balance_observation' => $balance];
    }
}
