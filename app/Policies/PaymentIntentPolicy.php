<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\InstallationInstitution;

class PaymentIntentPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, PaymentIntent $intent): bool
    {
        return $this->create($user) && $intent->organization_id === $this->institutions->current()?->id;
    }

    public function update(User $user, PaymentIntent $intent): bool
    {
        return false;
    }

    public function delete(User $user, PaymentIntent $intent): bool
    {
        return false;
    }

    public function execute(User $user, PaymentIntent $intent): bool
    {
        return false;
    }
}
