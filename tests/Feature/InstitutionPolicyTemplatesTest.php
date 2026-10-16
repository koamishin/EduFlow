<?php

declare(strict_types=1);

use App\Models\FinancePolicyActivation;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstitutionPolicyTemplates;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

test('finance workspace lists curated policy templates in inertia props', function (): void {
    $c = institutionPolicyContext();

    $this->actingAs($c['maker'])
        ->get(route('finance.policies.index'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('finance/policies/index')
            ->has('templates', 5)
            ->where('templates.0.key', InstitutionPolicyTemplates::TEMPLATE_STANDARD)
            ->where('templates.1.key', InstitutionPolicyTemplates::TEMPLATE_CAMPUS_EVENTS)
            ->where('templates.2.key', InstitutionPolicyTemplates::TEMPLATE_STEM_RESEARCH)
            ->where('templates.3.key', InstitutionPolicyTemplates::TEMPLATE_AUTONOMOUS_FAST_TRACK)
            ->where('templates.4.key', InstitutionPolicyTemplates::TEMPLATE_STRICT_GOVERNANCE)
        );
});

test('admin can apply and activate standard policy template in one click', function (): void {
    $c = institutionPolicyContext();

    $response = $this->actingAs($c['reviewer'])
        ->postJson(route('finance.policies.apply-template'), [
            'template_key' => InstitutionPolicyTemplates::TEMPLATE_STANDARD,
            'auto_publish' => true,
            'reason' => '1-click standard policy activation',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('status', 'success');

    $latestVersion = InstitutionPolicyVersion::query()->latest('id')->first();
    expect($latestVersion)->not->toBeNull()
        ->and($latestVersion->title)->toContain('Standard Academic Operations')
        ->and($latestVersion->hasValidContent())->toBeTrue();

    $publication = InstitutionPolicyPublication::query()->latest('id')->first();
    expect($publication)->not->toBeNull()
        ->and($publication->institution_policy_version_id)->toBe($latestVersion->id)
        ->and($publication->hasActiveControls())->toBeTrue();

    $currentActivation = FinancePolicyActivation::current($latestVersion->organization_id);
    expect($currentActivation)->not->toBeNull()
        ->and($currentActivation->finance_policy_version_id)->toBe($latestVersion->finance_policy_version_id);
});

test('admin can apply campus events template', function (): void {
    $c = institutionPolicyContext();

    $response = $this->actingAs($c['reviewer'])
        ->postJson(route('finance.policies.apply-template'), [
            'template_key' => InstitutionPolicyTemplates::TEMPLATE_CAMPUS_EVENTS,
            'auto_publish' => true,
        ]);

    $response->assertSuccessful()->assertJsonPath('status', 'success');

    $latestVersion = InstitutionPolicyVersion::query()->latest('id')->first();
    expect($latestVersion->title)->toContain('Campus Fest & Student Activities');
});

test('policy decision simulator classifies matching transactions', function (): void {
    $c = institutionPolicyContext();

    // Activate standard template first
    $this->actingAs($c['reviewer'])
        ->postJson(route('finance.policies.apply-template'), [
            'template_key' => InstitutionPolicyTemplates::TEMPLATE_STANDARD,
            'auto_publish' => true,
        ])->assertSuccessful();

    $response = $this->actingAs($c['maker'])
        ->postJson(route('finance.policies.simulate'), [
            'description' => 'Student partial tuition payment received for Fall semester',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', 'match')
        ->assertJsonPath('data.category_name', 'Tuition Receipts');

    expect($response->json('data.decision_steps'))->not->toBeEmpty();
});

test('policy decision simulator holds ambiguous transactions for bursar review', function (): void {
    $c = institutionPolicyContext();

    $this->actingAs($c['reviewer'])
        ->postJson(route('finance.policies.apply-template'), [
            'template_key' => InstitutionPolicyTemplates::TEMPLATE_STANDARD,
            'auto_publish' => true,
        ])->assertSuccessful();

    $response = $this->actingAs($c['maker'])
        ->postJson(route('finance.policies.simulate'), [
            'description' => 'Unapproved luxury diamond wristwatches for department staff party',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', 'needs_review')
        ->assertJsonPath('data.category_name', 'Needs Review');
});

test('institution setup completes and activates selected policy template', function (): void {
    $org = Organization::factory()->create([
        'is_setup_completed' => false,
    ]);
    $admin = User::factory()->create([
        'organization_id' => $org->id,
    ])->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->actingAs($admin)
        ->post(route('institutions.setup.complete'), [
            'policy_template' => InstitutionPolicyTemplates::TEMPLATE_STEM_RESEARCH,
            'minimum_reserve' => 1000,
            'max_auto_payment' => 150,
            'budgets' => [],
        ])
        ->assertRedirect('/finance');

    expect($org->fresh()->is_setup_completed)->toBeTrue();

    $latestVersion = InstitutionPolicyVersion::query()
        ->where('organization_id', $org->id)
        ->latest('id')
        ->first();

    expect($latestVersion)->not->toBeNull()
        ->and($latestVersion->title)->toContain('STEM Labs & Research Procurement');

    $pub = InstitutionPolicyPublication::query()
        ->where('organization_id', $org->id)
        ->latest('id')
        ->first();

    expect($pub)->not->toBeNull()
        ->and($pub->institution_policy_version_id)->toBe($latestVersion->id)
        ->and($pub->hasActiveControls())->toBeTrue();
});
