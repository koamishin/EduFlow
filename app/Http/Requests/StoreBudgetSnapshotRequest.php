<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CaptureBudgetSnapshot;
use App\Models\BudgetSnapshot;
use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BudgetSnapshot::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CaptureBudgetSnapshot::inputRules() + [
            'budget_id' => ['required', 'integer', 'min:1'], 'prepared_by' => ['prohibited'], 'organization_id' => ['prohibited'],
            'can_execute' => ['prohibited'], 'expected_receipts' => ['prohibited'], 'forecast_revenue' => ['prohibited'],
        ];
    }
}
