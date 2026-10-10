<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CollectionBatch;
use Illuminate\Foundation\Http\FormRequest;

class ReviewCollectionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('collectionBatch');

        return $batch instanceof CollectionBatch && ($this->user()?->can('review', $batch) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'], 'decision' => ['required', 'in:approve_receipts,reject,hold'],
            'verification_reference' => ['required', 'string', 'min:3', 'max:255'], 'reason' => ['required', 'string', 'max:1000'],
            'received_and_restrictions_verified' => ['required', 'accepted'], 'reviewed_by' => ['prohibited'],
            'organization_id' => ['prohibited'], 'received_amount' => ['prohibited'], 'restricted_amount' => ['prohibited'], 'snapshot' => ['prohibited']];
    }
}
