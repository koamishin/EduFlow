<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PaymentIntent;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $intent = $this->route('paymentIntent');

        return $intent instanceof PaymentIntent && ($this->user()?->can('reserve', $intent) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reservation_key' => ['required', 'uuid'], 'funding_window_approval_id' => ['required', 'integer', 'min:1'],
            'intent_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'], 'approval_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'reserved_by' => ['prohibited'], 'organization_id' => ['prohibited'], 'snapshot' => ['prohibited'],
            'amount_base_units' => ['prohibited'], 'max_fee_base_units' => ['prohibited'], 'can_execute' => ['prohibited']];
    }
}
