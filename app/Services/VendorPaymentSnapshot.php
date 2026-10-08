<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Exact document context, not a verdict that funds are available or approved.
 */
final class VendorPaymentSnapshot
{
    /** @return array<string, mixed> */
    public function build(Organization $institution, Invoice $invoice, Wallet $wallet, Money $maxFee): array
    {
        if (! $institution->exists || ! $invoice->exists || ! $wallet->exists
            || $invoice->organization_id !== $institution->id || $wallet->organization_id !== $institution->id) {
            $this->refuse('Payment documents and treasury must belong to the installation institution.');
        }
        if ($invoice->hasExactVersion()) {
            $this->refuse('Versioned invoices require reviewed exact-evidence integration; legacy document draft preparation is forbidden.');
        }
        if ($institution->currency !== CurrencyCode::USDC->value) {
            $this->refuse('Legacy document amounts are USDC; reviewed currency conversion is required.');
        }
        if (! in_array($invoice->status, ['pending', 'held', 'escalated'], true)) {
            $this->refuse('Only an open vendor bill can be prepared.');
        }
        if ($maxFee->currency !== CurrencyCode::USDC || $maxFee->minorUnits < 0) {
            $this->refuse('Fee ceiling must be non-negative exact USDC.');
        }

        /** @var Vendor|null $vendor */
        $vendor = $invoice->vendor;
        /** @var Budget|null $budget */
        $budget = $invoice->budget;
        if ($vendor === null || $vendor->organization_id !== $institution->id || ! $vendor->isVerified()) {
            $this->refuse('A verified institution vendor is required.');
        }
        if ($invoice->budget_id !== null && ($budget === null || $budget->organization_id !== $institution->id || $budget->status !== 'active')) {
            $this->refuse('The bill budget must be active and owned by the institution.');
        }
        if ($wallet->status !== 'active' || $wallet->provider !== 'circle') {
            $this->refuse('An active Circle treasury record is required.');
        }

        $chain = (string) config('lepton.arc.chain');
        $chainId = match ($chain) {
            'ARC' => 5042,
            'ARC-TESTNET' => 5042002,
            default => null,
        };
        if ($chainId === null || (int) config('lepton.arc.chain_id') !== $chainId
            || ! in_array($wallet->network, ['arc', strtolower($chain), $chain === 'ARC' ? 'arc-mainnet' : 'arc-testnet'], true)) {
            $this->refuse('Configured Arc chain identity and treasury network must agree.');
        }
        $configured = config('lepton.arc.treasury');
        if (is_string($configured) && $configured !== '' && strcasecmp($configured, $wallet->address) !== 0) {
            $this->refuse('Selected treasury does not match the configured Circle wallet.');
        }
        $source = $this->address($wallet->address);
        $recipient = $this->address($vendor->wallet_address);
        if ($source === $recipient) {
            $this->refuse('A vendor payment cannot target the source treasury.');
        }

        $amount = $this->amount($invoice, 'amount');
        if ($amount->minorUnits <= 0) {
            $this->refuse('Payment amount must be positive.');
        }
        $amount->plus($maxFee);
        $activation = FinancePolicyActivation::current($institution->id);
        if (! $activation instanceof FinancePolicyActivation) {
            $this->refuse('An independently activated institution finance policy is required.');
        }
        /** @var FinancePolicyVersion|null $policy */
        $policy = FinancePolicyVersion::query()->where('organization_id', $institution->id)->whereKey($activation->finance_policy_version_id)->first();
        if ($policy === null || ! $activation->hasValidEvidence($policy) || ! $activation->hasValidHistory()
                    || $maxFee->minorUnits > $policy->max_fee_base_units) {
            $this->refuse('Active policy evidence or requested fee ceiling is invalid.');
        }

        return [
            'schema_version' => 1,
            'institution_id' => $institution->id,
            'currency' => CurrencyCode::USDC->value,
            'portion' => 'full',
            'chain' => $chain,
            'chain_id' => $chainId,
            'max_fee_base_units' => (string) $maxFee->minorUnits,
            'invoice' => [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'vendor_id' => $vendor->id,
                'budget_id' => $invoice->budget_id,
                'amount_base_units' => (string) $amount->minorUnits,
                'due_date' => $invoice->due_date->toDateString(),
                'category' => $invoice->category,
                'status' => $invoice->status,
            ],
            'treasury' => ['wallet_id' => $wallet->id, 'source_address' => $source, 'provider' => $wallet->provider, 'network' => $wallet->network],
            'vendor' => ['id' => $vendor->id, 'recipient_address' => $recipient, 'status' => $vendor->status, 'risk_level' => $vendor->risk_level],
            'budget' => $budget === null ? null : [
                'id' => $budget->id,
                'category' => $budget->category,
                'status' => $budget->status,
                'allocated_base_units' => (string) $this->amount($budget, 'allocated_amount')->minorUnits,
            ],
            'policy' => [
                'source' => 'finance_policy_version',
                'version_id' => $policy->id,
                'activation_id' => $activation->id,
                'approved_by' => $activation->approved_by,
                'activation_digest' => $activation->activation_digest,
                'content_digest' => $policy->content_digest,
                'content' => $policy->content(),
            ],
        ];
    }

    private function amount(Model $model, string $attribute): Money
    {
        $value = $model->getRawOriginal($attribute);
        if (! is_string($value) && ! is_int($value)) {
            $this->refuse('Inexact legacy monetary storage cannot be used for a payment draft.');
        }

        return Money::fromDecimal((string) $value, CurrencyCode::USDC);
    }

    private function address(string $address): string
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1 || preg_match('/^0x0{40}$/D', $address) === 1) {
            $this->refuse('A real recorded nonzero 40-hex-digit payment address is required.');
        }

        return strtolower($address);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
