<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EnrollPaymentReviewer;
use App\Http\Requests\EnrollPaymentReviewerRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PaymentReviewerEnrollmentController extends Controller
{
    public function store(EnrollPaymentReviewerRequest $request, User $reviewer, EnrollPaymentReviewer $enroll): JsonResponse
    {
        $enrollment = $enroll->handle($request->user(), $reviewer, $request->validated());

        return response()->json(['data' => $enrollment->evidence()])->header('Cache-Control', 'no-store');
    }
}
