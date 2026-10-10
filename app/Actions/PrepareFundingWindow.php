<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\BudgetSnapshot;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ArcBalanceObservation;
use App\Services\FundingWindowContext;
use App\Services\InstallationInstitution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PrepareFundingWindow
{
    public function __construct(private InstallationInstitution $institutions, private ArcBalanceObservation $balances, private FundingWindowContext $context) {}

    public function handle(User $actor, BudgetSnapshot $budget, Wallet $wallet, string $key, string $validUntil): FundingWindow
    {
        Gate::forUser($actor)->authorize('create', FundingWindow::class);
        Gate::forUser($actor)->authorize('view', $budget);
        Validator::make(['key' => $key, 'valid_until' => $validUntil], ['key' => ['required', 'uuid'],
            'valid_until' => ['required', 'date_format:Y-m-d\TH:i:sP']])->validate();
        $institution = $this->institutions->require();
        $key = Str::lower($key);
        /** @var Wallet $storedWallet */
        $storedWallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($wallet->id)->firstOrFail();
        // External reads precede database locks. No signing or transfer calls occur here.
        $balance = FundingWindow::query()->where('request_key', $key)->exists() ? null : $this->balances->capture($storedWallet);

        return DB::transaction(function () use ($actor, $budget, $storedWallet, $key, $validUntil, $institution, $balance): FundingWindow {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', FundingWindow::class);
            /** @var BudgetSnapshot $storedBudget */
            $storedBudget = BudgetSnapshot::query()->where('organization_id', $institution->id)->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            /** @var Wallet $lockedWallet */
            $lockedWallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($storedWallet->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals(FundingWindowContext::walletFingerprint($storedWallet), FundingWindowContext::walletFingerprint($lockedWallet))) {
                throw ValidationException::withMessages(['wallet' => 'Treasury changed during observation; recapture funding evidence.']);
            }
            /** @var FundingWindow|null $existing */
            $existing = FundingWindow::query()->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->organization_id !== $institution->id || $existing->budget_snapshot_id !== $storedBudget->id
                    || $existing->wallet_id !== $lockedWallet->id || $existing->snapshot['valid_until'] !== $validUntil
                    || $existing->prepared_by !== $actor->id || ! $existing->hasValidSnapshot()) {
                    throw ValidationException::withMessages(['key' => 'Funding identity already binds different evidence.']);
                }

                return $existing;
            }
            if (FundingWindowApproval::query()->where('organization_id', $institution->id)->exists()) {
                throw ValidationException::withMessages(['funding' => 'An approved institution window already exists; refresh cannot reset reserved capacity. Reviewed rollover is required.']);
            }
            if ($balance === null || Carbon::parse($balance['observed_at'])->lt(now()->subSeconds(30))) {
                throw ValidationException::withMessages(['funding' => 'Balance observation expired while waiting for locks; recapture evidence.']);
            }
            $snapshot = $this->context->build($actor, $storedBudget, $lockedWallet, $balance, $key, $validUntil);
            /** @var FundingWindow $window */
            $window = FundingWindow::query()->create(['request_key' => $key, 'organization_id' => $institution->id,
                'budget_snapshot_id' => $storedBudget->id, 'budget_id' => $storedBudget->budget_id, 'wallet_id' => $lockedWallet->id,
                'finance_policy_activation_id' => $snapshot['policy_activation_id'], 'prepared_by' => $actor->id,
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            activity('finance')->causedBy($actor)->performedOn($window)->event('funding_window_prepared')
                ->withProperties(['snapshot_digest' => $window->snapshot_digest, 'is_fake' => $balance['is_fake'], 'can_execute' => false])
                ->log('Exact department allocation and Arc cash observation prepared for independent review');

            return $window;
        }, 3);
    }
}
