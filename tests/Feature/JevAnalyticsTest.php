<?php

declare(strict_types=1);

use App\Filament\Finance\Widgets\JevApprovalConcurrenceChart;
use App\Filament\Finance\Widgets\JevCategoryDistributionChart;
use App\Filament\Finance\Widgets\JevDecisionBreakdownChart;
use App\Filament\Finance\Widgets\JevDecisionsTrendChart;
use App\Filament\Finance\Widgets\JevDecisionSummaryWidget;
use App\Filament\Pages\JevAnalyticsDashboard;
use App\Models\Budget;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstitutionPolicyWorkspace;
use App\Services\JevAnalyticsService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Helpers/InstitutionPolicyFixtures.php';

beforeEach(function (): void {
    Bus::fake();
    $this->org = Organization::factory()->create();
    config([
        'queue.default' => 'database',
        'eduflow.institution_id' => $this->org->id,
    ]);

    $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->financeOfficer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $this->student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));

    $this->policy = app(InstitutionPolicyWorkspace::class)->save($this->financeOfficer, institutionPolicyInput());
    $this->policy->update(['organization_id' => $this->org->id]);
});

test('jev analytics service returns zeroed metrics on empty evaluations', function (): void {
    $service = app(JevAnalyticsService::class);

    $breakdown = $service->decisionBreakdown($this->org->id);
    expect($breakdown['total'])->toBe(0)
        ->and($breakdown['match'])->toBe(0)
        ->and($breakdown['needs_review'])->toBe(0)
        ->and($breakdown['hold'])->toBe(0)
        ->and($breakdown['exempt'])->toBe(0)
        ->and($breakdown['categories'])->toBe([]);

    $approvals = $service->approvalAnalytics($this->org->id);
    expect($approvals['total_evaluated'])->toBe(0)
        ->and($approvals['approval_rate'])->toBe(0.0)
        ->and($approvals['concurrence_count'])->toBe(0)
        ->and($approvals['override_count'])->toBe(0)
        ->and($approvals['avg_time_to_approval_human'])->toBe('N/A');

    $trends = $service->decisionTrends($this->org->id, 7);
    expect($trends)->toHaveCount(7);
});

test('jev analytics service classifies decisions and aggregates categories properly', function (): void {
    $service = app(JevAnalyticsService::class);

    // 1. Insert match evaluation
    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'budget_snapshot',
        'source_id' => 101,
        'source_digest' => str_repeat('a', 64),
        'policy_digest' => str_repeat('b', 64),
        'publication_digest' => str_repeat('c', 64),
        'reason' => 'Test match evaluation',
        'input' => json_encode(['type' => 'budget_snapshot']),
        'input_digest' => str_repeat('d', 64),
        'state' => 'completed',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'category_id' => 'materials',
            'top_category_name' => 'Teaching Materials',
            'confidence' => 0.95,
            'needs_review' => false,
        ]),
        'result_digest' => str_repeat('e', 64),
        'created_at' => now(),
        'completed_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Insert hold evaluation
    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'collection',
        'source_id' => 102,
        'source_digest' => str_repeat('f', 64),
        'policy_digest' => str_repeat('b', 64),
        'publication_digest' => str_repeat('c', 64),
        'reason' => 'Test hold evaluation',
        'input' => json_encode(['type' => 'collection']),
        'input_digest' => str_repeat('d', 64),
        'state' => 'needs_review',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'needs_review' => true,
            'explanation' => 'Held for human review due to safety threshold',
            'confidence' => 0.60,
        ]),
        'result_digest' => str_repeat('g', 64),
        'created_at' => now(),
        'completed_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Insert exempt evaluation
    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'transaction',
        'source_id' => 103,
        'source_digest' => str_repeat('h', 64),
        'policy_digest' => str_repeat('b', 64),
        'publication_digest' => str_repeat('c', 64),
        'reason' => 'Test exempt evaluation',
        'input' => json_encode(['type' => 'transaction']),
        'input_digest' => str_repeat('d', 64),
        'state' => 'completed',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'category_id' => 'exempt',
            'confidence' => 1.0,
            'needs_review' => false,
        ]),
        'result_digest' => str_repeat('i', 64),
        'created_at' => now(),
        'completed_at' => now(),
        'updated_at' => now(),
    ]);

    $breakdown = $service->decisionBreakdown($this->org->id);

    expect($breakdown['total'])->toBe(3)
        ->and($breakdown['match'])->toBe(1)
        ->and($breakdown['hold'])->toBe(1)
        ->and($breakdown['exempt'])->toBe(1)
        ->and($breakdown['match_rate'])->toBe(33.3)
        ->and($breakdown['categories'])->toHaveKey('materials');

    expect($breakdown['categories']['materials']['count'])->toBe(1)
        ->and($breakdown['categories']['materials']['avg_confidence'])->toBe(95.0);

    $audit = $service->recentAuditLog($this->org->id, 10);
    expect($audit)->toHaveCount(3);
});

