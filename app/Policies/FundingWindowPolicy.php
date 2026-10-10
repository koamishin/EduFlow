<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BudgetSnapshot;
use App\Models\FundingWindow;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class FundingWindowPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, FundingWindow $window): bool
    {
        return $window->exists && $this->create($user) && $window->organization_id === $this->institutions->current()?->id;
    }

    public function approve(User $user, FundingWindow $window): bool
    {
        /** @var BudgetSnapshot|null $budget */
        $budget = BudgetSnapshot::query()->find($window->budget_snapshot_id);

        return $this->view($user, $window) && $user->hasAnyRole(['admin', 'super_admin']) && $user->id !== $window->prepared_by
            && $budget !== null && $user->id !== $budget->prepared_by;
    }

    public function update(User $user, FundingWindow $window): bool
    {
        return false;
    }

    public function delete(User $user, FundingWindow $window): bool
    {
        return false;
    }
}
