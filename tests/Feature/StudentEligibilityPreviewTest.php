<?php

declare(strict_types=1);

use App\Actions\PreviewStudentEligibility;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;

beforeEach(function (): void {
    $this->org = Organization::create([
        'name' => 'Northstar Learning Center',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    $this->policy = AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $this->student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);
    TuitionAccount::factory()->for($this->student)->create([
        'total_amount' => 300000000,
        'paid_amount' => 25000000,
    ]);
});

test('eligible students see their auto slice with every gate passing', function (): void {
    $preview = app(PreviewStudentEligibility::class)->preview($this->student);

    expect($preview['eligible'])->toBeTrue()
        ->and($preview['eligible_amount_base_units'])->toBe('100000000')
        ->and($preview['policy_version'])->toBe('v1')
        ->and(collect($preview['checks'])->every(fn (array $check): bool => $check['passed']))->toBeTrue();
});

test('preview writes no decisions and files no requests', function (): void {
    app(PreviewStudentEligibility::class)->preview($this->student);

    expect(AgentDecision::query()->count())->toBe(0)
        ->and(AssistanceRequest::query()->count())->toBe(0);
});

test('unenrolled students are ineligible with the failing gate named', function (): void {
    $this->student->update(['enrollment_status' => 'not_enrolled']);

    $preview = app(PreviewStudentEligibility::class)->preview($this->student->fresh());

    expect($preview['eligible'])->toBeFalse()
        ->and(collect($preview['checks'])->firstWhere('key', 'enrolled')['passed'])->toBeFalse();
});

test('students with no outstanding tuition are ineligible', function (): void {
    $this->student->tuitionAccounts()->update(['paid_amount' => 300000000]);

    $preview = app(PreviewStudentEligibility::class)->preview($this->student->fresh());

    expect($preview['eligible'])->toBeFalse()
        ->and($preview['eligible_amount_base_units'])->toBe('0')
        ->and(collect($preview['checks'])->firstWhere('key', 'has_outstanding_tuition')['passed'])->toBeFalse();
});

test('no active policy means no eligibility', function (): void {
    $this->policy->update(['is_active' => false]);

    $preview = app(PreviewStudentEligibility::class)->preview($this->student);

    expect($preview['eligible'])->toBeFalse()
        ->and($preview['policy_version'])->toBeNull();
});

test('no fund means no eligibility', function (): void {
    $this->fund->delete();

    $preview = app(PreviewStudentEligibility::class)->preview($this->student);

    expect($preview['eligible'])->toBeFalse();
});
