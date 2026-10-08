<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/** @extends Factory<PaymentIntent> */
class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = (string) Str::uuid();

        return [
            'intent_key' => $key,
            'provider_idempotency_key' => 'eduflow:'.$key,
            'prepared_by' => User::factory(),
            'status' => 'draft',
            'currency' => 'USDC',
            'portion' => 'full',
        ];
    }

    public function forVendorBill(Invoice $invoice, Wallet $wallet, User $preparer): static
    {
        if (! $invoice->exists || ! $wallet->exists || ! $preparer->exists) {
            throw new LogicException('Draft factory needs existing bill, treasury and preparer records.');
        }

        return $this->state([
            'invoice_id' => $invoice->id,
            'wallet_id' => $wallet->id,
            'prepared_by' => $preparer->id,
        ]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PaymentIntent $intent): void {
            $attributes = $intent->getAttributes();
            if (! isset($attributes['invoice_id'], $attributes['wallet_id'])) {
                throw new LogicException('Use forVendorBill() or supply existing invoice_id and wallet_id to the draft factory.');
            }

            /** @var Organization $institution */
            $institution = Organization::query()->sole();
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($intent->invoice_id)->firstOrFail();
            /** @var Wallet $wallet */
            $wallet = Wallet::query()->whereKey($intent->wallet_id)->firstOrFail();
            $snapshot = app(VendorPaymentSnapshot::class)->build(
                $institution, $invoice, $wallet,
                new Money($intent->max_fee_base_units ?? 0, CurrencyCode::USDC),
            );

            $snapshot['intent_key'] = $intent->intent_key;
            $snapshot['prepared_by'] = $intent->prepared_by;

            $intent->fill([
                'organization_id' => $institution->id,
                'vendor_id' => $invoice->vendor_id,
                'budget_id' => $invoice->budget_id,
                'finance_policy_version_id' => $snapshot['policy']['version_id'],
                'finance_policy_activation_id' => $snapshot['policy']['activation_id'],
                'vendor_destination_version_id' => $snapshot['vendor_destination']['version_id'],
                'vendor_destination_approval_id' => $snapshot['vendor_destination']['approval_id'],
                'invoice_version_id' => $snapshot['invoice_evidence']['version_id'] ?? null,
                'invoice_version_review_id' => $snapshot['invoice_evidence']['review_id'] ?? null,
                'amount_base_units' => (int) $snapshot['invoice']['amount_base_units'],
                'max_fee_base_units' => (int) $snapshot['max_fee_base_units'],
                'chain' => $snapshot['chain'],
                'chain_id' => $snapshot['chain_id'],
                'source_address' => $snapshot['treasury']['source_address'],
                'recipient_address' => $snapshot['vendor']['recipient_address'],
                'snapshot' => $snapshot,
                'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);
        });
    }
}
