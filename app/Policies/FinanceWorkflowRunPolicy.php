<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceWorkflowRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class FinanceWorkflowRunPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function viewAny(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, FinanceWorkflowRun $run): bool
    {
        return $run->exists && $this->viewAny($user) && $run->organization_id === $this->institutions->current()?->id;
    }

    public function review(User $user, FinanceWorkflowRun $run): bool
    {
        return $this->view($user, $run) && $user->hasAnyRole(['admin', 'super_admin']) && $run->kind === 'budget_plan'
            && $run->state === 'waiting_for_review' && $run->hasValidResult() && $run->budgetSnapshot !== null
            && $user->id !== $run->budgetSnapshot->prepared_by;
    }
}
