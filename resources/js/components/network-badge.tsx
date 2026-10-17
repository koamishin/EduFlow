import { usePage } from '@inertiajs/react';
import { ExternalLink, Radio } from 'lucide-react';
import React from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface NetworkBadgeProps {
    className?: string;
    compact?: boolean;
}

export function NetworkBadge({ className = '', compact = false }: NetworkBadgeProps) {
    const page = usePage();
    const network = page.props.network as {
        isMainnet: boolean;
        name: string;
        chainCode: string;
        chainId: number;
        explorerUrl: string;
    } | undefined;

    const isMainnet = network?.isMainnet ?? false;
    const name = network?.name ?? (isMainnet ? 'Arc Mainnet' : 'Arc Testnet');
    const chainId = network?.chainId ?? (isMainnet ? 5040 : 5042);
    const explorerUrl = network?.explorerUrl ?? (isMainnet ? 'https://explorer.arc.network' : 'https://testnet.arcscan.io');

    const badgeContent = (
        <a
            href={explorerUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition-all hover:scale-[1.02] focus:outline-none ${
                isMainnet
                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 hover:border-emerald-500/50'
                    : 'border-amber-500/30 bg-amber-500/10 text-amber-400 hover:bg-amber-500/20 hover:border-amber-500/50'
            } ${className}`}
        >
            <span className="relative flex h-2 w-2">
                <span
                    className={`absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 ${
                        isMainnet ? 'bg-emerald-400' : 'bg-amber-400'
                    }`}
                />
                <span
                    className={`relative inline-flex h-2 w-2 rounded-full ${
                        isMainnet ? 'bg-emerald-500' : 'bg-amber-500'
                    }`}
                />
            </span>
            <span className="font-mono font-medium">
                {compact ? (isMainnet ? 'Mainnet' : 'Testnet') : `${name} (${chainId})`}
            </span>
            <ExternalLink className="size-2.5 opacity-60" />
        </a>
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {badgeContent}
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs">
                <p className="font-semibold">{name} (Chain ID: {chainId})</p>
                <p className="text-[11px] text-muted-foreground">Click to open Arcscan block explorer</p>
            </TooltipContent>
        </Tooltip>
    );
}
