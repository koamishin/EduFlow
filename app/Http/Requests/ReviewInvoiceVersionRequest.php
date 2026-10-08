<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InvoiceVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewInvoiceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bill = $this->route('invoiceVersion');

        return $bill instanceof InvoiceVersion && ($this->user()?->can('review', $bill) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'decision' => ['required', Rule::in(['approve_evidence', 'reject', 'hold'])],
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
            'reviewed_by' => ['prohibited'], 'approved_by' => ['prohibited'], 'organization_id' => ['prohibited'],
            'source_amount' => ['prohibited'], 'valuation_base_units' => ['prohibited'], 'recipient_address' => ['prohibited'],
        ];
    }
}
