<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ApproveFundingWindow;
use App\Actions\PrepareFundingWindow;
use App\Http\Requests\ApproveFundingWindowRequest;
use App\Http\Requests\StoreFundingWindowRequest;
use App\Models\BudgetSnapshot;
use App\Models\FundingWindow;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FundingWindowController extends Controller
{
    public function store(StoreFundingWindowRequest $request, PrepareFundingWindow $prepare, InstallationInstitution $institutions): JsonResponse
    {
        $data = $request->validated();
        $institution = $institutions->require();
        /** @var BudgetSnapshot $budget */
        $budget = BudgetSnapshot::query()->where('organization_id', $institution->id)->whereKey($data['budget_snapshot_id'])->firstOrFail();
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($data['wallet_id'])->firstOrFail();
        $window = $prepare->handle($request->user(), $budget, $wallet, $data['request_key'], $data['valid_until']);

        return response()->json(['data' => $window->evidence()]);
    }

    public function show(Request $request, FundingWindow $fundingWindow): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $fundingWindow);

        return response()->json(['data' => $fundingWindow->evidence()]);
    }

    public function approve(ApproveFundingWindowRequest $request, FundingWindow $fundingWindow, ApproveFundingWindow $approve): JsonResponse
    {
        $data = $request->validated();
        $review = $approve->handle($request->user(), $fundingWindow, $data['expected_digest'], $data['verification_reference']);

        return response()->json(['data' => ['id' => $review->id, 'content' => $review->content(), 'approval_digest' => $review->approval_digest,
            'payment_approved' => false, 'can_execute' => false, 'external_funds_locked' => false]]);
    }
}
