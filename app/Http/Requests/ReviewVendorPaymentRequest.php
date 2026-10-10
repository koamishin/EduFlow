<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\ReviewVendorPayment;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Http\FormRequest;

class ReviewVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $intent = $this->route('paymentIntent');

        return $intent instanceof PaymentIntent && ($this->user()?->can('authorizePayment', $intent) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ReviewVendorPayment::inputRules();
    }
}
