<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionSetupController extends Controller
{
    /**
     * Display the first-time application setup wizard.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $org = null;
        if (! empty($user->organization_id)) {
            $org = Organization::query()->find($user->organization_id);
        }

        if (! $org) {
            $org = app(InstallationInstitution::class)->current();
        }

        if (! $org) {
            return redirect()->route('institutions.register')
                ->with('status', 'Please register your educational institution first.');
        }

        $wallet = $org->wallets()->first();
        $budgets = $org->budgets()->get(['id', 'name', 'category', 'allocated_amount']);

        return Inertia::render('institutions/setup', [
            'institution' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'domain' => $org->domain,
                'country' => $org->country,
                'timezone' => $org->timezone,
                'currency' => $org->currency ?? 'USDC',
                'minimum_reserve' => $org->minimum_reserve,
                'max_auto_payment' => $org->max_auto_payment,
                'max_daily_disbursement' => $org->max_daily_disbursement,
                'human_approval_threshold' => $org->human_approval_threshold,
                'is_setup_completed' => (bool) $org->is_setup_completed,
            ],
            'treasury' => [
                'address' => $wallet?->address ?? config('lepton.arc.treasury') ?? '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA',
                'provider' => $wallet?->provider ?? 'circle',
                'network' => $wallet?->network ?? 'arc',
                'status' => $wallet?->status ?? 'active',
            ],
            'budgets' => $budgets,
            'studentRegistrationUrl' => url('/register?institution='.$org->slug),
            'arcNetwork' => [
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
            'policyTemplates' => app(InstitutionPolicyTemplates::class)->all($org),
        ]);
    }

    /**
     * Mark the first-time application setup as completed and launch the console.
     */
    public function complete(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $org = null;
        if (! empty($user->organization_id)) {
            $org = Organization::query()->find($user->organization_id);
        }

        if (! $org) {
            $org = app(InstallationInstitution::class)->current();
        }

        if (! $org) {
            return redirect()->route('institutions.register');
        }

        $validated = $request->validate([
            'minimum_reserve' => ['nullable', 'numeric', 'min:0'],
            'max_auto_payment' => ['nullable', 'numeric', 'min:0'],
            'max_daily_disbursement' => ['nullable', 'numeric', 'min:0'],
            'policy_template' => ['nullable', 'string'],
            'budgets' => ['nullable', 'array'],
            'budgets.*.id' => ['required', 'integer'],
            'budgets.*.allocated_amount' => ['required', 'numeric', 'min:0'],
        ]);

        if (isset($validated['minimum_reserve'])) {
            $org->minimum_reserve = (float) $validated['minimum_reserve'];
        }

        if (isset($validated['max_auto_payment'])) {
            $org->max_auto_payment = (float) $validated['max_auto_payment'];
        }

        if (isset($validated['max_daily_disbursement'])) {
            $org->max_daily_disbursement = (float) $validated['max_daily_disbursement'];
        } elseif (isset($validated['max_auto_payment']) && (float) $org->max_daily_disbursement < (float) $validated['max_auto_payment']) {
            $org->max_daily_disbursement = max((float) $validated['max_auto_payment'] * 10, 1000.0);
        }

        if (! empty($validated['budgets'])) {
            foreach ($validated['budgets'] as $bData) {
                $b = Budget::query()->where('organization_id', $org->id)->find($bData['id']);
                if ($b) {
                    $b->allocated_amount = (float) $bData['allocated_amount'];
                    $b->remaining_amount = max(0.0, (float) $bData['allocated_amount'] - (float) $b->spent_amount);
                    $b->save();
                }
            }
        }

        $org->is_setup_completed = true;
        $org->save();

        // Automatically activate verified policy template so JEV is operational from day 1
        $templateKey = $validated['policy_template'] ?? InstitutionPolicyTemplates::TEMPLATE_STANDARD;
        $controlOverrides = [];
        if (isset($validated['minimum_reserve']) && is_numeric($validated['minimum_reserve'])) {
            $controlOverrides['minimum_reserve'] = (string) $validated['minimum_reserve'];
        }
        if (isset($validated['max_auto_payment']) && is_numeric($validated['max_auto_payment'])) {
            $controlOverrides['max_auto_payment'] = (string) $validated['max_auto_payment'];
        }
        if (isset($validated['max_daily_disbursement']) && is_numeric($validated['max_daily_disbursement'])) {
            $controlOverrides['max_daily_disbursement'] = (string) $validated['max_daily_disbursement'];
        } elseif (isset($controlOverrides['max_auto_payment'])) {
            $controlOverrides['max_daily_disbursement'] = (string) max((float) $controlOverrides['max_auto_payment'] * 10, 1000.0);
        }

        try {
            app(InstitutionPolicyTemplates::class)->applyAndActivate(
                $user,
                $org,
                $templateKey,
                $controlOverrides,
                autoPublish: true,
                reason: "Initial setup activation of {$templateKey} policy for {$org->name}."
            );
        } catch (\Throwable $e) {
            report($e);
        }

        session()->flash('status', 'Institution setup completed successfully! Welcome to your OpenSinop Finance Console.');

        return Inertia::location(url('/finance'));
    }
}
