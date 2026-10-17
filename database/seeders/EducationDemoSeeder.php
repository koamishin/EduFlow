<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Enums\RoleEnums;
use App\Enums\StudentBillCategory;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class EducationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $gateway = app(ArcNetworkGateway::class);
        $isTestnet = ! \App\Services\ArcNetworkGateway::isMainnetGateway($gateway);

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Education demo data may only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $studentRole = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
            $financeRole = Role::firstOrCreate(['name' => 'finance_officer', 'guard_name' => 'web']);
            $superAdminRole = Role::firstOrCreate(['name' => RoleEnums::SUPER_ADMIN->value, 'guard_name' => 'web']);

            $org = Organization::query()->first() ?? Organization::create([
                'name' => 'Northstar Academy of Technology',
                'slug' => 'northstar-academy',
                'domain' => 'northstar.eduflow.internal',
                'country' => 'United States',
                'timezone' => 'UTC',
                'type' => 'school',
                'currency' => 'USDC',
                'minimum_reserve' => 20.00,
                'max_auto_payment' => 50.00,
                'max_daily_disbursement' => 200.00,
                'human_approval_threshold' => 50.00,
                'is_setup_completed' => true,
            ]);

            if (! $org->is_setup_completed) {
                $org->update(['is_setup_completed' => true]);
            }

            $treasuryAddress = (string) (config('lepton.arc.treasury') ?: '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA');

            Wallet::firstOrCreate(
                ['organization_id' => $org->id, 'address' => $treasuryAddress],
                [
                    'provider' => 'circle',
                    'network' => 'arc',
                    'balance' => 120.00,
                    'status' => 'active',
                ]
            );

            $isTesting = app()->runningUnitTests() || class_exists(TestCase::class, false);

            // 1. Staff / Admins
            $staffList = [
                ['name' => 'Finance Officer', 'email' => 'finance@eduflow.test', 'role' => $financeRole],
            ];

            if (! $isTesting) {
                $staffList[] = ['name' => 'Finance Director', 'email' => 'finance@opensinop.com', 'role' => $financeRole];
                $staffList[] = ['name' => 'System Administrator', 'email' => 'admin@opensinop.com', 'role' => $superAdminRole];
            }

            foreach ($staffList as $staff) {
                $user = User::firstOrCreate(['email' => $staff['email']], [
                    'name' => $staff['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'organization_id' => $org->id,
                ]);
                if (empty($user->organization_id)) {
                    $user->update(['organization_id' => $org->id]);
                }
                $user->assignRole($staff['role']);
            }

            User::query()->where('email', 'admin@admin.com')->whereNull('organization_id')->update(['organization_id' => $org->id]);

            // 2. Academic Term
            $term = AcademicTerm::firstOrCreate(['name' => 'Academic Term '.today()->year], [
                'starts_on' => today()->startOfYear(),
                'ends_on' => today()->endOfYear(),
            ]);

            // 3. Student Profiles
            $studentsData = [
                [
                    'name' => 'Juan Dela Cruz',
                    'email' => 'juan@eduflow.test',
                    'student_number' => 'STD-2026-001',
                    'program' => 'BS Information Technology',
                    'year_level' => 2,
                    'payout_address' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
                    'tuition_total' => 300000000,
                    'tuition_paid' => 0,
                    'bill_desc' => 'Laboratory Equipment & Capstone Access Fee',
                    'bill_amount' => 75_000000,
                ],
            ];

            if (! $isTesting) {
                $studentsData[] = [
                    'name' => 'Maria Santos',
                    'email' => 'maria@eduflow.test',
                    'student_number' => 'STD-2026-002',
                    'program' => 'BS Computer Engineering',
                    'year_level' => 3,
                    'payout_address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
                    'tuition_total' => 1500_000000,
                    'tuition_paid' => 500_000000,
                    'bill_desc' => 'Microcontroller Hardware Kit Fee',
                    'bill_amount' => 50_000000,
                ];
                $studentsData[] = [
                    'name' => 'Alex Rivera',
                    'email' => 'alex@eduflow.test',
                    'student_number' => 'STD-2026-003',
                    'program' => 'BS Data Science',
                    'year_level' => 1,
                    'payout_address' => '0x90F79bf6EB2c4f870365E785982E1f101E93b906',
                    'tuition_total' => 1000_000000,
                    'tuition_paid' => 0,
                    'bill_desc' => 'Cloud Cluster & Scientific Compute Fee',
                    'bill_amount' => 60_000000,
                ];
                $studentsData[] = [
                    'name' => 'Juan Dela Cruz (OpenSinop)',
                    'email' => 'juan@opensinop.com',
                    'student_number' => 'STD-2026-004',
                    'program' => 'BS Information Technology',
                    'year_level' => 2,
                    'payout_address' => '0x15d34AAf54267DB7D7c367839AAf71A00a2C6A65',
                    'tuition_total' => 1200_000000,
                    'tuition_paid' => 200_000000,
                    'bill_desc' => 'Laboratory Equipment & Capstone Access Fee',
                    'bill_amount' => 75_000000,
                ];
            }

            foreach ($studentsData as $item) {
                $u = User::firstOrCreate(['email' => $item['email']], [
                    'name' => $item['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'organization_id' => $org->id,
                ]);
                if (empty($u->organization_id)) {
                    $u->update(['organization_id' => $org->id]);
                }
                $u->assignRole($studentRole);

                $student = Student::firstOrCreate(
                    ['user_id' => $u->id],
                    [
                        'organization_id' => $org->id,
                        'student_number' => $item['student_number'],
                        'student_id_number' => $item['student_number'],
                        'program' => $item['program'],
                        'department' => $item['program'],
                        'year_level' => $item['year_level'],
                        'enrollment_status' => 'enrolled',
                        'academic_status' => 'qualified',
                        'attendance_rate' => 96,
                        'payout_address' => $item['payout_address'],
                    ]
                );

                StudentWallet::firstOrCreate(
                    ['student_id' => $student->id],
                    [
                        'address' => $item['payout_address'],
                        'provider' => 'circle',
                        'network' => 'arc',
                        'chain' => 'ARC-TESTNET',
                        'status' => 'active',
                        'generated_at' => now(),
                    ]
                );

                TuitionAccount::firstOrCreate(
                    [
                        'student_id' => $student->id,
                        'academic_term_id' => $term->id,
                    ],
                    [
                        'total_amount' => $item['tuition_total'],
                        'paid_amount' => $item['tuition_paid'],
                    ]
                );

                StudentBill::firstOrCreate(
                    [
                        'student_id' => $student->id,
                        'description' => $item['bill_desc'],
                    ],
                    [
                        'academic_term_id' => $term->id,
                        'category' => StudentBillCategory::LABORATORY,
                        'amount_base_units' => $item['bill_amount'],
                        'paid_base_units' => 0,
                        'due_date' => now()->addDays(30),
                        'status' => StudentBill::STATUS_OPEN,
                    ]
                );
            }

            // 4. Sample Emergency Assistance Request for supervisor / AI auditing
            $juanStudent = Student::query()->where('user_id', User::where('email', 'juan@eduflow.test')->value('id'))->first();
            if ($juanStudent) {
                AssistanceRequest::firstOrCreate(
                    ['submission_key' => 'eduflow-demo-emergency-aid'],
                    [
                        'ticket_number' => 'AST-DEMO01',
                        'student_id' => $juanStudent->id,
                        'academic_term_id' => $term->id,
                        'user_id' => $juanStudent->user_id,
                        'category' => AssistanceCategory::ACADEMIC,
                        'priority' => AssistancePriority::HIGH,
                        'status' => AssistanceStatus::SUBMITTED,
                        'subject' => 'Emergency tuition assistance for laboratory capstone materials',
                        'description' => 'Laboratory sensors and materials for the Arc smart contract capstone are due before term finals.',
                        'type' => 'emergency',
                        'requested_amount' => 15_000000,
                        'reason' => 'Course materials are required for completing the final assessment on Arc Testnet.',
                        'submitted_at' => now(),
                    ]
                );
            }
        });
    }
}
