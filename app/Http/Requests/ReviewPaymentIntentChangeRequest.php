<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PaymentIntentChange;
use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentIntentChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $change = $this->route('paymentIntentChange');

        return $change instanceof PaymentIntentChange && ($this->user()?->can('review', $change) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'decision' => ['required', 'in:approve_change,reject'], 'reason' => ['required', 'string', 'max:1000'],
            'reviewed_by' => ['prohibited'], 'organization_id' => ['prohibited'], 'payment_intent_id' => ['prohibited'],
            'replacement_intent_key' => ['prohibited'], 'replacement_snapshot' => ['prohibited'],
            'recipient_address' => ['prohibited'], 'amount_base_units' => ['prohibited'],
            'provider_idempotency_key' => ['prohibited'], 'can_execute' => ['prohibited']];
    }
}
