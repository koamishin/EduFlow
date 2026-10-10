<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FundingWindow;
use Illuminate\Foundation\Http\FormRequest;

class ApproveFundingWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $window = $this->route('fundingWindow');

        return $window instanceof FundingWindow && ($this->user()?->can('approve', $window) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'verification_reference' => ['required', 'string', 'min:3', 'max:255'],
            'allocation_and_exclusions_verified' => ['required', 'accepted'], 'exclusive_treasury_verified' => ['required', 'accepted'],
            'approved_by' => ['prohibited'], 'organization_id' => ['prohibited'], 'snapshot' => ['prohibited'], 'capacity' => ['prohibited']];
    }
}
