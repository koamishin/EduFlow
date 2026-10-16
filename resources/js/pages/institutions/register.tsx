import { Head, Link, useForm } from '@inertiajs/react';
import {
    Building2,
    CheckCircle2,
    Globe,
    Layers,
    Lock,
    Network,
    Shield,
    Sparkles,
    Wallet as WalletIcon,
} from 'lucide-react';
import React, { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';

interface ArcNetworkInfo {
    chainId: number;
    name: string;
    rpc: string;
    explorer: string;
}

interface Props {
    defaultCurrency?: string;
    defaultTreasury?: string;
    arcNetworks?: {
        mainnet: ArcNetworkInfo;
        testnet: ArcNetworkInfo;
    };
}

export default function InstitutionRegister({
    defaultCurrency = 'USDC',
    defaultTreasury = '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA',
    arcNetworks = {
        mainnet: {
            chainId: 5040,
            name: 'Arc Mainnet',
            rpc: 'https://rpc.arc.network',
            explorer: 'https://explorer.arc.network',
        },
        testnet: {
            chainId: 5042,
            name: 'Arc Testnet',
            rpc: 'https://testnet-rpc.arc.network',
            explorer: 'https://testnet.arcscan.io',
        },
    },
}: Props) {
    const [useAgentWallet, setUseAgentWallet] = useState<boolean>(true);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
        domain: '',
        country: 'United States',
        timezone: 'UTC',
        currency: defaultCurrency,
        treasury_address: defaultTreasury,
        use_agent_wallet: true,
        admin_name: '',
        admin_email: '',
        admin_password: '',
        admin_password_confirmation: '',
    });

    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const name = e.target.value;
        const autoSlug = name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)+/g, '');
        setData((prev) => ({
            ...prev,
            name,
            slug: prev.slug === '' || prev.slug === autoSlug.slice(0, -1) ? autoSlug : prev.slug,
        }));
    };

    const handleToggleAgentWallet = (checked: boolean) => {
        setUseAgentWallet(checked);
        setData('use_agent_wallet', checked);
        if (checked && !data.treasury_address) {
            setData('treasury_address', defaultTreasury);
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/institutions/register');
    };

    return (
        <div className="bulletin clay-ambient min-h-screen w-full bg-[#0d0c0a] text-[#f5f1e8] py-10 px-4 sm:px-6 lg:px-8">
            <Head title="Register Institution" />

            <div className="mx-auto max-w-4xl space-y-8">
                {/* Header Header Banner */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[var(--clay-border,#2e2924)] pb-6">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="bulletin-eyebrow flex items-center gap-1.5 text-xs text-[#c4a46c] uppercase tracking-wider font-semibold">
                                <Building2 className="size-3.5" />
                                Multi-School Tenancy & Treasury
                            </span>
                            <Badge variant="outline" className="text-xs border-[#c4a46c]/40 text-[#c4a46c]">
                                Arc Powered
                            </Badge>
                        </div>
                        <h1 className="clay-h1 mt-2 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Deploy School Institution
                        </h1>
                        <p className="clay-body mt-2 text-sm text-[#b8b0a2] max-w-2xl">
                            Create a dedicated institution tenancy with isolated accounting, Arc settlement rails (Mainnet & Testnet), and automated Circle Agent treasury management.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Link
                            href="/finance"
                            className="inline-flex items-center gap-1.5 text-xs text-[#b8b0a2] hover:text-[#f5f1e8] transition-colors"
                        >
                            <Shield className="size-3.5" />
                            Return to Finance
                        </Link>
                    </div>
                </div>

                {/* Form Container */}
                <form onSubmit={handleSubmit} className="space-y-8">
                    {/* Step 1: Institution Details */}
                    <Card className="clay-card border-[var(--clay-border,#2e2924)] bg-[#141210]/95 shadow-xl">
                        <CardHeader className="pb-4 border-b border-[var(--clay-border,#2e2924)]">
                            <div className="flex items-center gap-2">
                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[#c4a46c]/20 text-xs font-bold text-[#c4a46c]">
                                    1
                                </span>
                                <CardTitle className="clay-title text-lg font-semibold text-white">
                                    Institution Identity
                                </CardTitle>
                            </div>
                            <CardDescription className="text-xs text-[#8f8577]">
                                Basic branding, subdomain slug, and regional settings for tenancy resolution.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-2 md:col-span-2">
                                <Label htmlFor="name" className="text-xs font-medium text-[#d6cebf]">
                                    School / Institution Name <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    value={data.name}
                                    onChange={handleNameChange}
                                    placeholder="e.g., St. Jude College of Health & Technology"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="slug" className="text-xs font-medium text-[#d6cebf]">
                                    Subdomain Slug <span className="text-red-400">*</span>
                                </Label>
                                <div className="flex rounded-md shadow-sm">
                                    <Input
                                        id="slug"
                                        name="slug"
                                        value={data.slug}
                                        onChange={(e) => setData('slug', e.target.value)}
                                        placeholder="st-jude"
                                        required
                                        className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c] rounded-r-none"
                                    />
                                    <span className="inline-flex items-center rounded-r-md border border-l-0 border-[var(--clay-border,#2e2924)] bg-[#24201a] px-3 text-xs text-[#8f8577]">
                                        .eduflow.internal
                                    </span>
                                </div>
                                <InputError message={errors.slug} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="domain" className="text-xs font-medium text-[#d6cebf]">
                                    Custom Domain (Optional)
                                </Label>
                                <Input
                                    id="domain"
                                    name="domain"
                                    value={data.domain}
                                    onChange={(e) => setData('domain', e.target.value)}
                                    placeholder="finance.stjude.edu"
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.domain} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="country" className="text-xs font-medium text-[#d6cebf]">
                                    Country <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="country"
                                    name="country"
                                    value={data.country}
                                    onChange={(e) => setData('country', e.target.value)}
                                    placeholder="e.g. Kenya, Philippines, United States"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.country} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="timezone" className="text-xs font-medium text-[#d6cebf]">
                                    Timezone <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="timezone"
                                    name="timezone"
                                    value={data.timezone}
                                    onChange={(e) => setData('timezone', e.target.value)}
                                    placeholder="UTC, Africa/Nairobi, Asia/Manila"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.timezone} />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 2: Arc Treasury Rails */}
                    <Card className="clay-card border-[var(--clay-border,#2e2924)] bg-[#141210]/95 shadow-xl">
                        <CardHeader className="pb-4 border-b border-[var(--clay-border,#2e2924)]">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[#c4a46c]/20 text-xs font-bold text-[#c4a46c]">
                                        2
                                    </span>
                                    <CardTitle className="clay-title text-lg font-semibold text-white">
                                        Arc Settlement Rails & Treasury
                                    </CardTitle>
                                </div>
                                <Badge className="bg-[#24201a] border border-[#c4a46c]/30 text-[#e6d8be] text-xs">
                                    Accounting Currency: USDC
                                </Badge>
                            </div>
                            <CardDescription className="text-xs text-[#8f8577]">
                                Choose autonomous Circle Agent wallet automation or specify an Arc cold/multisig treasury vault.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-6 space-y-6">
                            {/* Rail Specs Grid */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="rounded-xl border border-[var(--clay-border,#2e2924)] bg-[#191612] p-3 text-xs space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold text-white flex items-center gap-1.5">
                                            <Network className="size-3.5 text-[#c4a46c]" />
                                            {arcNetworks.mainnet.name}
                                        </span>
                                        <Badge variant="outline" className="text-[10px] border-emerald-500/40 text-emerald-400">
                                            Chain ID: {arcNetworks.mainnet.chainId}
                                        </Badge>
                                    </div>
                                    <p className="text-[#8f8577] text-[11px] truncate">RPC: {arcNetworks.mainnet.rpc}</p>
                                    <p className="text-[#8f8577] text-[11px] truncate">Explorer: {arcNetworks.mainnet.explorer}</p>
                                </div>

                                <div className="rounded-xl border border-[var(--clay-border,#2e2924)] bg-[#191612] p-3 text-xs space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold text-white flex items-center gap-1.5">
                                            <Network className="size-3.5 text-[#c4a46c]" />
                                            {arcNetworks.testnet.name}
                                        </span>
                                        <Badge variant="outline" className="text-[10px] border-amber-500/40 text-amber-400">
                                            Chain ID: {arcNetworks.testnet.chainId}
                                        </Badge>
                                    </div>
                                    <p className="text-[#8f8577] text-[11px] truncate">RPC: {arcNetworks.testnet.rpc}</p>
                                    <p className="text-[#8f8577] text-[11px] truncate">Explorer: {arcNetworks.testnet.explorer}</p>
                                </div>
                            </div>

                            {/* Wallet Option Toggle */}
                            <div className="flex items-center justify-between rounded-xl border border-[#c4a46c]/30 bg-[#1e1b16] p-4">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Sparkles className="size-4 text-[#c4a46c]" />
                                        <span className="text-sm font-semibold text-white">
                                            Autonomous Circle Agent Treasury Wallet
                                        </span>
                                        <Badge className="bg-[#c4a46c]/20 text-[#c4a46c] text-[10px] border-none">
                                            Recommended
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-[#a39886]">
                                        Automatically configures Circle Agent Stack smart contract wallet with gas abstraction and automated spending limits on Arc.
                                    </p>
                                </div>
                                <Switch
                                    checked={useAgentWallet}
                                    onCheckedChange={handleToggleAgentWallet}
                                    aria-label="Toggle Autonomous Circle Agent Wallet"
                                />
                            </div>

                            {/* Manual Address Input (if disabled or custom) */}
                            {!useAgentWallet ? (
                                <div className="space-y-2">
                                    <Label htmlFor="treasury_address" className="text-xs font-medium text-[#d6cebf]">
                                        Arc Treasury Address <span className="text-red-400">*</span>
                                    </Label>
                                    <div className="relative">
                                        <Input
                                            id="treasury_address"
                                            name="treasury_address"
                                            value={data.treasury_address}
                                            onChange={(e) => setData('treasury_address', e.target.value)}
                                            placeholder="0x..."
                                            required={!useAgentWallet}
                                            className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white font-mono text-xs focus:border-[#c4a46c] pl-8"
                                        />
                                        <WalletIcon className="absolute left-2.5 top-2.5 size-4 text-[#8f8577]" />
                                    </div>
                                    <InputError message={errors.treasury_address} />
                                </div>
                            ) : (
                                <div className="rounded-lg bg-[#181613] p-3 text-xs text-[#a39886] flex items-center justify-between border border-[var(--clay-border,#2e2924)]">
                                    <span className="flex items-center gap-2">
                                        <CheckCircle2 className="size-4 text-emerald-400" />
                                        Circle Agent stack will bind and manage treasury disbursements autonomously.
                                    </span>
                                    <span className="font-mono text-[11px] text-[#c4a46c] truncate max-w-[200px]">
                                        {defaultTreasury}
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 3: Administrator Account */}
                    <Card className="clay-card border-[var(--clay-border,#2e2924)] bg-[#141210]/95 shadow-xl">
                        <CardHeader className="pb-4 border-b border-[var(--clay-border,#2e2924)]">
                            <div className="flex items-center gap-2">
                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[#c4a46c]/20 text-xs font-bold text-[#c4a46c]">
                                    3
                                </span>
                                <CardTitle className="clay-title text-lg font-semibold text-white">
                                    Super Administrator Account
                                </CardTitle>
                            </div>
                            <CardDescription className="text-xs text-[#8f8577]">
                                Initial governance credential with immediate super_admin role and access to the Finance command hub.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-2">
                                <Label htmlFor="admin_name" className="text-xs font-medium text-[#d6cebf]">
                                    Administrator Name <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="admin_name"
                                    name="admin_name"
                                    value={data.admin_name}
                                    onChange={(e) => setData('admin_name', e.target.value)}
                                    placeholder="Dean / Provost / Chief Financial Officer"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.admin_name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="admin_email" className="text-xs font-medium text-[#d6cebf]">
                                    Administrator Email <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="admin_email"
                                    name="admin_email"
                                    type="email"
                                    value={data.admin_email}
                                    onChange={(e) => setData('admin_email', e.target.value)}
                                    placeholder="cfo@institution.edu"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.admin_email} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="admin_password" className="text-xs font-medium text-[#d6cebf]">
                                    Password <span className="text-red-400">*</span>
                                </Label>
                                <PasswordInput
                                    id="admin_password"
                                    name="admin_password"
                                    value={data.admin_password}
                                    onChange={(e) => setData('admin_password', e.target.value)}
                                    placeholder="Strong governance password"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.admin_password} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="admin_password_confirmation" className="text-xs font-medium text-[#d6cebf]">
                                    Confirm Password <span className="text-red-400">*</span>
                                </Label>
                                <PasswordInput
                                    id="admin_password_confirmation"
                                    name="admin_password_confirmation"
                                    value={data.admin_password_confirmation}
                                    onChange={(e) => setData('admin_password_confirmation', e.target.value)}
                                    placeholder="Repeat password"
                                    required
                                    className="bg-[#1c1915] border-[var(--clay-border,#2e2924)] text-white focus:border-[#c4a46c]"
                                />
                                <InputError message={errors.admin_password_confirmation} />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit Bar */}
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-[var(--clay-border,#2e2924)]">
                        <div className="text-xs text-[#8f8577]">
                            Baseline budget lines (Technology, Operations, Assistance, Equipment) will be initialized automatically.
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full sm:w-auto min-w-[240px] bg-[#c4a46c] text-[#141210] hover:bg-[#d8b982] font-semibold text-sm shadow-lg h-11"
                        >
                            {processing ? (
                                <>
                                    <Spinner className="mr-2 size-4 text-[#141210]" />
                                    Deploying Tenancy...
                                </>
                            ) : (
                                <>
                                    <Building2 className="mr-2 size-4" />
                                    Deploy Institution & Launch Finance
                                </>
                            )}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
