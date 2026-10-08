<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BudgetSnapshot;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class BudgetSnapshotPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, BudgetSnapshot $snapshot): bool
    {
        return $snapshot->exists && $this->create($user) && $snapshot->organization_id === $this->institutions->current()?->id;
    }

    public function execute(User $user, BudgetSnapshot $snapshot): bool
    {
        return false;
    }

    public function update(User $user, BudgetSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $user, BudgetSnapshot $snapshot): bool
    {
        return false;
    }
}
