<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinancePolicyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class FinancePolicyVersionPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function activate(User $user, FinancePolicyVersion $policy): bool
    {
        return $user->exists && $policy->exists && $this->institutions->current()?->id === $policy->organization_id
            && $user->id !== $policy->created_by && $user->hasAnyRole(['admin', 'super_admin']);
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
