<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GuardSingleInstitution;
use App\Enums\RoleEnums;
use App\Models\Budget;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstitutionPolicyWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class InstitutionRegistrationController extends Controller
{
    /**
     * Display the institution registration form.
     */
    public function create(): Response
    {
        return Inertia::render('institutions/register', [
            'defaultCurrency' => 'USDC',
            'defaultTreasury' => (string) (config('lepton.arc.treasury') ?: '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA'),
            'arcNetworks' => [
                'mainnet' => [
                    'chainId' => 5040,
                    'name' => 'Arc Mainnet',
                    'rpc' => 'https://rpc.arc.network',
                    'explorer' => 'https://explorer.arc.network',
                ],
                'testnet' => [
                    'chainId' => 5042,
                    'name' => 'Arc Testnet',
                    'rpc' => 'https://testnet-rpc.arc.network',
                    'explorer' => 'https://testnet.arcscan.io',
                ],
            ],
        ]);
    }

    /**
     * Handle an incoming institution registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:organizations,name'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:organizations,slug'],
            'domain' => ['nullable', 'string', 'max:255', 'unique:organizations,domain'],
            'country' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'in:USDC'],
            'use_agent_wallet' => ['nullable', 'boolean'],
            'treasury_address' => $request->boolean('use_agent_wallet')
                ? ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/']
                : ['required', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        [$org, $admin] = GuardSingleInstitution::withoutGuard(function () use ($validated, $request): array {
            return DB::transaction(function () use ($validated, $request): array {
                $slug = ! empty($validated['slug'])
                    ? Str::slug($validated['slug'])
                    : Str::slug($validated['name']);

                $domain = ! empty($validated['domain'])
                    ? $validated['domain']
                    : ($slug.'.eduflow.internal');

                $org = Organization::create([
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'domain' => $domain,
                    'country' => $validated['country'],
                    'timezone' => $validated['timezone'],
                    'type' => 'school',
                    'currency' => 'USDC',
                    'minimum_reserve' => 20.00,
                    'max_auto_payment' => 50.00,
                    'max_daily_disbursement' => 200.00,
                    'human_approval_threshold' => 50.00,
                ]);

                $useAgentWallet = $request->boolean('use_agent_wallet');
                $treasuryAddress = $useAgentWallet
                    ? (string) (! empty($validated['treasury_address']) ? $validated['treasury_address'] : (config('lepton.arc.treasury') ?: '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA'))
                    : (string) $validated['treasury_address'];

                Wallet::create([
                    'organization_id' => $org->id,
                    'address' => $treasuryAddress,
                    'provider' => $useAgentWallet ? 'circle' : 'manual',
                    'network' => 'arc',
                    'balance' => 0.00,
                    'status' => 'active',
                ]);

                // Initialize baseline categories & budgets
                $baselineBudgets = [
                    ['name' => 'Technology & Cloud', 'category' => 'technology', 'amount' => 400.00],
                    ['name' => 'School Operations', 'category' => 'operations', 'amount' => 1000.00],
                    ['name' => 'Student Assistance & Aid', 'category' => 'assistance', 'amount' => 200.00],
                    ['name' => 'Campus Equipment & Lab', 'category' => 'equipment', 'amount' => 500.00],
                ];

                foreach ($baselineBudgets as $b) {
                    Budget::create([
                        'organization_id' => $org->id,
                        'name' => $b['name'],
                        'category' => $b['category'],
                        'allocated_amount' => $b['amount'],
                        'spent_amount' => 0.00,
                        'remaining_amount' => $b['amount'],
                        'status' => 'active',
                    ]);
                }

                $superAdminRole = Role::firstOrCreate([
                    'name' => RoleEnums::SUPER_ADMIN->value,
                    'guard_name' => 'web',
                ]);

                $admin = User::create([
                    'name' => $validated['admin_name'],
                    'email' => $validated['admin_email'],
                    'password' => Hash::make($validated['admin_password']),
                    'organization_id' => $org->id,
                ]);

                $admin->forceFill(['email_verified_at' => now()])->save();

                $admin->assignRole($superAdminRole);

                return [$org, $admin];
            });
        });

        // Set active session for the created institution
        $request->session()->put('current_organization_id', $org->id);
        Auth::login($admin);

        // Attempt baseline policy initialization
        try {
            $template = DefaultInstitutionPolicy::forInstitution($org);
            $template['request_key'] = (string) Str::uuid();
            $template['controls'] = [
                'minimum_reserve' => '0',
                'max_auto_payment' => '0',
                'max_daily_disbursement' => '0',
                'max_fee' => '0',
            ];

            app(InstitutionPolicyWorkspace::class)->save($admin, $template);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->route('institutions.setup');
    }
}
