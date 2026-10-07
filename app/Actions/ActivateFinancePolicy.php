<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ActivateFinancePolicy
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $reviewer, FinancePolicyVersion $policy, ?int $expectedActivationId): FinancePolicyActivation
    {
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $policy, $expectedActivationId, $institution): FinancePolicyActivation {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var FinancePolicyVersion $stored */
            $stored = FinancePolicyVersion::query()->where('organization_id', $institution->id)->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('activate', $stored);
            if (! $stored->hasValidContent()) {
                throw ValidationException::withMessages(['policy' => 'Policy content failed integrity verification.']);
            }
            $current = FinancePolicyActivation::current($institution->id);
            if ($current instanceof FinancePolicyActivation && ! $current->hasValidHistory()) {
                throw ValidationException::withMessages(['policy' => 'Current activation evidence failed integrity verification.']);
            }
            if ($current instanceof FinancePolicyActivation && $current->finance_policy_version_id === $stored->id) {
                if ($current->approved_by !== $reviewer->id || $current->previous_activation_id !== $expectedActivationId
                    || ! hash_equals($current->policy_digest, $stored->content_digest)) {
                    throw ValidationException::withMessages(['policy' => 'Policy activation retry conflicts with recorded review.']);
                }

                return $current;
            }
            if ($current?->id !== $expectedActivationId) {
                throw ValidationException::withMessages(['policy' => 'Current activation changed; review the replacement against the latest policy.']);
            }
            if (FinancePolicyActivation::query()->where('organization_id', $institution->id)->where('finance_policy_version_id', $stored->id)->exists()) {
                throw ValidationException::withMessages(['policy' => 'A previously activated policy cannot be reactivated; create a reviewed new version.']);
            }
            $activation = new FinancePolicyActivation([
                'organization_id' => $institution->id,
                'finance_policy_version_id' => $stored->id,
                'approved_by' => $reviewer->id,
                'previous_activation_id' => $current?->id,
                'previous_activation_digest' => $current?->activation_digest,
                'policy_digest' => $stored->content_digest,
            ]);
            $activation->activation_digest = PaymentIntent::digest($activation->content());
            $activation->save();
            activity('finance')->causedBy($reviewer)->performedOn($activation)->event('finance_policy_activated')
                ->withProperties(['policy_version_id' => $stored->id, 'previous_activation_id' => $current?->id, 'can_execute' => false])
                ->log('Finance policy activated by a separate reviewer; no payments enabled');

            return $activation;
        }, 3);
    }
}
