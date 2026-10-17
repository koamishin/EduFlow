<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RoleEnums;
use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        if (Organization::query()->count() === 0) {
            throw ValidationException::withMessages([
                'institution' => 'No educational institution is registered yet. Please register your institution first.',
            ]);
        }

        $isStudent = ! empty($input['student_id_number'])
            || ! empty($input['student_number'])
            || ! empty($input['department'])
            || ! empty($input['wallet_address'])
            || ! empty($input['payout_address'])
            || (($input['role'] ?? null) === RoleEnums::STUDENT->value);

        if ($isStudent) {
            return $this->createStudentUser($input);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $user->assignRole($this->defaultRole());

        return $user;
    }

    /**
     * Validate and create a student user with web3 wallet and tuition account.
     *
     * @param  array<string, mixed>  $input
     */
    protected function createStudentUser(array $input): User
    {
        $resolvedOrgId = $input['institution_id']
            ?? $input['organization_id']
            ?? app(InstallationInstitution::class)->current()?->id;

        if (empty($resolvedOrgId)) {
            throw ValidationException::withMessages([
                'institution_id' => 'Please select an educational institution to enroll.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'institution_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'student_id_number' => ['required_without:student_number', 'nullable', 'string', 'max:50'],
            'student_number' => ['required_without:student_id_number', 'nullable', 'string', 'max:50'],
            'department' => ['required_without:program', 'nullable', 'string', 'max:100'],
            'program' => ['required_without:department', 'nullable', 'string', 'max:100'],
            'wallet_address' => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'payout_address' => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/'],
        ])->validate();

        $studentNumber = (string) ($input['student_id_number'] ?? $input['student_number']);
        $department = (string) ($input['department'] ?? $input['program']);
        $walletAddress = $input['wallet_address'] ?? $input['payout_address'] ?? null;

        return DB::transaction(function () use ($input, $resolvedOrgId, $studentNumber, $department, $walletAddress): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'organization_id' => $resolvedOrgId,
                'wallet_address' => $walletAddress,
            ]);

            $studentRole = Role::firstOrCreate([
                'name' => RoleEnums::STUDENT->value,
                'guard_name' => 'web',
            ]);

            $user->assignRole($studentRole);

            $student = Student::create([
                'organization_id' => $resolvedOrgId,
                'user_id' => $user->id,
                'student_id_number' => $studentNumber,
                'student_number' => $studentNumber,
                'department' => $department,
                'program' => $department,
                'payout_address' => $walletAddress,
                'year_level' => (int) ($input['year_level'] ?? 1),
                'enrollment_status' => 'enrolled',
                'academic_status' => 'qualified',
            ]);

            if (is_string($walletAddress) && $walletAddress !== '') {
                StudentWallet::create([
                    'student_id' => $student->id,
                    'address' => $walletAddress,
                    'provider' => 'external',
                    'network' => 'arc',
                    'chain' => 'ARC-TESTNET',
                    'status' => 'active',
                    'generated_at' => now(),
                ]);

            }

            $term = AcademicTerm::query()
                ->where('starts_on', '<=', today())
                ->where('ends_on', '>=', today())
                ->first()
                ?: AcademicTerm::query()->latest('id')->first();

            if (! $term) {
                $term = AcademicTerm::create([
                    'name' => 'Term 1 - '.date('Y'),
                    'starts_on' => now()->startOfYear(),
                    'ends_on' => now()->endOfYear(),
                ]);
            }

            TuitionAccount::create([
                'student_id' => $student->id,
                'academic_term_id' => $term->id,
                'total_amount' => 300000000,
                'paid_amount' => 0,
            ]);

            return $user;
        });
    }

    protected function defaultRole(): Role
    {
        return Role::firstOrCreate([
            'name' => RoleEnums::USER->value,
            'guard_name' => 'web',
        ]);
    }
}
