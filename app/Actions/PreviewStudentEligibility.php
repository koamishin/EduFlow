<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\Organization;
use App\Models\Student;
use App\Services\InstallationInstitution;

/**
 * Read-only assistance eligibility preview for the student dashboard.
 *
 * Runs the student-side gates of the deterministic policy (enrollment,
 * academic standing, attendance, outstanding tuition, fund affordability of
 * the auto-limit slice) without creating a request, writing an agent
 * decision, or calling the AI advisory layer. A preview is information, not
 * an approval: real disbursement still flows through a submitted request,
 * the policy engine, and human review of anything above the auto limit.
 *
 * @return array{eligible: bool, eligible_amount_base_units: string, policy_version: string|null, checks: list<array{key: string, label: string, passed: bool}>, evaluated_at: string}
 */
class PreviewStudentEligibility
{
    public function __construct(
        private readonly InstallationInstitution $institutions,
    ) {}

    public function preview(Student $student): array
    {
        $policy = AssistancePolicyVersion::active();
        $autoLimitBase = $policy instanceof AssistancePolicyVersion ? (int) $policy->auto_limit_base_units : 0;

        $outstandingBase = 0;
        foreach ($student->tuitionAccounts as $account) {
            $outstandingBase += max(0, $account->remainingAmount());
        }

        $attendance = (float) ($student->attendance_rate ?? 0);

        $checks = [
            [
                'key' => 'enrolled',
                'label' => 'Enrolled',
                'passed' => $policy instanceof AssistancePolicyVersion && $student->enrollment_status === $policy->required_enrollment_status,
            ],
            [
                'key' => 'academic_qualified',
                'label' => 'Qualified standing',
                'passed' => $policy instanceof AssistancePolicyVersion && $student->academic_status === $policy->required_academic_status,
            ],
            [
                'key' => 'attendance_ok',
                'label' => 'Attendance threshold',
                'passed' => $policy instanceof AssistancePolicyVersion && $attendance >= (float) $policy->min_attendance_rate,
            ],
            [
                'key' => 'has_outstanding_tuition',
                'label' => 'Outstanding tuition',
                'passed' => $outstandingBase > 0,
            ],
        ];

        $fund = $this->resolveFundRow();

        if ($fund !== null && $autoLimitBase > 0) {
            // Read off the stored row directly: without Larastan the query
            // result is untyped, so the affordability math lives here rather
            // than behind model method calls.
            $affordable = $fund->balance_base_units >= $autoLimitBase
                && ($fund->balance_base_units - $autoLimitBase) >= $fund->reserve_threshold_base_units;

            $checks[] = [
                'key' => 'fund_affordable',
                'label' => 'Fund can cover auto slice',
                'passed' => $affordable,
            ];
        }

        $eligibleAmount = min($autoLimitBase, $outstandingBase);
        $eligible = $policy instanceof AssistancePolicyVersion
            && $fund !== null
            && $eligibleAmount > 0
            && collect($checks)->every(fn (array $check): bool => $check['passed']);

        return [
            'eligible' => $eligible,
            'eligible_amount_base_units' => (string) ($eligible ? $eligibleAmount : 0),
            'policy_version' => $policy instanceof AssistancePolicyVersion ? $policy->version : null,
            'checks' => $checks,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    private function resolveFundRow(): ?object
    {
        $organization = $this->institutions->current();

        if (! $organization instanceof Organization) {
            return null;
        }

        return AssistanceFund::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->first();
    }
}
