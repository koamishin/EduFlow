<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinancePolicyVersion;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class FinancePolicyVersionPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $this->institutions->current($user) instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function activate(User $user, FinancePolicyVersion $policy): bool
    {
        $org = $this->institutions->current($user);
        if (! $user->exists || ! $policy->exists || $org?->id !== $policy->organization_id) {
            return false;
        }

        if (! $user->hasAnyRole(['admin', 'super_admin'])) {
            return false;
        }

        $staffCount = User::query()
            ->where('organization_id', $policy->organization_id)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin', 'finance_officer'])->where('guard_name', 'web'))
            ->count();

        if ($staffCount > 1 && $user->id === $policy->created_by && ! $user->hasRole('super_admin')) {
            $isTemplate = InstitutionPolicyVersion::query()
                ->where('finance_policy_version_id', $policy->id)
                ->where(function ($query): void {
                    $templates = [
                        'Standard Academic Operations',
                        'Campus Fest & Student Activities',
                        'STEM Labs & Research Procurement',
                        'Autonomous Fast-Track Operations',
                        'Strict Fiscal Governance',
                    ];
                    foreach ($templates as $title) {
                        $query->orWhere('title', 'like', "%{$title}%");
                    }
                })
                ->exists();

            if (! $isTemplate) {
                return false;
            }
        }

        return true;
    }

    public function update(User $user, FinancePolicyVersion $policy): bool
    {
        return false;
    }

    public function delete(User $user, FinancePolicyVersion $policy): bool
    {
        return false;
    }
}
