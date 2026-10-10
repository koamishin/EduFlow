<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ArcBalanceObservation;
use App\Services\FundingWindowContext;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ApproveFundingWindow
{
    public function __construct(private InstallationInstitution $institutions, private ArcBalanceObservation $balances, private FundingWindowContext $context) {}

    public function handle(User $reviewer, FundingWindow $window, string $expectedDigest, string $reference): FundingWindowApproval
    {
        Gate::forUser($reviewer)->authorize('approve', $window);
        if (trim($reference) === '' || mb_strlen($reference) > 255) {
            throw ValidationException::withMessages(['reference' => 'Independent allocation, cash exclusions and exclusive treasury review reference required.']);
        }
        $institution = $this->institutions->require();
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($window->wallet_id)->firstOrFail();
        $balance = FundingWindowApproval::query()->where('organization_id', $institution->id)->exists() ? null : $this->balances->capture($wallet);

        return DB::transaction(function () use ($reviewer, $window, $expectedDigest, $reference, $institution, $balance): FundingWindowApproval {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var FundingWindow $stored */
            $stored = FundingWindow::query()->where('organization_id', $institution->id)->whereKey($window->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('approve', $stored);
            if (! $stored->hasValidSnapshot() || ! hash_equals($stored->snapshot_digest, $expectedDigest)) {
                throw ValidationException::withMessages(['expected_digest' => 'Exact intact funding window digest required.']);
            }
            /** @var FundingWindowApproval|null $existing */
            $existing = FundingWindowApproval::query()->where('organization_id', $institution->id)->first();
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($stored) || $existing->approved_by !== $reviewer->id || $existing->verification_reference !== $reference) {
                    throw ValidationException::withMessages(['funding' => 'Approved capacity already exists; conflicting review or refresh refused.']);
                }

                return $existing;
            }
            if ($balance === null) {
                throw ValidationException::withMessages(['funding' => 'Funding review state changed; fresh observation required.']);
            }
            $this->context->requireCurrent($reviewer, $stored, $balance);
            $approval = new FundingWindowApproval(['organization_id' => $institution->id, 'funding_window_id' => $stored->id,
                'approved_by' => $reviewer->id, 'verification_reference' => $reference, 'window_digest' => $stored->snapshot_digest]);
            $approval->approval_digest = PaymentIntent::digest($approval->content());
            $approval->save();
            activity('finance')->causedBy($reviewer)->performedOn($approval)->event('funding_window_approved')
                ->withProperties(['window_id' => $stored->id, 'approval_digest' => $approval->approval_digest, 'can_execute' => false])
                ->log('Exact capacity independently reviewed; no payment approval or external funds lock');

            return $approval;
        }, 3);
    }
}
