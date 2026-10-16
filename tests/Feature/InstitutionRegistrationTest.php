<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
});

test('institution registration page can be rendered', function (): void {
    $response = $this->get(route('institutions.register'));

    $response->assertOk();
});

test('an institution can be registered with autonomous circle agent wallet', function (): void {
    $response = $this->post(route('institutions.register.store'), [
        'name' => 'Apex Academy of Science',
        'slug' => 'apex-academy',
        'domain' => 'apex.edu',
        'country' => 'United States',
        'timezone' => 'America/New_York',
        'currency' => 'USDC',
        'use_agent_wallet' => true,
        'admin_name' => 'Dean Apex',
        'admin_email' => 'dean@apex.edu',
        'admin_password' => 'SecureApexPassword123!',
        'admin_password_confirmation' => 'SecureApexPassword123!',
    ]);

    $response->assertRedirect('/finance');

    $org = Organization::where('slug', 'apex-academy')->first();
    expect($org)->not->toBeNull()
        ->and($org->name)->toBe('Apex Academy of Science')
        ->and($org->country)->toBe('United States')
        ->and($org->currency)->toBe('USDC');

    // Assert Treasury Wallet
    $wallet = Wallet::where('organization_id', $org->id)->first();
    expect($wallet)->not->toBeNull()
        ->and($wallet->provider)->toBe('circle')
        ->and($wallet->network)->toBe('arc')
        ->and($wallet->status)->toBe('active');

    // Assert Baseline Budgets
    $budgetCategories = Budget::where('organization_id', $org->id)->pluck('category')->all();
    expect($budgetCategories)->toContain('technology', 'operations', 'assistance', 'equipment');

    // Assert Administrator user
    $admin = User::where('email', 'dean@apex.edu')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->organization_id)->toBe($org->id)
        ->and($admin->hasRole('super_admin'))->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($admin);
    expect(session('current_organization_id'))->toBe($org->id);
});

test('an institution can be registered with a manual arc treasury address', function (): void {
    $manualAddress = '0x1111111111222222222233333333334444444444';

    $response = $this->post(route('institutions.register.store'), [
        'name' => 'Metro Polytechnic Institute',
        'country' => 'United Kingdom',
        'timezone' => 'Europe/London',
        'currency' => 'USDC',
        'use_agent_wallet' => false,
        'treasury_address' => $manualAddress,
        'admin_name' => 'Provost Metro',
        'admin_email' => 'provost@metro.ac.uk',
        'admin_password' => 'SecureMetroPassword123!',
        'admin_password_confirmation' => 'SecureMetroPassword123!',
    ]);

    $response->assertRedirect('/finance');

    $org = Organization::where('name', 'Metro Polytechnic Institute')->first();
    expect($org)->not->toBeNull();

    $wallet = Wallet::where('organization_id', $org->id)->first();
    expect($wallet)->not->toBeNull()
        ->and($wallet->provider)->toBe('manual')
        ->and(strtolower($wallet->address))->toBe(strtolower($manualAddress));
});

test('multiple institutions can register on the shared database without single installation guard collisions', function (): void {
    // Register School 1
    $this->post(route('institutions.register.store'), [
        'name' => 'Alpha Institute',
        'country' => 'Canada',
        'timezone' => 'America/Toronto',
        'use_agent_wallet' => true,
        'admin_name' => 'Alpha Admin',
        'admin_email' => 'admin@alpha.edu',
        'admin_password' => 'AlphaAdminPass123!',
        'admin_password_confirmation' => 'AlphaAdminPass123!',
    ])->assertRedirect('/finance');

    // Register School 2
    $this->post(route('institutions.register.store'), [
        'name' => 'Beta University',
        'country' => 'Canada',
        'timezone' => 'America/Vancouver',
        'use_agent_wallet' => true,
        'admin_name' => 'Beta Admin',
        'admin_email' => 'admin@beta.edu',
        'admin_password' => 'BetaAdminPass123!',
        'admin_password_confirmation' => 'BetaAdminPass123!',
    ])->assertRedirect('/finance');

    expect(Organization::where('name', 'Alpha Institute')->exists())->toBeTrue()
        ->and(Organization::where('name', 'Beta University')->exists())->toBeTrue();
});

test('institution registration validates required fields and address format', function (): void {
    $response = $this->post(route('institutions.register.store'), [
        'name' => '',
        'country' => '',
        'timezone' => '',
        'use_agent_wallet' => false,
        'treasury_address' => 'invalid-address',
        'admin_name' => '',
        'admin_email' => 'not-an-email',
        'admin_password' => 'short',
        'admin_password_confirmation' => 'mismatch',
    ]);

    $response->assertSessionHasErrors([
        'name',
        'country',
        'timezone',
        'treasury_address',
        'admin_name',
        'admin_email',
        'admin_password',
    ]);
});

test('manual treasury requires a valid address when use_agent_wallet is false or omitted', function (): void {
    $response = $this->post(route('institutions.register.store'), [
        'name' => 'Valid College Name',
        'country' => 'United States',
        'timezone' => 'America/New_York',
        'use_agent_wallet' => false,
        'treasury_address' => '',
        'admin_name' => 'Dean Test',
        'admin_email' => 'dean.test@validcollege.edu',
        'admin_password' => 'ValidSecurePassword123!',
        'admin_password_confirmation' => 'ValidSecurePassword123!',
    ]);

    $response->assertSessionHasErrors(['treasury_address']);
});
