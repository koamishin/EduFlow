<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TuitionAccount;
use App\Models\User;

class TuitionAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function view(User $user, TuitionAccount $tuitionAccount): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function update(User $user, TuitionAccount $tuitionAccount): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function delete(User $user, TuitionAccount $tuitionAccount): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function restore(User $user, TuitionAccount $tuitionAccount): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, TuitionAccount $tuitionAccount): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, TuitionAccount $tuitionAccount): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
