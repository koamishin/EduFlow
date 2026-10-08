<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Models\VendorDestinationVersion;
use App\Services\InstallationInstitution;

class VendorDestinationVersionPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, VendorDestinationVersion $destination): bool
    {
        return $destination->exists && $this->create($user) && $destination->organization_id === $this->institutions->current()?->id;
    }

    public function approve(User $user, VendorDestinationVersion $destination): bool
    {
        return $this->view($user, $destination) && $user->id !== $destination->prepared_by && $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function update(User $user, VendorDestinationVersion $destination): bool
    {
        return false;
    }

    public function delete(User $user, VendorDestinationVersion $destination): bool
    {
        return false;
    }

    public function execute(User $user, VendorDestinationVersion $destination): bool
    {
        return false;
    }
}
