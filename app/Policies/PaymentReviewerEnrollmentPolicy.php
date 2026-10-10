<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class PaymentReviewerEnrollmentPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $user->hasAnyRole(['admin', 'super_admin'])
            && $this->institutions->current() instanceof Organization;
    }
}
