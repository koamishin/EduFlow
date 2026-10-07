<?php

declare(strict_types=1);

use App\Filament\Resources\TuitionAccounts\Pages\CreateTuitionAccount;
use App\Filament\Resources\TuitionAccounts\Pages\EditTuitionAccount;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'finance_officer', 'student', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->term = AcademicTerm::factory()->create();
});

test('tuition accounts resource is registered in the admin and finance panels', function (): void {
    expect(TuitionAccountResource::getUrl('index', panel: 'admin'))->toContain('tuition-accounts')
        ->and(TuitionAccountResource::getUrl('index', panel: 'finance'))->toContain('tuition-accounts');
});

test('staff can open the tuition accounts list in both panels', function (string $role, string $panel): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(TuitionAccountResource::getUrl('index', panel: $panel))->assertSuccessful();
})->with([
    ['super_admin', 'admin'],
    ['super_admin', 'finance'],
    ['admin', 'finance'],
    ['finance_officer', 'finance'],
]);

test('nonstaff cannot open the tuition accounts list', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(TuitionAccountResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(TuitionAccountResource::getUrl('index', panel: 'finance'))->assertForbidden();
})->with(['student', 'user']);

test('staff can set a tuition balance with exact base-unit storage', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $student = Student::factory()->create();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'academic_term_id' => $this->term->getKey(),
            'total_amount' => '300.00',
            'paid_amount' => '25.50',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TuitionAccount::query()->count())->toBe(1);

    $account = TuitionAccount::query()->firstOrFail();
    expect($account->total_amount)->toBe(300000000)
        ->and($account->paid_amount)->toBe(25500000)
        ->and($account->remainingAmount())->toBe(274500000);
});

test('paid amount cannot exceed total tuition', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $student = Student::factory()->create();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'academic_term_id' => $this->term->getKey(),
            'total_amount' => '100.00',
            'paid_amount' => '100.01',
        ])
        ->call('create')
        ->assertHasFormErrors(['paid_amount']);

    expect(TuitionAccount::query()->count())->toBe(0);
});

test('duplicate student and term accounts are rejected', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $account = TuitionAccount::factory()->create();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $account->student_id,
            'academic_term_id' => $account->academic_term_id,
            'total_amount' => '300.00',
            'paid_amount' => '0.00',
        ])
        ->call('create')
        ->assertHasFormErrors(['student_id']);

    expect(TuitionAccount::query()->count())->toBe(1);
});

test('staff can update the paid amount from the edit page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('admin');

    $account = TuitionAccount::factory()->create([
        'total_amount' => 300000000,
        'paid_amount' => 0,
    ]);

    Livewire::test(EditTuitionAccount::class, ['record' => $account->getKey()])
        ->fillForm(['paid_amount' => '150.25'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->paid_amount)->toBe(150250000)
        ->and($account->fresh()->remainingAmount())->toBe(149750000);
});
