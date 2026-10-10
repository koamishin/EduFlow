<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReserveVendorPayment;
use App\Http\Requests\StorePaymentReservationRequest;
use App\Models\FundingWindowApproval;
use App\Models\PaymentIntent;
use App\Services\InstallationInstitution;
use Illuminate\Http\JsonResponse;

class PaymentReservationController extends Controller
{
    public function store(StorePaymentReservationRequest $request, PaymentIntent $paymentIntent,
        ReserveVendorPayment $reserve, InstallationInstitution $institutions): JsonResponse
    {
        $data = $request->validated();
        /** @var FundingWindowApproval $approval */
        $approval = FundingWindowApproval::query()->where('organization_id', $institutions->require()->id)->whereKey($data['funding_window_approval_id'])->firstOrFail();
        $reservation = $reserve->handle($request->user(), $paymentIntent, $approval, $data['reservation_key'], $data['intent_digest'], $data['approval_digest']);

        return response()->json(['data' => $reservation->evidence()]);
    }
}
