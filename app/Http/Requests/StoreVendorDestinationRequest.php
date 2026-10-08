<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VendorDestinationVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', VendorDestinationVersion::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['vendor_id' => ['required', 'integer', 'min:1'], 'version' => ['required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D'],
            'address' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/D'], 'chain' => ['required', Rule::in(['ARC', 'ARC-TESTNET'])],
            'control_evidence' => ['required', 'string', 'min:3', 'max:255'], 'prepared_by' => ['prohibited'],
            'organization_id' => ['prohibited'], 'approved_by' => ['prohibited'], 'chain_id' => ['prohibited']];
    }
}
