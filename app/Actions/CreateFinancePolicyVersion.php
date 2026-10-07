<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\FinancePolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class CreateFinancePolicyVersion
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $actor, string $version, Money $reserve, Money $autoLimit, Money $dailyLimit, Money $maxFee): FinancePolicyVersion
    {
        Gate::forUser($actor)->authorize('create', FinancePolicyVersion::class);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $version) !== 1) {
            throw ValidationException::withMessages(['policy' => 'Policy version needs a stable 1-64 character identifier.']);
        }
        foreach ([$reserve, $autoLimit, $dailyLimit, $maxFee] as $amount) {
            if ($amount->currency !== CurrencyCode::USDC || $amount->minorUnits < 0) {
                throw ValidationException::withMessages(['policy' => 'Policy amounts must be exact non-negative USDC.']);
            }
        }
        if ($autoLimit->minorUnits > $dailyLimit->minorUnits) {
            throw ValidationException::withMessages(['policy' => 'Automatic limit cannot exceed daily limit.']);
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($institution, $actor, $version, $reserve, $autoLimit, $dailyLimit, $maxFee): FinancePolicyVersion {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $policy = new FinancePolicyVersion([
                'organization_id' => $institution->id, 'version' => $version, 'created_by' => $actor->id,
                'minimum_reserve_base_units' => $reserve->minorUnits, 'max_auto_payment_base_units' => $autoLimit->minorUnits,
                'max_daily_disbursement_base_units' => $dailyLimit->minorUnits, 'max_fee_base_units' => $maxFee->minorUnits,
            ]);
            $policy->content_digest = PaymentIntent::digest($policy->content());
            /** @var FinancePolicyVersion|null $existing */
            $existing = FinancePolicyVersion::query()->where('organization_id', $institution->id)->where('version', $version)->first();
            if ($existing !== null) {
                if (! $existing->hasValidContent() || ! hash_equals($existing->content_digest, $policy->content_digest)) {
                    throw ValidationException::withMessages(['policy' => 'Policy version already exists with different content or preparer.']);
                }

                return $existing;
            }
            $policy->save();
            activity('finance')->causedBy($actor)->performedOn($policy)->event('finance_policy_created')
                ->withProperties(['version' => $version, 'active' => false, 'can_execute' => false])->log('Immutable finance policy prepared; activation required');

            return $policy;
        }, 3);
    }
}