test('jev analytics service measures staff concurrence and time to approval', function (): void {
    $service = app(JevAnalyticsService::class);

    // Create a collection batch
    $batchId = DB::table('collection_batches')->insertGetId([
        'capture_key' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'prepared_by' => $this->financeOfficer->id,
        'source_stream' => 'test_stream',
        'source_reference' => 'ref-'.Str::random(10),
        'source_document_digest' => str_repeat('1', 64),
        'currency' => 'USDC',
        'received_minor_units' => 1000000,
        'restricted_minor_units' => 0,
        'collected_from' => now()->subDay(),
        'collected_until' => now(),
        'snapshot' => json_encode(['note' => 'test']),
        'snapshot_digest' => str_repeat('2', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a completed review that approved receipts
    DB::table('collection_batch_reviews')->insert([
        'organization_id' => $this->org->id,
        'collection_batch_id' => $batchId,
        'reviewed_by' => $this->admin->id,
        'decision' => 'approve_receipts',
        'verification_reference' => 'verified-ref',
        'reason' => 'Receipts verified and approved by admin',
        'batch_digest' => str_repeat('3', 64),
        'review_digest' => str_repeat('4', 64),
        'created_at' => now()->addMinutes(10),
        'updated_at' => now()->addMinutes(10),
    ]);

    // Insert an evaluation matching this batch
    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'collection',
        'source_id' => $batchId,
        'source_digest' => str_repeat('5', 64),
        'policy_digest' => str_repeat('6', 64),
        'publication_digest' => str_repeat('7', 64),
        'reason' => 'Collection match test',
        'input' => json_encode(['source_type' => 'collection', 'source_id' => $batchId]),
        'input_digest' => str_repeat('8', 64),
        'state' => 'completed',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'category_id' => 'materials',
            'needs_review' => false,
            'confidence' => 0.90,
        ]),
        'result_digest' => str_repeat('9', 64),
        'created_at' => now(),
        'completed_at' => now()->addMinutes(1),
        'updated_at' => now()->addMinutes(1),
    ]);

    $analytics = $service->approvalAnalytics($this->org->id);

    expect($analytics['total_evaluated'])->toBe(1)
        ->and($analytics['total_reviewed'])->toBe(1)
        ->and($analytics['total_approved'])->toBe(1)
        ->and($analytics['concurrence_count'])->toBe(1)
        ->and($analytics['concurrence_rate'])->toBe(100.0)
        ->and($analytics['override_count'])->toBe(0)
        ->and($analytics['avg_time_to_approval_seconds'])->toBeGreaterThan(0);
});

test('guest cannot access jev analytics dashboard', function (): void {
    $this->get(route('finance.jev-analytics'))
        ->assertRedirect(route('login'));
});

test('unauthorized user cannot access jev analytics dashboard', function (): void {
    $this->actingAs($this->student)
        ->get(route('finance.jev-analytics'))
        ->assertForbidden();
});

test('finance officer and admin can access jev analytics dashboard with valid inertia props', function (): void {
    $this->actingAs($this->financeOfficer)
        ->get(route('finance.jev-analytics'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('finance/analytics')
            ->has('institution')
            ->has('filters')
            ->has('breakdown')
            ->has('approvals')
            ->has('trends')
            ->has('recentAudit')
            ->has('urls')
        );

    $this->actingAs($this->admin)
        ->get(route('finance.jev-analytics', ['period' => '7_days', 'source_type' => 'collection']))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('finance/analytics')
            ->where('filters.period', '7_days')
            ->where('filters.source_type', 'collection')
        );
});

