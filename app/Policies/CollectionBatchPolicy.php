<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CollectionBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class CollectionBatchPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, CollectionBatch $batch): bool
    {
        return $batch->exists && $this->create($user) && $batch->organization_id === $this->institutions->current()?->id;
    }

    public function review(User $user, CollectionBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->hasAnyRole(['admin', 'super_admin']) && $user->id !== $batch->prepared_by;
    }

    public function update(User $user, CollectionBatch $batch): bool
    {
        return false;
    }

    public function delete(User $user, CollectionBatch $batch): bool
    {
        return false;
    }
}
