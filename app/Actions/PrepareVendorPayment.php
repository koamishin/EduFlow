<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\PaymentIntentLifecycle;
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
        private PaymentIntentLifecycle $lifecycle,
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
            Gate::forUser($actor)->authorize('create', PaymentIntent::class);
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

            $lifecycle = $this->lifecycle->resolve($lockedInstitution->id, $lockedInvoice->id);
            if ($lifecycle['state'] === 'cancelled') {
                throw ValidationException::withMessages(['payment' => 'This bill draft was independently cancelled; a new key cannot reopen it.']);
            }
            $existing = $lifecycle['current'];
            if ($existing !== null) {
                $this->lifecycle->requireActive($existing);
            }
            $snapshot = $this->snapshots->build($lockedInstitution, $lockedInvoice, $lockedWallet, $maxFee);
            $snapshot['intent_key'] = $intentKey;
            $snapshot['prepared_by'] = $actor->id;

            if ($existing !== null) {
                $snapshot['prepared_by'] = $existing->prepared_by;
                if ($existing->revision > 0) {
                    $snapshot['recovery'] = $existing->snapshot['recovery'];
                }
                $digest = PaymentIntent::digest($snapshot);

                if (! $existing->hasValidSnapshot() || $existing->intent_key !== $intentKey || ! hash_equals($existing->snapshot_digest, $digest)) {
                    throw ValidationException::withMessages(['payment' => 'Existing payment draft conflicts with this identity or current document; review required.']);
                }

                return $existing;
            }

            if (PaymentIntent::query()->where('intent_key', $intentKey)->exists()
                || PaymentIntentChange::query()->where('replacement_intent_key', $intentKey)->exists()) {
                throw ValidationException::withMessages(['payment' => 'Intent identity already belongs to another payment document.']);
            }

            $intent = PaymentIntent::fromSnapshot($snapshot);
            $intent->save();

            activity('finance')->causedBy($actor)->performedOn($intent)->event('vendor_payment_prepared')
                ->withProperties(['intent_key' => $intentKey, 'invoice_id' => $lockedInvoice->id, 'approved' => false, 'payments_submitted' => 0])
                ->log('Vendor payment draft prepared; no authority to execute');

            return $intent;
        }, 3);
    }
}
