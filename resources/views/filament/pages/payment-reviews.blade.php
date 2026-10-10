<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Payment review — execution disabled</x-slot>
        <x-slot name="description">
            Review exact institution payment reservations with independent password and authenticator confirmation.
            Approval records bounded authority, not a transfer. Simulation evidence grants simulation-only authority.
        </x-slot>
        <x-filament::badge color="warning">Executor disabled — can_execute=false</x-filament::badge>
        <p>
            No transfers are made and no local payment state is changed here. Reservations hold application capacity only;
            external funds are not locked. Rejecting or holding a payment keeps its reservation held.
            Recorded approval never enables execution from this page. Reviewed payments are evidence-only;
            renewal requires a separate reviewed workflow.
        </p>
        <p>
            Reviewer enrollment independently pins an existing authenticator to protect against account factor replacement.
            Existing pins cannot be rotated here; changed factors require independent recovery.
            Enrollment grants no payment permission, approves no payment and makes no transfer.
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
