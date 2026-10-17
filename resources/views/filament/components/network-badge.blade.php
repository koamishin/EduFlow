@php
    $gateway = app(\Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class);
    $isMainnet = \App\Services\ArcNetworkGateway::isMainnetGateway($gateway);
    $networkName = $isMainnet ? 'Arc Mainnet' : 'Arc Testnet';
    $chainId = $gateway->chainId();
    $explorerUrl = $isMainnet
        ? config('lepton.explorer.mainnet', 'https://explorer.arc.network')
        : config('lepton.explorer.testnet', 'https://testnet.arcscan.io');
@endphp

<div class="flex items-center me-3">
    <a
        href="{{ $explorerUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        title="{{ $networkName }} (Chain ID: {{ $chainId }}) — Click to view block explorer"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full border transition-all duration-150 hover:scale-[1.03] focus:outline-none {{ $isMainnet ? 'border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-400' : 'border-amber-500/40 bg-amber-50 text-amber-700 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-400' }}"
    >
        <span class="relative flex h-2 w-2">
            <span class="absolute inline-flex h-full w-full rounded-full opacity-75 animate-ping {{ $isMainnet ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 {{ $isMainnet ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
        </span>
        <span class="font-mono text-[11px] font-medium tracking-tight">
            {{ $networkName }} ({{ $chainId }})
        </span>
        <svg class="h-3 w-3 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
        </svg>
    </a>
</div>
