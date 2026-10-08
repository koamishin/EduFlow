<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class InvoiceVersionPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, InvoiceVersion $bill): bool
    {
        return $bill->exists && $this->create($user) && $this->institutions->current()?->id === $bill->organization_id;
    }

    public function review(User $user, InvoiceVersion $bill): bool
    {
        return $this->view($user, $bill) && $user->id !== $bill->prepared_by
            && $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function execute(User $user, InvoiceVersion $bill): bool
    {
        return false;
    }

    public function update(User $user, InvoiceVersion $bill): bool
    {
        return false;
    }

    public function delete(User $user, InvoiceVersion $bill): bool
    {
        return false;
    }
}
