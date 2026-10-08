<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\PaymentIntentLifecycle;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class VerifyVendorPaymentDraft
{
    public function __construct(
        private InstallationInstitution $institutions,
        private VendorPaymentSnapshot $snapshots,
        private PaymentIntentLifecycle $lifecycle,
    ) {}

    public function handle(User $actor, PaymentIntent $intent): PaymentIntent
    {
        Gate::forUser($actor)->authorize('view', $intent);
        $institution = $this->institutions->require();

        /** @var PaymentIntent $stored */
        $stored = PaymentIntent::query()->where('organization_id', $institution->id)->whereKey($intent->id)->firstOrFail();
        Gate::forUser($actor)->authorize('view', $stored);
        $this->lifecycle->requireActive($stored);
        if (! $stored->hasValidSnapshot()) {
            throw ValidationException::withMessages(['payment' => 'Stored payment draft failed its integrity checks.']);
        }
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('organization_id', $institution->id)->whereKey($stored->invoice_id)->firstOrFail();
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($stored->wallet_id)->firstOrFail();
        $snapshot = $this->snapshots->build($institution, $invoice, $wallet, new Money($stored->max_fee_base_units, CurrencyCode::USDC));
        $snapshot['intent_key'] = $stored->intent_key;
        $snapshot['prepared_by'] = $stored->prepared_by;
        if ($stored->revision > 0) {
            $snapshot['recovery'] = $stored->snapshot['recovery'];
        }

        if (! hash_equals($stored->snapshot_digest, PaymentIntent::digest($snapshot))) {
            throw ValidationException::withMessages(['payment' => 'Payment document, destination, treasury or policy changed; draft is stale and requires review.']);
        }

        return $stored;
    }
}
