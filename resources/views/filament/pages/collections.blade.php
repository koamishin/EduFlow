<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Evidence capture and independent review only</x-slot>
        <x-slot name="description">
            Capture closed intervals of received fees. A different authorized reviewer verifies source evidence and restrictions.
        </x-slot>
        <x-filament::badge color="warning">No payment authority</x-filament::badge>
        <p>
            Capturing or approving receipts does not post cash, allocate student payments, verify a bank balance,
            fund Arc settlement or execute a transfer. Amounts retain their original currency and full precision.
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
