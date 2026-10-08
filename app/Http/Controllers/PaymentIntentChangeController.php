<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProposePaymentIntentChange;
use App\Actions\ReviewPaymentIntentChange;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Http\Requests\ReviewPaymentIntentChangeRequest;
use App\Http\Requests\StorePaymentIntentChangeRequest;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\PaymentIntentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentIntentChangeController extends Controller
{
    public function store(StorePaymentIntentChangeRequest $request, PaymentIntent $paymentIntent,
        ProposePaymentIntentChange $propose, InstallationInstitution $institutions): JsonResponse
    {
        $data = $request->validated();
        /** @var Wallet|null $wallet */
        $wallet = $data['kind'] === 'replace'
            ? Wallet::query()->where('organization_id', $institutions->require()->id)->whereKey($data['wallet_id'])->firstOrFail() : null;
        $change = $propose->handle($request->user(), $paymentIntent, $data['request_key'], $data['expected_digest'], $data['kind'], $data['reason'],
            $data['replacement_intent_key'] ?? null, $wallet, $data['kind'] === 'replace' ? Money::fromDecimal($data['max_fee'], CurrencyCode::USDC) : null);

        return response()->json(['data' => $change->evidence()]);
    }

    public function show(Request $request, PaymentIntentChange $paymentIntentChange, PaymentIntentLifecycle $lifecycle): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $paymentIntentChange);
        $state = $lifecycle->resolve($paymentIntentChange->organization_id, $paymentIntentChange->invoice_id);

        return response()->json(['data' => $paymentIntentChange->evidence(), 'lifecycle' => ['state' => $state['state'], 'current_intent_id' => $state['current']?->id]]);
    }

    public function review(ReviewPaymentIntentChangeRequest $request, PaymentIntentChange $paymentIntentChange, ReviewPaymentIntentChange $review): JsonResponse
    {
        $data = $request->validated();
        $result = $review->handle($request->user(), $paymentIntentChange, $data['expected_digest'], $data['decision'], $data['reason']);

        return response()->json(['data' => $result->evidence()]);
    }
}
