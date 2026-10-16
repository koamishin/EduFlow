<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Organization::withoutSingleInstitutionGuard(function () {
        return Organization::create([
            'name' => 'General Academy',
            'type' => 'school',
            'currency' => 'USDC',
            'is_setup_completed' => true,
        ]);
    });
});

test('registration screen can be rendered', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration creates the user role when it is missing', function (): void {
    Role::query()->where('name', 'user')->delete();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->hasRole('user'))->toBeTrue()
        ->and(Role::query()->where('name', 'user')->where('guard_name', 'web')->exists())->toBeTrue();
});