test('dispatch policy evaluations console command executes cleanly with empty queue and queued evaluations', function (): void {
    $this->artisan('app:dispatch-policy-evaluations')
        ->expectsOutputToContain('No queued policy evaluations waiting for dispatch.')
        ->assertSuccessful();

    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'collection',
        'source_id' => 999,
        'source_digest' => str_repeat('a', 64),
        'policy_digest' => str_repeat('b', 64),
        'publication_digest' => str_repeat('c', 64),
        'reason' => 'Queued dispatch test',
        'input' => json_encode(['source_type' => 'collection']),
        'input_digest' => str_repeat('d', 64),
        'state' => 'queued',
        'stage' => 'queued',
        'completed_targets' => 0,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => null,
        'result_digest' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('app:dispatch-policy-evaluations')
        ->expectsOutputToContain('Dispatching policy evaluation #')
        ->expectsOutputToContain('Processed 1 JEV policy evaluations.')
        ->assertSuccessful();
});

test('decisionTrends aggregates time series with populated evaluations without throwing RelationNotFoundException', function (): void {
    $service = app(JevAnalyticsService::class);

    // Insert an evaluation from today
    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'collection',
        'source_id' => 881,
        'source_digest' => str_repeat('a', 64),
        'policy_digest' => str_repeat('b', 64),
        'publication_digest' => str_repeat('c', 64),
        'reason' => 'Today match evaluation',
        'input' => json_encode(['source_type' => 'collection']),
        'input_digest' => str_repeat('d', 64),
        'state' => 'completed',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'category_id' => 'materials',
            'top_category_name' => 'Teaching Materials',
            'confidence' => 0.95,
            'needs_review' => false,
        ]),
        'result_digest' => str_repeat('e', 64),
        'created_at' => now(),
        'completed_at' => now(),
        'updated_at' => now(),
    ]);

    // Insert an evaluation from 2 days ago
    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'collection',
        'source_id' => 882,
        'source_digest' => str_repeat('f', 64),
        'policy_digest' => str_repeat('b', 64),
        'publication_digest' => str_repeat('c', 64),
        'reason' => 'Past hold evaluation',
        'input' => json_encode(['source_type' => 'collection']),
        'input_digest' => str_repeat('d', 64),
        'state' => 'needs_review',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'needs_review' => true,
            'explanation' => 'Held for human review due to safety threshold',
            'confidence' => 0.65,
        ]),
        'result_digest' => str_repeat('g', 64),
        'created_at' => now()->subDays(2),
        'completed_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $trends = $service->decisionTrends($this->org->id, 7);

    expect($trends)->toHaveCount(7);
    $todayKey = now()->toDateString();
    $twoDaysAgoKey = now()->subDays(2)->toDateString();

    expect($trends[$todayKey]['matches'])->toBe(1)
        ->and($trends[$todayKey]['total'])->toBe(1)
        ->and($trends[$twoDaysAgoKey]['holds'])->toBe(1)
        ->and($trends[$twoDaysAgoKey]['total'])->toBe(1);
});

