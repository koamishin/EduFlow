<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CaptureCollectionBatch;
use App\Models\CollectionBatch;
use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CollectionBatch::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CaptureCollectionBatch::inputRules() + ['organization_id' => ['prohibited'], 'prepared_by' => ['prohibited'],
            'snapshot' => ['prohibited'], 'received_minor_units' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'student_id' => ['prohibited'],
            'forecast_revenue' => ['prohibited'], 'can_execute' => ['prohibited']];
    }
}
