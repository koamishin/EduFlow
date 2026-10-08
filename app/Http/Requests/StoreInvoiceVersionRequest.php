<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CurrencyCode;
use App\Models\InvoiceVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InvoiceVersion::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'min:1'], 'capture_key' => ['required', 'uuid'],
            'source_amount' => ['required', 'string', 'max:30'],
            'source_currency' => ['required', Rule::in(CurrencyCode::values())],
            'source_evidence' => ['required', 'string', 'min:3', 'max:255'],
            'business_approval_reference' => ['required', 'string', 'min:3', 'max:255'],
            'department' => ['required', 'string', 'min:2', 'max:120'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'source_per_usdc' => ['required', 'string', 'max:31'], 'rate_source' => ['required', 'string', 'min:3', 'max:255'],
            'rate_observed_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'rounding' => ['required', Rule::in(['down', 'half_up', 'up'])],
            'prepared_by' => ['prohibited'], 'organization_id' => ['prohibited'], 'status' => ['prohibited'],
            'valuation_base_units' => ['prohibited'], 'approved_by' => ['prohibited'], 'chain' => ['prohibited'], 'chain_id' => ['prohibited'],
        ];
    }
}
