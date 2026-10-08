<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CaptureBudgetSnapshot;
use App\Http\Requests\StoreBudgetSnapshotRequest;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Services\DepartmentBudgetPlanner;
use App\Services\InstallationInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BudgetSnapshotController extends Controller
{
    public function store(StoreBudgetSnapshotRequest $request, CaptureBudgetSnapshot $capture, InstallationInstitution $institutions): JsonResponse
    {
        $data = $request->validated();
        /** @var Budget $budget */
        $budget = Budget::query()->where('organization_id', $institutions->require()->id)->whereKey($data['budget_id'])->firstOrFail();
        unset($data['budget_id']);
        $snapshot = $capture->handle($request->user(), $budget, $data);

        return response()->json(['data' => ['id' => $snapshot->id, 'snapshot' => $snapshot->snapshot, 'snapshot_digest' => $snapshot->snapshot_digest,
            'headroom' => $snapshot->headroom(), 'can_execute' => false, 'payment_approved' => false]]);
    }

    public function show(Request $request, BudgetSnapshot $budgetSnapshot): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $budgetSnapshot);

        return response()->json(['data' => ['id' => $budgetSnapshot->id, 'snapshot' => $budgetSnapshot->snapshot,
            'snapshot_digest' => $budgetSnapshot->snapshot_digest, 'snapshot_valid' => $budgetSnapshot->hasValidSnapshot(), 'can_execute' => false]]);
    }

    public function plan(Request $request, BudgetSnapshot $budgetSnapshot, DepartmentBudgetPlanner $planner): JsonResponse
    {
        $result = $planner->handle($request->user(), $budgetSnapshot);
        activity('finance')->causedBy($request->user())->performedOn($budgetSnapshot)->event('department_budget_planned')
            ->withProperties(['snapshot_digest' => $result['snapshot_digest'], 'can_execute' => false])->log('Read-only cumulative departmental bill plan inspected');

        return response()->json(['data' => $result]);
    }
}
