<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\Organization;
use App\Services\InstallationInstitution;

/**
 * Binds an operator to the installation institution, with optional aid tools.
 *
 * Container-resolving the agent can construct blank Eloquent models. Refuse
 * missing/ambiguous identity here instead of relying on a later wallet failure.
 */
final class SettlementOperatorFactory
{
    /**
     * Build an institution-bound operator even when student aid is not configured.
     */
    public static function make(?Organization $organization = null): ?SettlementOperator
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null || ($organization instanceof Organization && (! $organization->exists || $organization->id !== $institution->id))) {
            return null;
        }

        $organization = $institution;

        $fund = AssistanceFund::query()->where('organization_id', $organization->id)
            ->where('status', 'active')->orderBy('id')->first();
        $policyVersion = AssistancePolicyVersion::active($organization->id);

        if ($fund === null || ! $policyVersion instanceof AssistancePolicyVersion) {
            return SettlementOperator::make($organization);
        }

        return SettlementOperator::make($organization, $fund, $policyVersion);
    }

    /**
     * Build an operator or fail loudly.
     *
     * Used where continuing without one would silently skip a payment.
     *
     * @throws \RuntimeException when installation institution identity is unavailable
     */
    public static function makeOrFail(?Organization $organization = null): SettlementOperator
    {
        $operator = self::make($organization);

        if (! $operator instanceof SettlementOperator) {
            throw new \RuntimeException(
                'SettlementOperator needs a persisted organization matching the installation. '
                .'Check EDUFLOW_INSTITUTION_ID and single-institution setup.'
            );
        }

        return $operator;
    }
}
