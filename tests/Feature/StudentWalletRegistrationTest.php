<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    $this->org = Organization::withoutSingleInstitutionGuard(fn () => Organization::create([
        'name' => 'Continental University of Technology',
        'slug' => 'continental-tech',
        'domain' => 'continental.edu',
        'country' => 'Kenya',
        'timezone' => 'Africa/Nairobi',
        'currency' => 'USDC',
        'type' => 'school',
        'is_setup_completed' => true,
    ]));
});

test('student registration screen renders successfully', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('a new student can register with institution, academic details, and web3 wallet', function (): void {
    $validArcWallet = '0x1234567890abcdef1234567890abcdef12345678';

    $response = $this->post(route('register.store'), [
        'name' => 'Marcus Doe',
        'email' => 'marcus@student.continental.edu',
        'password' => 'SecurePass123!@#',
        'password_confirmation' => 'SecurePass123!@#',
        'institution_id' => $this->org->id,
        'student_id_number' => 'CUT-2026-9901',
        'department' => 'Software Engineering',
        'wallet_address' => $validArcWallet,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    // Verify User
    $user = User::where('email', 'marcus@student.continental.edu')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Marcus Doe')
        ->and($user->organization_id)->toBe($this->org->id)
        ->and($user->wallet_address)->toBe($validArcWallet)
        ->and($user->hasRole('student'))->toBeTrue();

    // Verify Student Model
    $student = Student::where('user_id', $user->id)->first();
    expect($student)->not->toBeNull()
        ->and($student->organization_id)->toBe($this->org->id)
        ->and($student->student_id_number)->toBe('CUT-2026-9901')
        ->and($student->student_number)->toBe('CUT-2026-9901')
        ->and($student->department)->toBe('Software Engineering')
        ->and($student->program)->toBe('Software Engineering')
        ->and(strtolower($student->payout_address))->toBe(strtolower($validArcWallet))
        ->and($student->enrollment_status)->toBe('enrolled')
        ->and($student->academic_status)->toBe('qualified');

    // Verify Student Wallet
    $wallet = StudentWallet::where('student_id', $student->id)->first();
    expect($wallet)->not->toBeNull()
        ->and(strtolower($wallet->address))->toBe(strtolower($validArcWallet))
        ->and($wallet->network)->toBe('arc');

    // Verify Initial Tuition Account
    $tuitionAccount = TuitionAccount::where('student_id', $student->id)->first();
    expect($tuitionAccount)->not->toBeNull()
        ->and($tuitionAccount->total_amount)->toBeGreaterThan(0)
        ->and($tuitionAccount->paid_amount)->toBe(0)
        ->and($tuitionAccount->remainingAmount())->toBe($tuitionAccount->total_amount);
});

test('student registration rejects invalid arc wallet addresses', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Bad Wallet Student',
        'email' => 'badwallet@student.edu',
        'password' => 'SecurePass123!@#',
        'password_confirmation' => 'SecurePass123!@#',
        'institution_id' => $this->org->id,
        'student_id_number' => 'STU-100',
        'department' => 'Arts',
        'wallet_address' => '0xinvalidwalletnot40chars',
    ]);

    $response->assertSessionHasErrors(['wallet_address']);
    expect(User::where('email', 'badwallet@student.edu')->exists())->toBeFalse();
});

test('standard user registration without student fields creates non-student user', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Standard User',
        'email' => 'standard@example.com',
        'password' => 'StandardPass123!',
        'password_confirmation' => 'StandardPass123!',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'standard@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($user->student)->toBeNull();
});

test('students can register without an extension and create their embedded wallet later', function (): void {
    $this->post(route('register.store'), [
        'name' => 'Embedded Student', 'email' => 'embedded@student.edu',
        'password' => 'SecurePass123!@#', 'password_confirmation' => 'SecurePass123!@#',
        'institution_id' => $this->org->id, 'student_id_number' => 'EMBEDDED-001', 'department' => 'Computing',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $user = User::query()->where('email', 'embedded@student.edu')->firstOrFail();
    $student = $user->student()->firstOrFail();
    expect($user->hasRole('student'))->toBeTrue()
        ->and($student->payout_address)->toBeNull()
        ->and($student->wallet)->toBeNull();
});
