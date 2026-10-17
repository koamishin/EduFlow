<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleEnums;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreshInstallWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_page_detects_empty_database_and_prompts_institution_setup(): void
    {
        $this->assertEquals(0, Organization::count());

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('welcome')
            ->where('hasInstitutions', false)
            ->where('institutionCount', 0)
        );
    }

    public function test_console_redirects_to_institution_registration_when_no_institutions_exist(): void
    {
        $this->assertEquals(0, Organization::count());

        $response = $this->get('/console');

        $response->assertRedirect(route('institutions.register'));
    }

    public function test_console_redirects_to_login_when_institutions_exist_and_unauthenticated(): void
    {
        Organization::factory()->create();

        $response = $this->get('/console');

        $response->assertRedirect(route('login'));
    }

    public function test_student_registration_is_disabled_when_no_institutions_exist(): void
    {
        $this->assertEquals(0, Organization::count());

        $response = $this->get('/register');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('auth/register')
            ->where('hasInstitutions', false)
            ->has('institutions', 0)
        );

        // Attempting to post registration fails with validation error
        $postResponse = $this->post('/register', [
            'name' => 'John Doe',
            'email' => 'john@student.edu',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'student_id_number' => 'STU-9901',
            'department' => 'Computer Science',
            'wallet_address' => '0x71C845136D5620bB19b5e52331562b8A252E349A',
        ]);

        $postResponse->assertSessionHasErrors(['institution']);
    }

    public function test_student_login_page_renders_with_disabled_state_when_no_institutions_exist(): void
    {
        $this->assertEquals(0, Organization::count());

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('auth/login')
            ->where('hasInstitutions', false)
        );
    }

    public function test_new_institution_registration_redirects_to_first_application_setup(): void
    {
        $response = $this->post('/institutions/register', [
            'name' => 'Oxford Academy of Technology',
            'slug' => 'oxford-tech',
            'domain' => 'oxford-tech.edu',
            'country' => 'United Kingdom',
            'timezone' => 'Europe/London',
            'currency' => 'USDC',
            'use_agent_wallet' => true,
            'treasury_address' => '0x71C845136D5620bB19b5e52331562b8A252E349A',
            'admin_name' => 'Dean Harrison',
            'admin_email' => 'dean@oxford-tech.edu',
            'admin_password' => 'SecurePass123!@#',
            'admin_password_confirmation' => 'SecurePass123!@#',
        ]);

        $response->assertRedirect(route('institutions.setup'));

        $org = Organization::where('slug', 'oxford-tech')->first();
        $this->assertNotNull($org);
        $this->assertFalse($org->is_setup_completed);
        $this->assertAuthenticated();
    }

    public function test_first_application_setup_page_renders_with_institution_and_treasury_details(): void
    {
        $org = Organization::create([
            'name' => 'Imperial Science Institute',
            'slug' => 'imperial-science',
            'domain' => 'imperial.edu',
            'country' => 'United Kingdom',
            'timezone' => 'Europe/London',
            'type' => 'school',
            'currency' => 'USDC',
            'is_setup_completed' => false,
            'minimum_reserve' => 25.00,
            'max_auto_payment' => 60.00,
            'max_daily_disbursement' => 250.00,
            'human_approval_threshold' => 60.00,
        ]);

        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'provost@imperial.edu',
        ]);

        $adminRole = Role::firstOrCreate(['name' => RoleEnums::SUPER_ADMIN->value, 'guard_name' => 'web']);
        $admin->assignRole($adminRole);

        $response = $this->actingAs($admin)->get('/institutions/setup');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('institutions/setup')
            ->where('institution.name', 'Imperial Science Institute')
            ->where('institution.slug', 'imperial-science')
            ->where('institution.currency', 'USDC')
            ->where('institution.is_setup_completed', false)
            ->has('treasury')
            ->has('arcNetwork')
        );
    }

    public function test_completing_first_application_setup_marks_is_setup_completed_and_redirects_to_finance(): void
    {
        $org = Organization::create([
            'name' => 'Cambridge Polytechnic',
            'slug' => 'cambridge-poly',
            'domain' => 'cambridge.edu',
            'country' => 'United Kingdom',
            'timezone' => 'Europe/London',
            'type' => 'school',
            'currency' => 'USDC',
            'is_setup_completed' => false,
            'minimum_reserve' => 20.00,
            'max_auto_payment' => 50.00,
            'max_daily_disbursement' => 200.00,
            'human_approval_threshold' => 50.00,
        ]);

        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'bursar@cambridge.edu',
        ]);

        $response = $this->actingAs($admin)->post('/institutions/setup/complete', [
            'minimum_reserve' => 30.00,
            'max_auto_payment' => 75.00,
            'max_daily_disbursement' => 300.00,
        ]);

        $response->assertRedirect('/finance');

        $org->refresh();
        $this->assertTrue($org->is_setup_completed);
        $this->assertEquals(30.00, $org->minimum_reserve);
        $this->assertEquals(75.00, $org->max_auto_payment);
    }

    public function test_console_redirects_to_setup_if_incomplete_or_finance_if_complete(): void
    {
        $org = Organization::create([
            'name' => 'Edinburgh College',
            'slug' => 'edinburgh',
            'domain' => 'edinburgh.edu',
            'country' => 'United Kingdom',
            'timezone' => 'Europe/London',
            'type' => 'school',
            'currency' => 'USDC',
            'is_setup_completed' => false,
        ]);

        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'admin@edinburgh.edu',
        ]);

        $adminRole = Role::firstOrCreate(['name' => RoleEnums::ADMIN->value, 'guard_name' => 'web']);
        $admin->assignRole($adminRole);

        // When setup is not completed, /console routes to /institutions/setup
        $response = $this->actingAs($admin)->get('/console');
        $response->assertRedirect(route('institutions.setup'));

        // Once completed, /console routes to /finance
        $org->update(['is_setup_completed' => true]);
        $response2 = $this->actingAs($admin)->get('/console');
        $response2->assertRedirect('/finance');
    }

    public function test_student_can_register_and_login_once_institution_is_setup(): void
    {
        $org = Organization::create([
            'name' => 'Stanford University',
            'slug' => 'stanford',
            'domain' => 'stanford.edu',
            'country' => 'United States',
            'timezone' => 'America/Los_Angeles',
            'type' => 'school',
            'currency' => 'USDC',
            'is_setup_completed' => true,
        ]);

        // Registration page now has hasInstitutions = true
        $regResponse = $this->get('/register');
        $regResponse->assertOk();
        $regResponse->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('auth/register')
            ->where('hasInstitutions', true)
            ->has('institutions', 1)
        );

        // Student can register
        $postResponse = $this->post('/register', [
            'name' => 'Alice Walker',
            'email' => 'alice@stanford.edu',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
            'institution_id' => $org->id,
            'student_id_number' => 'STU-2026-STAN-001',
            'department' => 'Computer Science',
            'wallet_address' => '0x71C845136D5620bB19b5e52331562b8A252E349A',
        ]);

        $postResponse->assertRedirect(route('dashboard'));

        $studentUser = User::where('email', 'alice@stanford.edu')->first();
        $this->assertNotNull($studentUser);
        $this->assertEquals($org->id, $studentUser->organization_id);
        $this->assertTrue($studentUser->hasRole(RoleEnums::STUDENT->value));

        $student = Student::where('user_id', $studentUser->id)->first();
        $this->assertNotNull($student);
        $this->assertEquals('STU-2026-STAN-001', $student->student_id_number);
        $this->assertEquals('0x71C845136D5620bB19b5e52331562b8A252E349A', $student->payout_address);
    }
}
