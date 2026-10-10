<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\EnrollPaymentReviewer;
use App\Models\PaymentReviewerEnrollment;
use Illuminate\Foundation\Http\FormRequest;

class EnrollPaymentReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PaymentReviewerEnrollment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return EnrollPaymentReviewer::inputRules();
    }
}