test('dual-control multi-review picks the latest immutable review for concurrence', function (): void {
    $service = app(JevAnalyticsService::class);

    $budget = Budget::factory()->create(['organization_id' => $this->org->id]);

    $snapshotId = DB::table('budget_snapshots')->insertGetId([
        'capture_key' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'budget_id' => $budget->id,
        'prepared_by' => $this->financeOfficer->id,
        'currency' => 'USDC',
        'snapshot' => json_encode(['title' => 'Dual Control Budget']),
        'snapshot_digest' => str_repeat('1', 64),
        'created_at' => now()->subHours(4),
        'updated_at' => now()->subHours(4),
    ]);

    // Run #1: First supervisor requested correction / held
    $run1Id = DB::table('finance_workflow_runs')->insertGetId([
        'organization_id' => $this->org->id,
        'budget_snapshot_id' => $snapshotId,
        'kind' => 'plan_review',
        'trigger_key' => 'run-1-'.Str::uuid(),
        'source_digest' => str_repeat('1', 64),
        'state' => 'completed',
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    DB::table('finance_plan_reviews')->insert([
        'organization_id' => $this->org->id,
        'finance_workflow_run_id' => $run1Id,
        'reviewed_by' => $this->financeOfficer->id,
        'decision' => 'request_correction',
        'reason' => 'First review requested correction',
        'plan_digest' => str_repeat('2', 64),
        'review_digest' => str_repeat('3', 64),
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    // Run #2: Dual-control second supervisor approved plan
    $run2Id = DB::table('finance_workflow_runs')->insertGetId([
        'organization_id' => $this->org->id,
        'budget_snapshot_id' => $snapshotId,
        'kind' => 'plan_review',
        'trigger_key' => 'run-2-'.Str::uuid(),
        'source_digest' => str_repeat('1', 64),
        'state' => 'completed',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    DB::table('finance_plan_reviews')->insert([
        'organization_id' => $this->org->id,
        'finance_workflow_run_id' => $run2Id,
        'reviewed_by' => $this->admin->id,
        'decision' => 'accept_plan',
        'reason' => 'Second review approved after dual verification',
        'plan_digest' => str_repeat('4', 64),
        'review_digest' => str_repeat('5', 64),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    // JEV evaluation matched
    $evalId = DB::table('institution_policy_evaluations')->insertGetId([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'budget_snapshot',
        'source_id' => $snapshotId,
        'source_digest' => str_repeat('5', 64),
        'policy_digest' => str_repeat('6', 64),
        'publication_digest' => str_repeat('7', 64),
        'reason' => 'Dual control test',
        'input' => json_encode(['source_type' => 'budget_snapshot', 'source_id' => $snapshotId]),
        'input_digest' => str_repeat('8', 64),
        'state' => 'completed',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'lines' => [
                ['decision' => 'propose_human_review', 'category_id' => 'materials'],
            ],
        ]),
        'result_digest' => str_repeat('9', 64),
        'created_at' => now()->subHours(4),
        'completed_at' => now()->subHours(4),
        'updated_at' => now()->subHours(4),
    ]);

    $eval = InstitutionPolicyEvaluation::query()->findOrFail($evalId);
    $approval = $service->resolveStaffApproval($eval);

    // Concurrence should be based on Review #2 (accept_plan), meaning approved and concurred!
    expect($approval['reviewed'])->toBeTrue()
        ->and($approval['status'])->toBe('approved')
        ->and($approval['decision'])->toBe('accept_plan')
        ->and($approval['reviewer_id'])->toBe($this->admin->id)
        ->and($approval['concurrence'])->toBeTrue()
        ->and($approval['is_override'])->toBeFalse();
});

test('budget plan review with request_correction maps to held status', function (): void {
    $service = app(JevAnalyticsService::class);

    $budget = Budget::factory()->create(['organization_id' => $this->org->id]);

    $snapshotId = DB::table('budget_snapshots')->insertGetId([
        'capture_key' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'budget_id' => $budget->id,
        'prepared_by' => $this->financeOfficer->id,
        'currency' => 'USDC',
        'snapshot' => json_encode(['test' => true]),
        'snapshot_digest' => str_repeat('1', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = DB::table('finance_workflow_runs')->insertGetId([
        'organization_id' => $this->org->id,
        'budget_snapshot_id' => $snapshotId,
        'kind' => 'plan_review',
        'trigger_key' => (string) Str::uuid(),
        'source_digest' => str_repeat('1', 64),
        'state' => 'waiting_for_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('finance_plan_reviews')->insert([
        'organization_id' => $this->org->id,
        'finance_workflow_run_id' => $runId,
        'reviewed_by' => $this->admin->id,
        'decision' => 'request_correction',
        'reason' => 'Staff requested correction on budget line item',
        'plan_digest' => str_repeat('2', 64),
        'review_digest' => str_repeat('3', 64),
        'created_at' => now()->addMinutes(15),
        'updated_at' => now()->addMinutes(15),
    ]);

    $evalId = DB::table('institution_policy_evaluations')->insertGetId([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'budget_snapshot',
        'source_id' => $snapshotId,
        'source_digest' => str_repeat('4', 64),
        'policy_digest' => str_repeat('5', 64),
        'publication_digest' => str_repeat('6', 64),
        'reason' => 'Budget plan test',
        'input' => json_encode(['source_type' => 'budget_snapshot', 'source_id' => $snapshotId]),
        'input_digest' => str_repeat('7', 64),
        'state' => 'needs_review',
        'stage' => 'completed',
        'completed_targets' => 1,
        'total_targets' => 1,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'lines' => [
                ['decision' => 'hold', 'category_id' => 'materials'],
            ],
        ]),
        'result_digest' => str_repeat('8', 64),
        'created_at' => now(),
        'completed_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    $eval = InstitutionPolicyEvaluation::query()->findOrFail($evalId);
    $approval = $service->resolveStaffApproval($eval);

    expect($approval['reviewed'])->toBeTrue()
        ->and($approval['status'])->toBe('held')
        ->and($approval['decision'])->toBe('request_correction')
        ->and($approval['concurrence'])->toBeTrue();
});

test('filament jev analytics dashboard renders for authorized officers with table and widgets', function (): void {
    $this->actingAs($this->financeOfficer);

    Livewire::test(JevAnalyticsDashboard::class)
        ->assertSuccessful()
        ->assertSee('Recent JEV Advisory Evaluations &amp; Staff Audit Trail', escape: false)
        ->assertSee('Autonomous Lane Telemetry')
        ->assertSee('80% Confidence Safety Gate');
});

test('filament jev widgets render correctly with both empty and populated data', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(JevDecisionSummaryWidget::class)->assertSuccessful();
    Livewire::test(JevDecisionBreakdownChart::class)->assertSuccessful();
    Livewire::test(JevApprovalConcurrenceChart::class)->assertSuccessful();
    Livewire::test(JevDecisionsTrendChart::class)->assertSuccessful();
    Livewire::test(JevCategoryDistributionChart::class)->assertSuccessful();
});

test('budget_snapshot evaluations aggregate all line categories and classify partial cash headroom holds as policy matches', function (): void {
    $service = app(JevAnalyticsService::class);
    $budget = Budget::factory()->create(['organization_id' => $this->org->id]);

    $snapshotId = DB::table('budget_snapshots')->insertGetId([
        'capture_key' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'budget_id' => $budget->id,
        'prepared_by' => $this->financeOfficer->id,
        'currency' => 'USDC',
        'snapshot' => json_encode(['test' => true]),
        'snapshot_digest' => str_repeat('1', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('institution_policy_evaluations')->insert([
        'organization_id' => $this->org->id,
        'institution_policy_version_id' => $this->policy->id,
        'requested_by' => $this->financeOfficer->id,
        'request_key' => (string) Str::uuid(),
        'source_type' => 'budget_snapshot',
        'source_id' => $snapshotId,
        'source_digest' => str_repeat('4', 64),
        'policy_digest' => str_repeat('5', 64),
        'publication_digest' => str_repeat('6', 64),
        'reason' => 'Multi-category budget snapshot test',
        'input' => json_encode(['source_type' => 'budget_snapshot', 'source_id' => $snapshotId]),
        'input_digest' => str_repeat('7', 64),
        'state' => 'completed',
        'stage' => 'completed',
        'completed_targets' => 3,
        'total_targets' => 3,
        'timeline' => json_encode([]),
        'result' => json_encode([
            'lines' => [
                [
                    'decision' => 'propose_human_review',
                    'category_id' => 'supplier-invoices',
                    'confidence' => 98,
                    'explanation' => 'JEV matched "Approved Supplier Invoices" (98% confidence).',
                    'checks' => ['policy_classified' => true, 'within_available_cash' => true],
                ],
                [
                    'decision' => 'propose_human_review',
                    'category_id' => 'budget-technology',
                    'confidence' => 95,
                    'explanation' => 'JEV matched "IT & Campus Technology" (95% confidence).',
                    'checks' => ['policy_classified' => true, 'within_available_cash' => true],
                ],
                [
                    'decision' => 'hold_for_review',
                    'category_id' => 'budget-equipment',
                    'confidence' => 92,
                    'explanation' => 'JEV matched "STEM Lab & Capital Equipment" (92% confidence). Held due to remaining cash headroom.',
                    'checks' => ['policy_classified' => true, 'within_available_cash' => false],
                ],
            ],
        ]),
        'result_digest' => str_repeat('8', 64),
        'created_at' => now(),
        'completed_at' => now()->addSeconds(5),
        'updated_at' => now()->addSeconds(5),
    ]);

    $breakdown = $service->decisionBreakdown($this->org->id);
    $categories = collect($breakdown['categories'])->keyBy('id');

    expect($breakdown['match'])->toBeGreaterThanOrEqual(1)
        ->and($categories->has('supplier-invoices'))->toBeTrue()
        ->and($categories->has('budget-technology'))->toBeTrue()
        ->and($categories->has('budget-equipment'))->toBeTrue()
        ->and($categories['supplier-invoices']['name'])->toBe('Approved Supplier Invoices')
        ->and($categories['budget-technology']['name'])->toBe('IT & Campus Technology')
        ->and($categories['budget-equipment']['name'])->toBe('STEM Lab & Capital Equipment');

    $audit = $service->recentAuditLog($this->org->id, 5);
    $snapshotRow = collect($audit)->firstWhere('source_type', 'budget_snapshot');
    expect($snapshotRow)->not->toBeNull()
        ->and($snapshotRow['jev_decision'])->toBe('match')
        ->and($snapshotRow['category_name'])->toContain('Approved Supplier Invoices')
        ->and($snapshotRow['confidence_percent'])->toBe(95);
});
