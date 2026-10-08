<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VendorDestinationVersion;
use Illuminate\Foundation\Http\FormRequest;

class ApproveVendorDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $destination = $this->route('vendorDestinationVersion');

        return $destination instanceof VendorDestinationVersion && ($this->user()?->can('approve', $destination) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'expected_approval' => ['required', 'string', 'regex:/^(?:none|[1-9][0-9]*)$/D'],
            'verification_reference' => ['required', 'string', 'min:3', 'max:255'],
            'control_verified' => ['required', 'accepted'], 'approved_by' => ['prohibited'], 'organization_id' => ['prohibited'],
            'address' => ['prohibited'], 'chain' => ['prohibited'], 'chain_id' => ['prohibited']];
    }
}
