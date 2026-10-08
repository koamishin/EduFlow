<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PaymentIntent;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentIntentChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $intent = $this->route('paymentIntent');

        return $intent instanceof PaymentIntent && ($this->user()?->can('proposeChange', $intent) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['request_key' => ['required', 'string', 'uuid'], 'expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'kind' => ['required', 'in:cancel,replace'], 'reason' => ['required', 'string', 'max:1000'],
            'replacement_intent_key' => ['required_if:kind,replace', 'prohibited_if:kind,cancel', 'string', 'uuid'],
            'wallet_id' => ['required_if:kind,replace', 'prohibited_if:kind,cancel', 'integer', 'min:1'],
            'max_fee' => ['required_if:kind,replace', 'prohibited_if:kind,cancel', 'string', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D'],
            'proposed_by' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'organization_id' => ['prohibited'],
            'invoice_id' => ['prohibited'], 'source_digest' => ['prohibited'], 'snapshot' => ['prohibited'],
            'replacement_snapshot' => ['prohibited'], 'recipient_address' => ['prohibited'], 'amount_base_units' => ['prohibited'],
            'provider_idempotency_key' => ['prohibited'], 'can_execute' => ['prohibited']];
    }
}
