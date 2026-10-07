<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function view(User $user, Student $student): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function update(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function restore(User $user, Student $student): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Student $student): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Student $student): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
