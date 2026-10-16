import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    Check,
    CheckCircle2,
    Copy,
    DollarSign,
    ExternalLink,
    GraduationCap,
    Layers,
    Shield,
    Sparkles,
    Wallet as WalletIcon,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

interface Budget {
    id: number;
    name: string;
    category: string;
    allocated_amount: number;
}

interface Institution {
    id: number;
    name: string;
    slug: string;
    domain: string;
    country: string;
    timezone: string;
    currency: string;
    minimum_reserve: number;
    max_auto_payment: number;
    max_daily_disbursement: number;
    human_approval_threshold: number;
    is_setup_completed: boolean;
}

interface Treasury {
    address: string;
    provider: string;
    network: string;
    status: string;
}

interface NetworkConfig {
    chainId: number;
    name: string;
    rpc: string;
    explorer: string;
}

interface Props {
    institution: Institution;
    treasury: Treasury;
    budgets: Budget[];
    studentRegistrationUrl: string;
    arcNetwork: {
        mainnet: NetworkConfig;
        testnet: NetworkConfig;
    };
}

export default function InstitutionSetup({
    institution,
    treasury,
    budgets: initialBudgets,
    studentRegistrationUrl,
    arcNetwork,
}: Props) {
    const [budgets, setBudgets] = useState<Budget[]>(initialBudgets);
    const [minReserve, setMinReserve] = useState<number>(institution.minimum_reserve || 20);
    const [maxAutoPayment, setMaxAutoPayment] = useState<number>(institution.max_auto_payment || 50);
    const [copiedUrl, setCopiedUrl] = useState<boolean>(false);
    const [copiedAddress, setCopiedAddress] = useState<boolean>(false);
    const [submitting, setSubmitting] = useState<boolean>(false);

    const handleBudgetChange = (id: number, value: string) => {
        const val = parseFloat(value) || 0;
        setBudgets(budgets.map((b) => (b.id === id ? { ...b, allocated_amount: val } : b)));
    };

    const copyToClipboard = (text: string, type: 'url' | 'address') => {
        navigator.clipboard.writeText(text);
        if (type === 'url') {
            setCopiedUrl(true);
            setTimeout(() => setCopiedUrl(false), 2000);
        } else {
            setCopiedAddress(true);
            setTimeout(() => setCopiedAddress(false), 2000);
        }
    };

    const handleComplete = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        router.post('/institutions/setup/complete', {
            minimum_reserve: minReserve,
            max_auto_payment: maxAutoPayment,
            budgets: budgets.map((b) => ({
                id: b.id,
                allocated_amount: b.allocated_amount,
            })),
        }, {
            onFinish: () => setSubmitting(false),
        });
    };

    const totalAllocated = budgets.reduce((acc, b) => acc + (Number(b.allocated_amount) || 0), 0);

    return (
        <div className="min-h-screen bg-[#0d0c0b] text-[#ede8e1] selection:bg-[#c4a46c]/30 selection:text-[#f3eee7] font-sans antialiased">
            <Head title={`Setup ${institution.name} — OpenSinop`} />

            {/* Top Navigation Bar */}
            <header className="border-b border-stone-800/80 bg-[#141210]/80 backdrop-blur-md sticky top-0 z-50">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 h-16 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-xl bg-[#c4a46c] text-stone-950 font-serif font-black text-lg shadow-sm">
                            S
                        </div>
                        <div>
                            <span className="font-serif font-bold text-base tracking-tight text-white block">
                                {institution.name}
                            </span>
                            <span className="text-[11px] font-mono text-stone-400">
                                OpenSinop First Application Setup
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Badge variant="outline" className="text-[11px] font-mono border-amber-500/30 bg-amber-500/10 text-amber-400">
                            Setup Wizard · 4 Steps
                        </Badge>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 sm:px-6 py-10 sm:py-14 space-y-10">
                {/* Hero Greeting */}
                <div className="space-y-3">
                    <div className="inline-flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-mono text-amber-400">
                        <Sparkles className="size-3.5" />
                        <span>INSTITUTION INITIALIZATION COMPLETE</span>
                    </div>
                    <h1 className="font-serif text-3xl sm:text-4xl font-bold tracking-tight text-white">
                        Welcome to OpenSinop, {institution.name}.
                    </h1>
                    <p className="text-sm sm:text-base text-stone-400 max-w-2xl leading-relaxed">
                        Your sovereign school treasury, autonomous JEV finance agent, and multi-school tenancy are provisioned. Review and confirm your first-time application configuration below before opening your finance console.
                    </p>
                </div>

                <form onSubmit={handleComplete} className="space-y-8">
                    {/* Section 1: Institution Profile & Identity */}
                    <Card className="border-stone-800/80 bg-[#151311]/90 shadow-sm">
                        <CardHeader className="pb-4 border-b border-stone-800/60">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <CardTitle className="text-base font-serif font-bold text-white flex items-center gap-2">
                                        <Building2 className="size-4.5 text-[#c4a46c]" />
                                        1. Institution Profile & Identity
                                    </CardTitle>
                                    <CardDescription className="text-xs text-stone-400">
                                        Academic domain, sovereign tenancy context, and accounting currency.
                                    </CardDescription>
                                </div>
                                <Badge className="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px]">
                                    Verified Active
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                            <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-3.5 space-y-1">
                                <span className="font-mono text-[10px] text-stone-400 uppercase tracking-wider block">School Slug</span>
                                <span className="font-mono font-semibold text-white text-sm">{institution.slug}</span>
                            </div>
                            <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-3.5 space-y-1">
                                <span className="font-mono text-[10px] text-stone-400 uppercase tracking-wider block">Country / Timezone</span>
                                <span className="font-medium text-white text-sm">{institution.country} · {institution.timezone}</span>
                            </div>
                            <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-3.5 space-y-1">
                                <span className="font-mono text-[10px] text-stone-400 uppercase tracking-wider block">Accounting Currency</span>
                                <span className="font-mono font-bold text-emerald-400 text-sm">{institution.currency}</span>
                            </div>
                            <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-3.5 space-y-1">
                                <span className="font-mono text-[10px] text-stone-400 uppercase tracking-wider block">Campus Domain</span>
                                <span className="font-mono text-stone-300 text-sm truncate block">{institution.domain}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 2: Department Budgets */}
                    <Card className="border-stone-800/80 bg-[#151311]/90 shadow-sm">
                        <CardHeader className="pb-4 border-b border-stone-800/60">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <CardTitle className="text-base font-serif font-bold text-white flex items-center gap-2">
                                        <Layers className="size-4.5 text-[#c4a46c]" />
                                        2. Baseline Department Budgets
                                    </CardTitle>
                                    <CardDescription className="text-xs text-stone-400">
                                        Allocated operational categories. JEV uses these to automatically review bills and authorize payment proposals.
                                    </CardDescription>
                                </div>
                                <div className="text-right">
                                    <span className="text-[10px] font-mono text-stone-400 uppercase tracking-wider block">Total Allocated</span>
                                    <span className="text-sm font-mono font-bold text-white">{totalAllocated.toFixed(2)} {institution.currency}</span>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {budgets.map((budget) => (
                                    <div key={budget.id} className="rounded-xl border border-stone-800 bg-stone-900/40 p-4 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="font-medium text-xs text-white">{budget.name}</span>
                                            <Badge variant="outline" className="text-[10px] font-mono border-stone-700 text-stone-400">
                                                {budget.category}
                                            </Badge>
                                        </div>
                                        <div className="flex items-center gap-2 pt-1">
                                            <Label htmlFor={`budget-${budget.id}`} className="text-xs text-stone-400 shrink-0 font-mono">
                                                Allocation ({institution.currency}):
                                            </Label>
                                            <Input
                                                id={`budget-${budget.id}`}
                                                type="number"
                                                step="10"
                                                min="0"
                                                value={budget.allocated_amount}
                                                onChange={(e) => handleBudgetChange(budget.id, e.target.value)}
                                                className="h-8 font-mono text-xs bg-stone-950 border-stone-700 text-white"
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 3: Treasury Rails & Autonomous Policy Safeguards */}
                    <Card className="border-stone-800/80 bg-[#151311]/90 shadow-sm">
                        <CardHeader className="pb-4 border-b border-stone-800/60">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <CardTitle className="text-base font-serif font-bold text-white flex items-center gap-2">
                                        <Shield className="size-4.5 text-[#c4a46c]" />
                                        3. Autonomous Arc Treasury & Safety Guardrails
                                    </CardTitle>
                                    <CardDescription className="text-xs text-stone-400">
                                        Arc blockchain settlement rails and JEV safety buffer settings.
                                    </CardDescription>
                                </div>
                                <Badge className="bg-amber-500/20 text-amber-400 border border-amber-500/30 text-[10px]">
                                    Arc Network
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-5">
                            {/* Treasury Address Display */}
                            <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div className="space-y-1">
                                    <span className="text-[11px] font-mono text-stone-400 uppercase tracking-wider flex items-center gap-1.5">
                                        <WalletIcon className="size-3.5 text-[#c4a46c]" />
                                        School Treasury Address ({treasury.provider === 'circle' ? 'Autonomous Circle Agent Wallet' : 'Manual EVM Multisig'})
                                    </span>
                                    <span className="font-mono text-xs text-white break-all">{treasury.address}</span>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => copyToClipboard(treasury.address, 'address')}
                                    className="shrink-0 h-8 text-xs border-stone-700 hover:bg-stone-800 flex items-center gap-1.5 text-stone-300"
                                >
                                    {copiedAddress ? <Check className="size-3.5 text-emerald-400" /> : <Copy className="size-3.5" />}
                                    {copiedAddress ? 'Copied' : 'Copy Address'}
                                </Button>
                            </div>

                            {/* Safety Threshold Inputs */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-4 space-y-2">
                                    <Label htmlFor="min-reserve" className="text-xs font-medium text-white flex items-center gap-1.5">
                                        Minimum Emergency Reserve ({institution.currency})
                                    </Label>
                                    <p className="text-[11px] text-stone-400">
                                        Funds below this balance cannot be spent by automated payment pipelines.
                                    </p>
                                    <Input
                                        id="min-reserve"
                                        type="number"
                                        min="0"
                                        value={minReserve}
                                        onChange={(e) => setMinReserve(parseFloat(e.target.value) || 0)}
                                        className="h-8 font-mono text-xs bg-stone-950 border-stone-700 text-white"
                                    />
                                </div>

                                <div className="rounded-xl border border-stone-800 bg-stone-900/40 p-4 space-y-2">
                                    <Label htmlFor="max-auto-payment" className="text-xs font-medium text-white flex items-center gap-1.5">
                                        Max Auto-Payment Limit ({institution.currency})
                                    </Label>
                                    <p className="text-[11px] text-stone-400">
                                        Invoices higher than this amount always require manual bursar approval.
                                    </p>
                                    <Input
                                        id="max-auto-payment"
                                        type="number"
                                        min="0"
                                        value={maxAutoPayment}
                                        onChange={(e) => setMaxAutoPayment(parseFloat(e.target.value) || 0)}
                                        className="h-8 font-mono text-xs bg-stone-950 border-stone-700 text-white"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 4: Student Enrollment Share Link */}
                    <Card className="border-stone-800/80 bg-[#151311]/90 shadow-sm">
                        <CardHeader className="pb-4 border-b border-stone-800/60">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <CardTitle className="text-base font-serif font-bold text-white flex items-center gap-2">
                                        <GraduationCap className="size-4.5 text-[#c4a46c]" />
                                        4. Student Enrollment Link (Share with Students)
                                    </CardTitle>
                                    <CardDescription className="text-xs text-stone-400">
                                        Provide this direct link to your students to register their academic identity and link their Web3 payout wallet.
                                    </CardDescription>
                                </div>
                                <Badge variant="outline" className="text-[10px] font-mono border-emerald-500/30 text-emerald-400">
                                    Ready to Share
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-3">
                            <div className="rounded-xl border border-stone-800 bg-stone-900/60 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <span className="font-mono text-xs text-[#c4a46c] break-all select-all">
                                    {studentRegistrationUrl}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => copyToClipboard(studentRegistrationUrl, 'url')}
                                    className="shrink-0 h-8 text-xs border-[#c4a46c]/40 text-[#c4a46c] hover:bg-[#c4a46c]/10 flex items-center gap-1.5"
                                >
                                    {copiedUrl ? <Check className="size-3.5 text-emerald-400" /> : <Copy className="size-3.5" />}
                                    {copiedUrl ? 'Copied to Clipboard!' : 'Copy Student Link'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submission / Launch Button */}
                    <div className="rounded-2xl border border-[#c4a46c]/30 bg-[#1c1915]/60 p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h3 className="font-serif font-bold text-white text-base">
                                Ready to open your Financial Operations Console?
                            </h3>
                            <p className="text-xs text-stone-400">
                                Confirming will finalize your initial application setup and transition you to your live dashboard.
                            </p>
                        </div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={submitting}
                            className="bg-[#c4a46c] text-stone-950 hover:bg-[#d6b77e] font-semibold text-xs px-8 h-11 shrink-0 flex items-center gap-2 shadow-lg hover:shadow-xl transition-all"
                        >
                            {submitting ? (
                                <>
                                    <Spinner className="size-4" />
                                    Finalizing Setup...
                                </>
                            ) : (
                                <>
                                    Complete Setup & Launch Console
                                    <ArrowRight className="size-4" />
                                </>
                            )}
                        </Button>
                    </div>
                </form>
            </main>
        </div>
    );
}
