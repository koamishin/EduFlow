<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FundingWindow;
use Illuminate\Foundation\Http\FormRequest;

class StoreFundingWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FundingWindow::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'budget_snapshot_id' => ['required', 'integer', 'min:1'],
            'wallet_id' => ['required', 'integer', 'min:1'], 'valid_until' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'exclusive_treasury' => ['required', 'accepted'], 'prepared_by' => ['prohibited'], 'organization_id' => ['prohibited'],
            'snapshot' => ['prohibited'], 'balance' => ['prohibited'], 'capacity' => ['prohibited']];
    }
}
