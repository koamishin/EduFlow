<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $payment_intent_id
 * @property int $payment_reservation_id
 * @property int $invoice_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $mfa_method
 * @property string $factor_fingerprint
 * @property int $mfa_timestep
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 */
class PaymentAuthorization extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'payment_intent_id', 'payment_reservation_id', 'invoice_id',
        'reviewed_by', 'decision', 'mfa_method', 'factor_fingerprint', 'mfa_timestep', 'snapshot', 'snapshot_digest'];

    protected $hidden = ['factor_fingerprint', 'snapshot'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $authorization): void {
            /** @var PaymentIntent|null $intent */
            $intent = PaymentIntent::query()->find($authorization->payment_intent_id);
            /** @var PaymentReservation|null $reservation */
            $reservation = PaymentReservation::query()->find($authorization->payment_reservation_id);
            if ($intent === null || $reservation === null || ! $authorization->hasValidEvidence($intent, $reservation)) {
                throw new LogicException('Payment authorization requires intact exact reservation and independent MFA review evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Payment authorization evidence is immutable; changes require fresh independent review.');
        });
        static::deleting(function (): never {
            throw new LogicException('Payment authorization evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'payment_intent_id' => 'integer', 'payment_reservation_id' => 'integer',
            'invoice_id' => 'integer', 'reviewed_by' => 'integer', 'mfa_timestep' => 'integer', 'snapshot' => 'array'];
    }

    public function hasValidEvidence(PaymentIntent $intent, PaymentReservation $reservation): bool
    {
        try {
            /** @var FundingWindowApproval|null $approval */
            $approval = FundingWindowApproval::query()->find($reservation->funding_window_approval_id);
            /** @var FundingWindow|null $window */
            $window = $approval === null ? null : FundingWindow::query()->find($approval->funding_window_id);
            $s = $this->getAttribute('snapshot');
            if (! is_array($s) || ! is_array($s['mfa'] ?? null) || $approval === null || $window === null
                || ! $approval->hasValidEvidence($window) || ! $reservation->hasValidEvidence($intent, $approval)
                || ! is_string($s['mfa']['verified_at'] ?? null) || ! is_string($s['reason'] ?? null)
                || trim($s['reason']) === '' || mb_strlen($s['reason']) > 1000) {
                return false;
            }
            /** @var PaymentReviewerEnrollment|null $enrollment */
            $enrollment = PaymentReviewerEnrollment::query()->find($s['reviewer_enrollment_id'] ?? null);
            if ($enrollment === null || ! $enrollment->hasValidEvidence() || $enrollment->reviewer_id !== $this->reviewed_by
                || $enrollment->organization_id !== $this->organization_id || $enrollment->mfa_method !== $this->mfa_method
                || ! hash_equals($enrollment->factor_fingerprint, $this->factor_fingerprint)
                || ($s['reviewer_enrollment_digest'] ?? null) !== $enrollment->snapshot_digest) {
                return false;
            }
            $verifiedAt = Carbon::parse($s['mfa']['verified_at']);
            $isFake = $reservation->snapshot['balance_observation']['is_fake'] ?? null;
            if ($this->decision === 'approve_payment') {
                if (! is_string($s['valid_until'] ?? null)) {
                    return false;
                }
                $expiry = Carbon::parse($s['valid_until']);
                if ($expiry->lte($verifiedAt) || $expiry->gt($verifiedAt->copy()->addMinutes(5))
                    || $expiry->gt(Carbon::parse($window->snapshot['valid_until']))) {
                    return false;
                }
            } elseif (($s['valid_until'] ?? null) !== null) {
                return false;
            }

            return $intent->exists && $reservation->exists && $this->payment_intent_id === $intent->id
                && $this->payment_reservation_id === $reservation->id && $this->invoice_id === $intent->invoice_id
                && $this->organization_id === $intent->organization_id && $this->reviewed_by > 0
                && $this->reviewed_by !== $intent->prepared_by && $this->reviewed_by !== $reservation->reserved_by
                && Str::isUuid($this->request_key) && $this->request_key === strtolower($this->request_key)
                && in_array($this->decision, ['approve_payment', 'reject_payment', 'hold_payment'], true)
                && in_array($this->mfa_method, ['filament_app', 'fortify_totp'], true)
                && preg_match('/^[0-9a-f]{64}$/D', $this->factor_fingerprint) === 1 && $this->mfa_timestep > 0
                && abs($this->mfa_timestep - intdiv($verifiedAt->timestamp, 30)) <= 1
                && $verifiedAt->utc()->toIso8601String() === $s['mfa']['verified_at']
                && $intent->chain === 'ARC-TESTNET' && $intent->chain_id === 5042002
                && is_bool($isFake) && ($s['is_fake'] ?? null) === $isFake
                && ($s['authority_scope'] ?? null) === ($isFake ? 'simulation_only' : 'network_payment')
                && ($s['schema_version'] ?? null) === 1 && ($s['purpose'] ?? null) === 'reserved_vendor_payment_review'
                && ($s['request_key'] ?? null) === $this->request_key && ($s['institution_id'] ?? null) === $this->organization_id
                && ($s['payment_intent_id'] ?? null) === $intent->id && ($s['invoice_id'] ?? null) === $intent->invoice_id
                && ($s['payment_reservation_id'] ?? null) === $reservation->id && ($s['reviewed_by'] ?? null) === $this->reviewed_by
                && ($s['decision'] ?? null) === $this->decision && ($s['intent_digest'] ?? null) === $intent->snapshot_digest
                && ($s['reservation_digest'] ?? null) === $reservation->snapshot_digest
                && ($s['funding_approval_digest'] ?? null) === $approval->approval_digest
                && ($s['funding_window_digest'] ?? null) === $window->snapshot_digest
                && ($s['chain'] ?? null) === $intent->chain && ($s['chain_id'] ?? null) === $intent->chain_id
                && ($s['currency'] ?? null) === 'USDC' && ($s['source_address'] ?? null) === $intent->source_address
                && ($s['recipient_address'] ?? null) === $intent->recipient_address
                && ($s['amount_base_units'] ?? null) === (string) $intent->amount_base_units
                && ($s['max_fee_base_units'] ?? null) === (string) $intent->max_fee_base_units
                && ($s['mfa']['method'] ?? null) === $this->mfa_method
                && ($s['mfa']['factor_fingerprint'] ?? null) === $this->factor_fingerprint
                && ($s['mfa']['timestep'] ?? null) === $this->mfa_timestep
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        /** @var PaymentIntent|null $intent */
        $intent = PaymentIntent::query()->find($this->payment_intent_id);
        /** @var PaymentReservation|null $reservation */
        $reservation = PaymentReservation::query()->find($this->payment_reservation_id);
        $intact = $intent !== null && $reservation !== null && $this->hasValidEvidence($intent, $reservation);
        $approved = $intact && $this->decision === 'approve_payment';
        $current = $approved && Carbon::parse($this->snapshot['valid_until'])->isFuture();
        /** @var User|null $reviewer */
        $reviewer = User::query()->find($this->reviewed_by);
        /** @var PaymentReviewerEnrollment|null $enrollment */
        $enrollment = PaymentReviewerEnrollment::query()->find($this->snapshot['reviewer_enrollment_id'] ?? null);
        $reviewerCurrent = $reviewer !== null && $enrollment !== null && $enrollment->matchesCurrentFactor($reviewer)
            && $reviewer->hasVerifiedEmail() && $reviewer->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            && $reviewer->checkPermissionTo('AuthorizePayment:PaymentIntent', 'web') && $reviewer->hasDirectPermission('AuthorizePayment:PaymentIntent');

        return ['id' => $this->id, 'request_key' => $this->request_key, 'payment_intent_id' => $this->payment_intent_id,
            'payment_reservation_id' => $this->payment_reservation_id, 'decision' => $this->decision, 'reviewed_by' => $this->reviewed_by,
            'snapshot_digest' => $this->snapshot_digest, 'evidence_valid' => $intact, 'approval_recorded' => $approved,
            'authorization_unexpired' => $current, 'current_execution_checks_required' => true,
            'payment_approved' => $current && $reviewerCurrent && ($this->snapshot['is_fake'] ?? null) === false,
            'reviewer_authority_current' => $reviewerCurrent,
            'authority_scope' => $this->snapshot['authority_scope'] ?? null, 'is_fake' => $this->snapshot['is_fake'] ?? null,
            'amount_base_units' => $this->snapshot['amount_base_units'] ?? null, 'max_fee_base_units' => $this->snapshot['max_fee_base_units'] ?? null,
            'currency' => $this->snapshot['currency'] ?? null, 'chain' => $this->snapshot['chain'] ?? null,
            'source_address' => $this->snapshot['source_address'] ?? null, 'recipient_address' => $this->snapshot['recipient_address'] ?? null,
            'valid_until' => $this->snapshot['valid_until'] ?? null, 'reason' => $this->snapshot['reason'] ?? null,
            'mfa_method' => $this->mfa_method, 'mfa_verified_at' => $this->snapshot['mfa']['verified_at'] ?? null,
            'funds_reserved' => true, 'external_funds_locked' => false, 'can_execute' => false, 'payments_submitted' => 0,
            'local_accounts_changed' => false];
    }
}
