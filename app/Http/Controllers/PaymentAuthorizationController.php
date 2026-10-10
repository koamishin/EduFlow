<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReviewVendorPayment;
use App\Http\Requests\ReviewVendorPaymentRequest;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Services\InstallationInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentAuthorizationController extends Controller
{
    public function store(ReviewVendorPaymentRequest $request, PaymentIntent $paymentIntent, ReviewVendorPayment $review): JsonResponse
    {
        $authorization = $review->handle($request->user(), $paymentIntent, $request->validated());

        return response()->json(['data' => $authorization->evidence()])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, PaymentIntent $paymentIntent, InstallationInstitution $institutions): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $paymentIntent);
        /** @var PaymentAuthorization $authorization */
        $authorization = PaymentAuthorization::query()->where('organization_id', $institutions->require()->id)
            ->where('payment_intent_id', $paymentIntent->id)->firstOrFail();

        return response()->json(['data' => $authorization->evidence()])->header('Cache-Control', 'no-store');
    }
}
