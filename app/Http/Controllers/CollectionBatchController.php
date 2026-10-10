<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CaptureCollectionBatch;
use App\Actions\ReviewCollectionBatch;
use App\Http\Requests\ReviewCollectionBatchRequest;
use App\Http\Requests\StoreCollectionBatchRequest;
use App\Models\CollectionBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CollectionBatchController extends Controller
{
    public function store(StoreCollectionBatchRequest $request, CaptureCollectionBatch $capture): JsonResponse
    {
        $batch = $capture->handle($request->user(), $request->validated());

        return response()->json(['data' => $batch->evidence()]);
    }

    public function show(Request $request, CollectionBatch $collectionBatch): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $collectionBatch);

        return response()->json(['data' => $collectionBatch->evidence()]);
    }

    public function review(ReviewCollectionBatchRequest $request, CollectionBatch $collectionBatch, ReviewCollectionBatch $review): JsonResponse
    {
        $data = $request->validated();
        $result = $review->handle($request->user(), $collectionBatch, $data['expected_digest'], $data['decision'], $data['verification_reference'], $data['reason']);

        return response()->json(['data' => ['id' => $result->id, 'content' => $result->content(), 'review_digest' => $result->review_digest,
            'arc_funding_verified' => false, 'can_execute' => false]]);
    }
}
