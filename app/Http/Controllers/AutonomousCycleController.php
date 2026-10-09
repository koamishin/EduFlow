<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agents\EduFlowAgent;
use App\Http\Responses\AutonomousCycleResponse;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AutonomousCycleController extends Controller
{
    public function __invoke(Request $request, InstallationInstitution $institutions, EduFlowAgent $agent): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['super_admin', 'admin']), 403);

        $request->validate([
            'confirmed' => ['required', 'accepted'],
            'organization_id' => ['prohibited'],
            'amount' => ['prohibited'],
            'recipient_address' => ['prohibited'],
        ]);

        $institution = $institutions->current();
        abort_unless($institution instanceof Organization, 422, 'Institution context unavailable. Check installation configuration.');

        $primaryWallet = $institution->primaryWallet();
        abort_unless($primaryWallet instanceof Wallet, 422, 'Active institution wallet unavailable.');

        $runId = (string) Str::uuid();

        try {
            // Use shared storage even when the default cache is worker-local or failover.
            /** @var LockProvider $cacheStore */
            $cacheStore = Cache::store('database');
            $lock = $cacheStore->lock('eduflow:autonomous-cycle:'.$institution->id, 86400);
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            Log::error('Autonomous financial cycle coordination unavailable.', [
                'reference' => $runId,
                'organization_id' => $institution->id,
                'exception_type' => $exception::class,
            ]);

            abort(503, 'Cycle coordination unavailable. No cycle was started.');
        }

        abort_unless($acquired, 409, 'Another institution cycle is running or requires review after interruption.');

        return AutonomousCycleResponse::from($agent, $institution, $lock, $runId);
    }
}
