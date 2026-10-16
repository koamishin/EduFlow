<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InstitutionPolicyEvaluation;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\JevAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class JevAnalyticsController extends Controller
{
    public function index(Request $request, InstallationInstitution $institutions, JevAnalyticsService $analytics): Response
    {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User && (
                Gate::forUser($actor)->allows('viewAny', InstitutionPolicyEvaluation::class)
                || $actor->hasAnyRole(['admin', 'super_admin', 'finance_officer', 'finance_supervisor'])
            ),
            403
        );

        $institution = $institutions->require();

        $validated = $request->validate([
            'period' => ['nullable', 'string', Rule::in(['all', 'today', '7_days', '14_days', '30_days'])],
            'source_type' => ['nullable', 'string', Rule::in(['all', 'collection', 'transaction', 'budget_snapshot'])],
        ]);

        $period = $validated['period'] ?? 'all';
        $sourceType = isset($validated['source_type']) && $validated['source_type'] !== 'all'
            ? $validated['source_type']
            : null;

        $breakdown = $analytics->decisionBreakdown($institution->id, $period, $sourceType);
        $approvals = $analytics->approvalAnalytics($institution->id, $period, $sourceType);
        $trends = array_values($analytics->decisionTrends($institution->id, 14, $sourceType));
        $recentAudit = $analytics->recentAuditLog($institution->id, 25)->all();

        return Inertia::render('finance/analytics', [
            'institution' => [
                'id' => $institution->id,
                'name' => $institution->name,
            ],
            'filters' => [
                'period' => $period,
                'source_type' => $validated['source_type'] ?? 'all',
            ],
            'breakdown' => $breakdown,
            'approvals' => $approvals,
            'trends' => $trends,
            'recentAudit' => $recentAudit,
            'urls' => [
                'evaluationsUrl' => route('finance.policy-evaluations.index'),
                'policiesUrl' => route('finance.policies.index'),
                'filamentAnalyticsUrl' => url('/finance/advisory-analytics'),
                'financeSupervisorUrl' => url('/finance/finance-supervisor'),
                'financeDashboardUrl' => url('/finance'),
            ],
        ]);
    }
}
