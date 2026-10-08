<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PrepareVendorPayment
{
    public function __construct(
        private InstallationInstitution $institutions,
        private VendorPaymentSnapshot $snapshots,
    ) {}

    public function handle(User $actor, Invoice $invoice, Wallet $wallet, Money $maxFee, string $intentKey): PaymentIntent
    {
        Gate::forUser($actor)->authorize('create', PaymentIntent::class);
        if (! Str::isUuid($intentKey) || ! $invoice->exists || ! $wallet->exists) {
            throw ValidationException::withMessages(['payment' => 'Persisted documents and a UUID intent identity are required.']);
        }
        $intentKey = strtolower($intentKey);
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($actor, $invoice, $wallet, $maxFee, $intentKey, $institution): PaymentIntent {
            /** @var Organization $lockedInstitution */
            $lockedInstitution = Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::query()->where('organization_id', $lockedInstitution->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            /** @var Wallet $lockedWallet */
            $lockedWallet = Wallet::query()->where('organization_id', $lockedInstitution->id)->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            /** @var Vendor $vendor */
            $vendor = Vendor::query()->whereKey($lockedInvoice->vendor_id)->lockForUpdate()->firstOrFail();
            $lockedInvoice->setRelation('vendor', $vendor);

            if ($lockedInvoice->budget_id !== null) {
                $lockedInvoice->setRelation('budget', Budget::query()->whereKey($lockedInvoice->budget_id)->lockForUpdate()->firstOrFail());
            }

            $snapshot = $this->snapshots->build($lockedInstitution, $lockedInvoice, $lockedWallet, $maxFee);
            $snapshot['intent_key'] = $intentKey;
            $snapshot['prepared_by'] = $actor->id;
            $digest = PaymentIntent::digest($snapshot);
            /** @var PaymentIntent|null $existing */
            $existing = PaymentIntent::query()->where('organization_id', $lockedInstitution->id)
                ->where('invoice_id', $lockedInvoice->id)->where('portion', 'full')->first();

            if ($existing !== null) {
                $snapshot['prepared_by'] = $existing->prepared_by;
                $digest = PaymentIntent::digest($snapshot);

                if (! $existing->hasValidSnapshot() || $existing->intent_key !== $intentKey || ! hash_equals($existing->snapshot_digest, $digest)) {
                    throw ValidationException::withMessages(['payment' => 'Existing payment draft conflicts with this identity or current document; review required.']);
                }

                return $existing;
            }

            if (PaymentIntent::query()->where('intent_key', $intentKey)->exists()) {
                throw ValidationException::withMessages(['payment' => 'Intent identity already belongs to another payment document.']);
            }

            /** @var PaymentIntent $intent */
            $intent = PaymentIntent::query()->create([
                'intent_key' => $intentKey,
                'organization_id' => $lockedInstitution->id,
                'invoice_id' => $lockedInvoice->id,
                'wallet_id' => $lockedWallet->id,
                'vendor_id' => $vendor->id,
                'budget_id' => $lockedInvoice->budget_id,
                'prepared_by' => $actor->id,
                'finance_policy_version_id' => $snapshot['policy']['version_id'],
                'finance_policy_activation_id' => $snapshot['policy']['activation_id'],
                'vendor_destination_version_id' => $snapshot['vendor_destination']['version_id'],
                'vendor_destination_approval_id' => $snapshot['vendor_destination']['approval_id'],
                'invoice_version_id' => $snapshot['invoice_evidence']['version_id'] ?? null,
                'invoice_version_review_id' => $snapshot['invoice_evidence']['review_id'] ?? null,
                'amount_base_units' => (int) $snapshot['invoice']['amount_base_units'],
                'max_fee_base_units' => $maxFee->minorUnits,
                'chain' => $snapshot['chain'],
                'chain_id' => $snapshot['chain_id'],
                'source_address' => $snapshot['treasury']['source_address'],
                'recipient_address' => $snapshot['vendor']['recipient_address'],
                'provider_idempotency_key' => 'eduflow:'.$intentKey,
                'snapshot_digest' => $digest,
                'snapshot' => $snapshot,
            ]);

            activity('finance')->causedBy($actor)->performedOn($intent)->event('vendor_payment_prepared')
                ->withProperties(['intent_key' => $intentKey, 'invoice_id' => $lockedInvoice->id, 'approved' => false, 'payments_submitted' => 0])
                ->log('Vendor payment draft prepared; no authority to execute');

            return $intent;
        }, 3);
    }
}
