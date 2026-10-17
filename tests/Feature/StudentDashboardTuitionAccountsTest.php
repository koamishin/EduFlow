<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);
    config(['eduflow.display_currency' => 'PHP']);
    Cache::flush();

    foreach (['student', 'finance_officer', 'admin', 'super_admin', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

test('student dashboard includes all tuition accounts in tuitionAccounts prop', function (): void {
    $user = User::factory()->create();
    $user->assignRole('student');
    $student = Student::factory()->for($user)->create();

    $term1 = AcademicTerm::factory()->create([
        'name' => 'First Term 2026',
        'starts_on' => today()->subMonths(6),
        'ends_on' => today()->subMonth(),
    ]);

    $term2 = AcademicTerm::factory()->create([
        'name' => 'Second Term 2026',
        'starts_on' => today()->subDays(5),
        'ends_on' => today()->addMonths(4),
    ]);

    $account1 = TuitionAccount::factory()->for($student)->for($term1)->create([
        'total_amount' => 300000000,
        'paid_amount' => 300000000,
    ]);

    $account2 = TuitionAccount::factory()->for($student)->for($term2)->create([
        'total_amount' => 500000000,
        'paid_amount' => 100000000,
    ]);

    $this->actingAs($user)->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('student/dashboard')
            ->has('tuitionAccounts', 2)
            ->where('tuitionAccount.id', $account2->id)
            ->where('tuitionAccounts.0.id', $account2->id)
            ->where('tuitionAccounts.1.id', $account1->id)
        );
});

test('student dashboard loads selected tuition account when account query param is provided', function (): void {
    $user = User::factory()->create();
    $user->assignRole('student');
    $student = Student::factory()->for($user)->create();

    $term1 = AcademicTerm::factory()->create([
        'name' => 'Previous Term',
        'starts_on' => today()->subMonths(6),
        'ends_on' => today()->subMonth(),
    ]);

    $term2 = AcademicTerm::factory()->create([
        'name' => 'Current Term',
        'starts_on' => today()->subDays(5),
        'ends_on' => today()->addMonths(4),
    ]);

    $account1 = TuitionAccount::factory()->for($student)->for($term1)->create([
        'total_amount' => 300000000,
        'paid_amount' => 250000000,
    ]);

    $account2 = TuitionAccount::factory()->for($student)->for($term2)->create([
        'total_amount' => 500000000,
        'paid_amount' => 100000000,
    ]);

    // Request explicitly for account 1
    $this->actingAs($user)->get(route('student.dashboard', ['account' => $account1->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('tuitionAccount.id', $account1->id)
            ->where('tuitionAccount.term', 'Previous Term')
            ->where('tuitionAccount.remaining_amount', '50000000')
        );
});

test('student dashboard ignores unowned account query param and does not leak other student account', function (): void {
    $user = User::factory()->create();
    $user->assignRole('student');
    $student = Student::factory()->for($user)->create();

    $otherUser = User::factory()->create();
    $otherUser->assignRole('student');
    $otherStudent = Student::factory()->for($otherUser)->create();

    $term = AcademicTerm::factory()->create([
        'starts_on' => today()->subDays(5),
        'ends_on' => today()->addMonths(4),
    ]);

    $ownAccount = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 400000000,
        'paid_amount' => 100000000,
    ]);

    $otherAccount = TuitionAccount::factory()->for($otherStudent)->for($term)->create([
        'total_amount' => 800000000,
        'paid_amount' => 0,
    ]);

    // Requesting other student's account should not load other account
    $this->actingAs($user)->get(route('student.dashboard', ['account' => $otherAccount->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('tuitionAccount.id', $ownAccount->id)
            ->has('tuitionAccounts', 1)
            ->where('tuitionAccounts.0.id', $ownAccount->id)
        );
});

test('student dashboard with overlapping current terms provides all accounts and allows explicit selection', function (): void {
    $user = User::factory()->create();
    $user->assignRole('student');
    $student = Student::factory()->for($user)->create();

    $term1 = AcademicTerm::factory()->create([
        'name' => 'Demo Academic Term 2026',
        'starts_on' => today()->startOfYear(),
        'ends_on' => today()->endOfYear(),
    ]);

    $term2 = AcademicTerm::factory()->create([
        'name' => 'Academic Term 2026',
        'starts_on' => today()->startOfYear(),
        'ends_on' => today()->endOfYear(),
    ]);

    $account1 = TuitionAccount::factory()->for($student)->for($term1)->create([
        'total_amount' => 300000000,
        'paid_amount' => 25000000,
    ]);

    $account2 = TuitionAccount::factory()->for($student)->for($term2)->create([
        'total_amount' => 1200000000,
        'paid_amount' => 205000000,
    ]);

    // Without account query param: tuitionAccount is null because of overlapping terms, but tuitionAccounts contains both
    $this->actingAs($user)->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('tuitionAccount', null)
            ->where('canRequest', false)
            ->has('tuitionAccounts', 2)
            ->where('tuitionAccounts.0.id', $account2->id)
            ->where('tuitionAccounts.1.id', $account1->id)
        );

    // With explicit ?account= parameter: student can inspect their account directly
    $this->actingAs($user)->get(route('student.dashboard', ['account' => $account2->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('tuitionAccount.id', $account2->id)
            ->where('tuitionAccount.term', 'Academic Term 2026')
            ->where('tuitionAccount.remaining_amount', '995000000')
        );
});
