<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ConsoleRedirectController extends Controller
{
    /**
     * Handle the console redirection.
     */
    public function __invoke(Request $request): Response
    {
        // 1. If no institutions exist, direct to institution registration
        if (Organization::query()->count() === 0) {
            return redirect()->route('institutions.register')
                ->with('status', 'Please register your educational institution to initialize the campus treasury and launch the finance console.');
        }

        // 2. If unauthenticated, redirect to application login (never raw panel login)
        if (! Auth::check()) {
            return redirect()->route('login')
                ->with('status', 'Please log in to access the institution console.');
        }

        /** @var User $user */
        $user = Auth::user();

        // 3. If user is a student, direct to student dashboard
        if ($user->hasRole('student')) {
            return redirect()->route('dashboard');
        }

        // 4. Resolve the user's institution
        $org = null;
        if (! empty($user->organization_id)) {
            $org = Organization::query()->find($user->organization_id);
        }

        if (! $org) {
            $org = app(InstallationInstitution::class)->current();
        }

        // 5. For new institutions that haven't finished first-time setup, redirect to first application setup
        if ($org && ! $org->is_setup_completed) {
            return redirect()->route('institutions.setup');
        }

        // 6. Otherwise, launch finance console
        if ($request->header('X-Inertia')) {
            return Inertia::location(url('/finance'));
        }

        return redirect()->to('/finance');
    }
}
