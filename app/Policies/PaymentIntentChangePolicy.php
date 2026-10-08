<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\User;
use App\Services\InstallationInstitution;

class PaymentIntentChangePolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function view(User $user, PaymentIntentChange $change): bool
    {
        $institution = $this->institutions->current();

        return $user->exists && $change->exists && $institution instanceof Organization
            && $change->organization_id === $institution->id
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function review(User $user, PaymentIntentChange $change): bool
    {
        /** @var PaymentIntent|null $source */
        $source = PaymentIntent::query()->where('organization_id', $change->organization_id)->find($change->payment_intent_id);

        return $this->view($user, $change) && $user->hasAnyRole(['admin', 'super_admin'])
            && $user->id !== $change->proposed_by && $source !== null && $user->id !== $source->prepared_by;
    }

    public function update(User $user, PaymentIntentChange $change): bool
    {
        return false;
    }

    public function delete(User $user, PaymentIntentChange $change): bool
    {
        return false;
    }
}
