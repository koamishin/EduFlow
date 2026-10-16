import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    ArrowLeft,
    Award,
    BarChart3,
    CheckCircle2,
    Clock,
    FileSpreadsheet,
    Filter,
    RefreshCw,
    Scale,
    Search,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/reui/badge';
import {
    Frame,
    FrameDescription,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type CategoryItem = {
    id: string;
    name: string;
    count: number;
    avg_confidence: number;
};

type DecisionBreakdown = {
    total: number;
    needs_review: number;
    needs_review_rate: number;
    match: number;
    match_rate: number;
    hold: number;
    hold_rate: number;
    exempt: number;
    exempt_rate: number;
    in_progress: number;
    avg_confidence: number;
    categories: Record<string, CategoryItem>;
};

type ApprovalAnalytics = {
    total_evaluated: number;
    total_reviewed: number;
    total_approved: number;
    total_rejected: number;
    total_held: number;
    pending_review: number;
    approval_rate: number;
    concurrence_count: number;
    concurrence_rate: number;
    override_count: number;
    override_rate: number;
    avg_time_to_approval_seconds: number;
    avg_time_to_approval_human: string;
    fastest_approval_seconds: number | null;
    slowest_approval_seconds: number | null;
};

type TrendPoint = {
    date: string;
    label: string;
    matches: number;
    needs_review: number;
    holds: number;
    exempts: number;
    total: number;
    approved: number;
    overridden: number;
};

type AuditItem = {
    id: number;
    source_type: string;
    source_id: number;
    label: string;
    reason: string;
    jev_decision: string;
    category_name: string | null;
    confidence_percent: number | null;
    staff_status: string;
    reviewer_name: string | null;
    time_to_approval: string | null;
    concurrence: boolean | null;
    is_override: boolean;
    created_at: string;
};

type Props = {
    institution: {
        id: number;
        name: string;
    };
    filters: {
        period: string;
        source_type: string;
    };
    breakdown: DecisionBreakdown;
    approvals: ApprovalAnalytics;
    trends: TrendPoint[];
    recentAudit: AuditItem[];
    urls: {
        evaluationsUrl: string;
        policiesUrl: string;
        filamentAnalyticsUrl?: string;
        financeSupervisorUrl: string;
        financeDashboardUrl: string;
    };
};

export default function JevAnalyticsPage({
    institution,
    filters,
    breakdown,
    approvals,
    trends,
    recentAudit,
    urls,
}: Props) {
    const [searchTerm, setSearchTerm] = useState('');
    const [decisionFilter, setDecisionFilter] = useState('all');

    const handleFilterChange = (
        key: 'period' | 'source_type',
        value: string,
    ) => {
        router.get(
            window.location.pathname,
            {
                period: key === 'period' ? value : filters.period,
                source_type:
                    key === 'source_type' ? value : filters.source_type,
            },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    // Filter audit trail records
    const filteredAudit = useMemo(() => {
        return recentAudit.filter((item) => {
            const matchesSearch =
                searchTerm === '' ||
                item.label.toLowerCase().includes(searchTerm.toLowerCase()) ||
                item.reason.toLowerCase().includes(searchTerm.toLowerCase()) ||
                (item.category_name &&
                    item.category_name
                        .toLowerCase()
                        .includes(searchTerm.toLowerCase())) ||
                (item.reviewer_name &&
                    item.reviewer_name
                        .toLowerCase()
                        .includes(searchTerm.toLowerCase()));

            const matchesDecision =
                decisionFilter === 'all' ||
                item.jev_decision === decisionFilter;

            return matchesSearch && matchesDecision;
        });
    }, [recentAudit, searchTerm, decisionFilter]);

    // Max volume for trend bar scale
    const maxTrendTotal = useMemo(() => {
        const max = Math.max(...trends.map((t) => t.total), 1);
        return max;
    }, [trends]);

    const categoriesList = useMemo(() => {
        return Object.values(breakdown.categories || {});
    }, [breakdown.categories]);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
            <Head title="JEV Decision & Staff Approval Analytics" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
                {/* Header Section */}
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between border-b border-slate-200 pb-6 dark:border-slate-800">
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <Link
                                href={urls.evaluationsUrl}
                                className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                            >
                                <ArrowLeft className="h-3.5 w-3.5" />
                                Policy Evaluations
                            </Link>
                            <span className="text-slate-300 dark:text-slate-700">
                                /
                            </span>
                            <span className="text-xs font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                                Autonomous Lane Telemetry
                            </span>
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-3xl flex items-center gap-3">
                            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 text-white shadow-sm ring-2 ring-amber-100 dark:ring-amber-950">
                                <Sparkles className="h-5 w-5" />
                            </span>
                            JEV Advisory & Admin Approval Analytics
                        </h1>
                        <p className="text-sm text-slate-600 dark:text-slate-400 max-w-3xl">
                            {institution.name} · Empirical breakdown of how
                            often JEV decides on matches, holds, or review
                            escalations, and how frequently human finance
                            officers concur vs override under dual-custody
                            governance.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={urls.evaluationsUrl}>
                                <FileSpreadsheet className="mr-1.5 h-4 w-4" />
                                Live Evaluations
                            </Link>
                        </Button>
                        {urls.filamentAnalyticsUrl && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={urls.filamentAnalyticsUrl}>
                                    <Sparkles className="mr-1.5 h-4 w-4 text-amber-500" />
                                    Filament Governance View
                                </a>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <a href={urls.financeSupervisorUrl}>
                                <Scale className="mr-1.5 h-4 w-4" />
                                Finance Supervisor
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={urls.financeDashboardUrl}>
                                <Activity className="mr-1.5 h-4 w-4" />
                                Finance Overview
                            </a>
                        </Button>
                    </div>
                </div>

                {/* Filter Toolbar */}
                <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4 rounded-xl border border-slate-200/90 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center gap-2">
                        <Filter className="h-4 w-4 text-slate-400" />
                        <span className="text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            Telemetry Filters:
                        </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <div className="flex items-center gap-2">
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                Time Window:
                            </span>
                            <Select
                                value={filters.period}
                                onValueChange={(val) =>
                                    handleFilterChange('period', val)
                                }
                            >
                                <SelectTrigger className="h-8 w-36 text-xs">
                                    <SelectValue placeholder="Select period" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Time
                                    </SelectItem>
                                    <SelectItem value="today">Today</SelectItem>
                                    <SelectItem value="7_days">
                                        Last 7 Days
                                    </SelectItem>
                                    <SelectItem value="14_days">
                                        Last 14 Days
                                    </SelectItem>
                                    <SelectItem value="30_days">
                                        Last 30 Days
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex items-center gap-2">
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                Evidence Source:
                            </span>
                            <Select
                                value={filters.source_type}
                                onValueChange={(val) =>
                                    handleFilterChange('source_type', val)
                                }
                            >
                                <SelectTrigger className="h-8 w-44 text-xs">
                                    <SelectValue placeholder="Select source" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Evidence Types
                                    </SelectItem>
                                    <SelectItem value="collection">
                                        Collection Batches
                                    </SelectItem>
                                    <SelectItem value="transaction">
                                        Ledger Transactions
                                    </SelectItem>
                                    <SelectItem value="budget_snapshot">
                                        Budget Snapshots
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </div>

                {/* KPI Top Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    {/* Card 1: Total Evaluations */}
                    <div className="rounded-xl border border-slate-200/80 bg-white p-4.5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-2">
                        <div className="flex items-center justify-between text-slate-500 dark:text-slate-400">
                            <span className="text-xs font-medium">
                                Total Evaluations
                            </span>
                            <Sparkles className="h-4 w-4 text-amber-500" />
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            {breakdown.total}
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-1">
                            <span>{breakdown.match} matches</span>
                            <span>·</span>
                            <span>{breakdown.in_progress} in progress</span>
                        </p>
                    </div>

                    {/* Card 2: Matches */}
                    <div className="rounded-xl border border-slate-200/80 bg-white p-4.5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-2">
                        <div className="flex items-center justify-between text-slate-500 dark:text-slate-400">
                            <span className="text-xs font-medium">
                                Policy Matches
                            </span>
                            <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                            {breakdown.match}{' '}
                            <span className="text-sm font-normal text-slate-500">
                                ({breakdown.match_rate}%)
                            </span>
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                            Avg confidence: {breakdown.avg_confidence}%
                        </p>
                    </div>

                    {/* Card 3: Needs Review & Holds */}
                    <div className="rounded-xl border border-slate-200/80 bg-white p-4.5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-2">
                        <div className="flex items-center justify-between text-slate-500 dark:text-slate-400">
                            <span className="text-xs font-medium">
                                Needs Review / Holds
                            </span>
                            <ShieldAlert className="h-4 w-4 text-amber-500" />
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                            {breakdown.needs_review + breakdown.hold}{' '}
                            <span className="text-sm font-normal text-slate-500">
                                (
                                {breakdown.total > 0
                                    ? Math.round(
                                          ((breakdown.needs_review +
                                              breakdown.hold) /
                                              breakdown.total) *
                                              100,
                                      )
                                    : 0}
                                %)
                            </span>
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                            {breakdown.hold} holds · {breakdown.needs_review}{' '}
                            ambiguous
                        </p>
                    </div>

                    {/* Card 4: Staff Approval Rate */}
                    <div className="rounded-xl border border-slate-200/80 bg-white p-4.5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-2">
                        <div className="flex items-center justify-between text-slate-500 dark:text-slate-400">
                            <span className="text-xs font-medium">
                                Staff Approval Rate
                            </span>
                            <UserCheck className="h-4 w-4 text-indigo-500" />
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                            {approvals.approval_rate}%
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                            {approvals.total_approved} of{' '}
                            {approvals.total_reviewed} approved
                        </p>
                    </div>

                    {/* Card 5: Concurrence Rate */}
                    <div className="rounded-xl border border-slate-200/80 bg-white p-4.5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-2">
                        <div className="flex items-center justify-between text-slate-500 dark:text-slate-400">
                            <span className="text-xs font-medium">
                                Staff Concurrence
                            </span>
                            <Scale className="h-4 w-4 text-emerald-500" />
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            {approvals.concurrence_rate}%
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                            {approvals.concurrence_count} agreed ·{' '}
                            {approvals.override_count} overrides
                        </p>
                    </div>

                    {/* Card 6: Avg Time to Approval */}
                    <div className="rounded-xl border border-slate-200/80 bg-white p-4.5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-2">
                        <div className="flex items-center justify-between text-slate-500 dark:text-slate-400">
                            <span className="text-xs font-medium">
                                Avg Review Latency
                            </span>
                            <Clock className="h-4 w-4 text-sky-500" />
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-sky-600 dark:text-sky-400">
                            {approvals.avg_time_to_approval_human}
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                            {approvals.pending_review} pending human signoff
                        </p>
                    </div>
                </div>

                {/* Main Visual Grids */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Panel 1: JEV Decision Distribution */}
                    <Frame>
                        <FrameHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <FrameTitle className="flex items-center gap-2">
                                        <ShieldCheck className="h-4 w-4 text-amber-500" />
                                        Complete JEV Decision Breakdown
                                    </FrameTitle>
                                    <FrameDescription>
                                        How many times JEV decided on match,
                                        needs_review, safety hold, or exempt.
                                    </FrameDescription>
                                </div>
                                <Badge variant="outline" className="text-xs">
                                    {breakdown.total} Evaluated
                                </Badge>
                            </div>
                        </FrameHeader>
                        <FramePanel className="space-y-5">
                            {/* Visual Progress Bar Breakdown */}
                            <div className="space-y-1.5">
                                <div className="flex h-4 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div
                                        style={{
                                            width: `${breakdown.match_rate}%`,
                                        }}
                                        className="bg-emerald-500 transition-all duration-500"
                                        title={`Match: ${breakdown.match} (${breakdown.match_rate}%)`}
                                    />
                                    <div
                                        style={{
                                            width: `${breakdown.needs_review_rate}%`,
                                        }}
                                        className="bg-amber-500 transition-all duration-500"
                                        title={`Needs Review: ${breakdown.needs_review} (${breakdown.needs_review_rate}%)`}
                                    />
                                    <div
                                        style={{
                                            width: `${breakdown.hold_rate}%`,
                                        }}
                                        className="bg-rose-500 transition-all duration-500"
                                        title={`Hold: ${breakdown.hold} (${breakdown.hold_rate}%)`}
                                    />
                                    <div
                                        style={{
                                            width: `${breakdown.exempt_rate}%`,
                                        }}
                                        className="bg-indigo-500 transition-all duration-500"
                                        title={`Exempt: ${breakdown.exempt} (${breakdown.exempt_rate}%)`}
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                                    <span>High Confidence Match</span>
                                    <span>Safety Hold & Review</span>
                                </div>
                            </div>

                            {/* Detailed Decision Items */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="rounded-lg border border-emerald-200/60 bg-emerald-50/40 p-3.5 dark:border-emerald-950 dark:bg-emerald-950/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                            <span className="text-xs font-semibold text-emerald-950 dark:text-emerald-300">
                                                Direct Match
                                            </span>
                                        </div>
                                        <span className="text-sm font-bold text-emerald-900 dark:text-emerald-200">
                                            {breakdown.match}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-emerald-700 dark:text-emerald-400">
                                        Matched published category (&ge;80%
                                        confidence with verbatim policy quotes).
                                    </p>
                                    <div className="mt-2 text-[10px] font-medium text-emerald-800 dark:text-emerald-300">
                                        {breakdown.match_rate}% of total runs
                                    </div>
                                </div>

                                <div className="rounded-lg border border-amber-200/60 bg-amber-50/40 p-3.5 dark:border-amber-950 dark:bg-amber-950/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="h-2.5 w-2.5 rounded-full bg-amber-500" />
                                            <span className="text-xs font-semibold text-amber-950 dark:text-amber-300">
                                                Needs Review
                                            </span>
                                        </div>
                                        <span className="text-sm font-bold text-amber-900 dark:text-amber-200">
                                            {breakdown.needs_review}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-amber-700 dark:text-amber-400">
                                        Ambiguous evidence or questions flagged
                                        by policy parser.
                                    </p>
                                    <div className="mt-2 text-[10px] font-medium text-amber-800 dark:text-amber-300">
                                        {breakdown.needs_review_rate}% of total
                                        runs
                                    </div>
                                </div>

                                <div className="rounded-lg border border-rose-200/60 bg-rose-50/40 p-3.5 dark:border-rose-950 dark:bg-rose-950/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="h-2.5 w-2.5 rounded-full bg-rose-500" />
                                            <span className="text-xs font-semibold text-rose-950 dark:text-rose-300">
                                                Safety Hold
                                            </span>
                                        </div>
                                        <span className="text-sm font-bold text-rose-900 dark:text-rose-200">
                                            {breakdown.hold}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-rose-700 dark:text-rose-400">
                                        Below 80% threshold or conflicting
                                        policy categories detected.
                                    </p>
                                    <div className="mt-2 text-[10px] font-medium text-rose-800 dark:text-rose-300">
                                        {breakdown.hold_rate}% of total runs
                                    </div>
                                </div>

                                <div className="rounded-lg border border-indigo-200/60 bg-indigo-50/40 p-3.5 dark:border-indigo-950 dark:bg-indigo-950/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="h-2.5 w-2.5 rounded-full bg-indigo-500" />
                                            <span className="text-xs font-semibold text-indigo-950 dark:text-indigo-300">
                                                Exempt / Non-chargeable
                                            </span>
                                        </div>
                                        <span className="text-sm font-bold text-indigo-900 dark:text-indigo-200">
                                            {breakdown.exempt}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-indigo-700 dark:text-indigo-400">
                                        Excluded criteria or zero-headroom
                                        budget line allocations.
                                    </p>
                                    <div className="mt-2 text-[10px] font-medium text-indigo-800 dark:text-indigo-300">
                                        {breakdown.exempt_rate}% of total runs
                                    </div>
                                </div>
                            </div>
                        </FramePanel>
                    </Frame>

                    {/* Panel 2: Staff Approval & Concurrence Analytics */}
                    <Frame>
                        <FrameHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <FrameTitle className="flex items-center gap-2">
                                        <UserCheck className="h-4 w-4 text-indigo-500" />
                                        Admin Approval & Concurrence Analytics
                                    </FrameTitle>
                                    <FrameDescription>
                                        How frequently human officers approve
                                        JEV decisions vs manual overrides.
                                    </FrameDescription>
                                </div>
                                <Badge variant="outline" className="text-xs">
                                    {approvals.total_reviewed} Reviewed
                                </Badge>
                            </div>
                        </FrameHeader>
                        <FramePanel className="space-y-5">
                            {/* Concurrence Bar */}
                            <div className="space-y-1.5">
                                <div className="flex h-4 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div
                                        style={{
                                            width: `${approvals.concurrence_rate}%`,
                                        }}
                                        className="bg-emerald-500 transition-all duration-500"
                                        title={`Staff Concurred: ${approvals.concurrence_count} (${approvals.concurrence_rate}%)`}
                                    />
                                    <div
                                        style={{
                                            width: `${approvals.override_rate}%`,
                                        }}
                                        className="bg-amber-500 transition-all duration-500"
                                        title={`Manual Override: ${approvals.override_count} (${approvals.override_rate}%)`}
                                    />
                                </div>
                                <div className="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                                    <span>
                                        Concurred with JEV (
                                        {approvals.concurrence_rate}%)
                                    </span>
                                    <span>
                                        Manual Overrides (
                                        {approvals.override_rate}%)
                                    </span>
                                </div>
                            </div>

                            {/* Approval Stats Grid */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3 text-center dark:border-slate-800 dark:bg-slate-900/40">
                                    <span className="text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                        Staff Approvals
                                    </span>
                                    <div className="mt-1 text-xl font-bold text-emerald-600 dark:text-emerald-400">
                                        {approvals.total_approved}
                                    </div>
                                    <span className="text-[10px] text-slate-400">
                                        {approvals.approval_rate}% approval rate
                                    </span>
                                </div>

                                <div className="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3 text-center dark:border-slate-800 dark:bg-slate-900/40">
                                    <span className="text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                        Staff Rejections
                                    </span>
                                    <div className="mt-1 text-xl font-bold text-rose-600 dark:text-rose-400">
                                        {approvals.total_rejected}
                                    </div>
                                    <span className="text-[10px] text-slate-400">
                                        Rejected or revoked
                                    </span>
                                </div>

                                <div className="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3 text-center dark:border-slate-800 dark:bg-slate-900/40">
                                    <span className="text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                        Held for Corrections
                                    </span>
                                    <div className="mt-1 text-xl font-bold text-amber-600 dark:text-amber-400">
                                        {approvals.total_held}
                                    </div>
                                    <span className="text-[10px] text-slate-400">
                                        Staff confirmed hold
                                    </span>
                                </div>
                            </div>

                            {/* Dual-Custody Insights Banner */}
                            <div className="rounded-lg border border-indigo-200/70 bg-indigo-50/50 p-3.5 text-xs text-indigo-950 dark:border-indigo-950 dark:bg-indigo-950/20 dark:text-indigo-200 flex items-start gap-2.5">
                                <Award className="h-4 w-4 text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" />
                                <div>
                                    <span className="font-semibold">
                                        Separation of Duties Verification:
                                    </span>{' '}
                                    JEV proposes advisory classifications and
                                    allocation bounds; no payment is ever
                                    released until a certified human officer
                                    independently signs off. Time to approval
                                    averages{' '}
                                    <span className="font-semibold text-indigo-700 dark:text-indigo-300">
                                        {approvals.avg_time_to_approval_human}
                                    </span>{' '}
                                    across all processed collections and budget
                                    plans.
                                </div>
                            </div>
                        </FramePanel>
                    </Frame>
                </div>

                {/* 14-Day Visual Evaluation & Approval Trends */}
                <Frame>
                    <FrameHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <FrameTitle className="flex items-center gap-2">
                                    <BarChart3 className="h-4 w-4 text-sky-500" />
                                    14-Day Daily Evaluation & Approval Trends
                                </FrameTitle>
                                <FrameDescription>
                                    Daily evaluation volume by decision state
                                    and human staff approvals.
                                </FrameDescription>
                            </div>
                            <div className="flex items-center gap-3 text-xs text-slate-500">
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-full bg-emerald-500" />{' '}
                                    Match
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-full bg-amber-500" />{' '}
                                    Needs Review
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-full bg-rose-500" />{' '}
                                    Safety Hold
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-full bg-sky-500" />{' '}
                                    Approved
                                </span>
                            </div>
                        </div>
                    </FrameHeader>
                    <FramePanel>
                        {trends.length === 0 ? (
                            <div className="py-12 text-center text-sm text-slate-500">
                                No trend data available for the selected
                                interval.
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="grid grid-cols-14 gap-1.5 sm:gap-2 h-44 items-end pt-4">
                                    {trends.map((point) => {
                                        const heightPercent = Math.max(
                                            Math.round(
                                                (point.total / maxTrendTotal) *
                                                    100,
                                            ),
                                            point.total > 0 ? 12 : 2,
                                        );

                                        return (
                                            <div
                                                key={point.date}
                                                className="flex flex-col items-center gap-1.5 h-full justify-end group relative"
                                            >
                                                {/* Hover Tooltip */}
                                                <div className="absolute -top-12 z-20 hidden rounded-md bg-slate-900 px-2 py-1 text-[10px] text-white shadow-lg group-hover:block dark:bg-slate-800 whitespace-nowrap">
                                                    <div>
                                                        {point.label}:{' '}
                                                        {point.total}{' '}
                                                        evaluations
                                                    </div>
                                                    <div className="text-emerald-400">
                                                        {point.matches} matches
                                                        · {point.approved}{' '}
                                                        approved
                                                    </div>
                                                </div>

                                                {/* Stacked bar */}
                                                <div
                                                    style={{
                                                        height: `${heightPercent}%`,
                                                    }}
                                                    className="w-full max-w-[28px] rounded-t-md overflow-hidden flex flex-col justify-end bg-slate-100 dark:bg-slate-800 transition-all duration-300"
                                                >
                                                    {point.matches > 0 && (
                                                        <div
                                                            style={{
                                                                height: `${(point.matches / (point.total || 1)) * 100}%`,
                                                            }}
                                                            className="bg-emerald-500"
                                                        />
                                                    )}
                                                    {point.needs_review > 0 && (
                                                        <div
                                                            style={{
                                                                height: `${(point.needs_review / (point.total || 1)) * 100}%`,
                                                            }}
                                                            className="bg-amber-500"
                                                        />
                                                    )}
                                                    {point.holds > 0 && (
                                                        <div
                                                            style={{
                                                                height: `${(point.holds / (point.total || 1)) * 100}%`,
                                                            }}
                                                            className="bg-rose-500"
                                                        />
                                                    )}
                                                </div>

                                                <span className="text-[10px] text-slate-400 truncate max-w-[32px]">
                                                    {point.label.split(' ')[1]}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </FramePanel>
                </Frame>

                {/* Matched Policy Categories */}
                {categoriesList.length > 0 && (
                    <Frame>
                        <FrameHeader>
                            <FrameTitle className="flex items-center gap-2">
                                <FileSpreadsheet className="h-4 w-4 text-indigo-500" />
                                Top Policy Categories Classified by JEV
                            </FrameTitle>
                            <FrameDescription>
                                Exact distribution of published institution
                                policy categories matched with verified
                                citations.
                            </FrameDescription>
                        </FrameHeader>
                        <FramePanel>
                            <div className="space-y-3">
                                {categoriesList.map((cat) => {
                                    const percent =
                                        breakdown.match > 0
                                            ? Math.round(
                                                  (cat.count /
                                                      breakdown.match) *
                                                      100,
                                              )
                                            : 0;

                                    return (
                                        <div key={cat.id} className="space-y-1">
                                            <div className="flex items-center justify-between text-xs">
                                                <span className="font-semibold text-slate-800 dark:text-slate-200">
                                                    {cat.name}
                                                </span>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-slate-500">
                                                        {cat.count} runs (
                                                        {percent}%)
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px] py-0"
                                                    >
                                                        {cat.avg_confidence}%
                                                        confidence
                                                    </Badge>
                                                </div>
                                            </div>
                                            <div className="h-2 w-full rounded-full bg-slate-100 overflow-hidden dark:bg-slate-800">
                                                <div
                                                    style={{
                                                        width: `${percent}%`,
                                                    }}
                                                    className="h-full bg-indigo-600 rounded-full transition-all duration-300"
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </FramePanel>
                    </Frame>
                )}

                {/* Audit Trail Table of Recent Evaluations */}
                <Frame>
                    <FrameHeader>
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <FrameTitle className="flex items-center gap-2">
                                    <Clock className="h-4 w-4 text-amber-500" />
                                    Recent JEV Evaluations & Staff Audit Trail
                                </FrameTitle>
                                <FrameDescription>
                                    Inspect decision history, verbatim
                                    citations, staff reviews, and concurrence
                                    records.
                                </FrameDescription>
                            </div>

                            <div className="flex items-center gap-2">
                                <div className="relative w-56">
                                    <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" />
                                    <Input
                                        placeholder="Search evaluations..."
                                        value={searchTerm}
                                        onChange={(e) =>
                                            setSearchTerm(e.target.value)
                                        }
                                        className="h-8 pl-8 text-xs"
                                    />
                                </div>

                                <Select
                                    value={decisionFilter}
                                    onValueChange={setDecisionFilter}
                                >
                                    <SelectTrigger className="h-8 w-32 text-xs">
                                        <SelectValue placeholder="Decision" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Decisions
                                        </SelectItem>
                                        <SelectItem value="match">
                                            Match
                                        </SelectItem>
                                        <SelectItem value="needs_review">
                                            Needs Review
                                        </SelectItem>
                                        <SelectItem value="hold">
                                            Safety Hold
                                        </SelectItem>
                                        <SelectItem value="exempt">
                                            Exempt
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </FrameHeader>
                    <FramePanel className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs border-collapse">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50/75 dark:border-slate-800 dark:bg-slate-900/50 text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                                        <th className="py-3 px-4">#ID</th>
                                        <th className="py-3 px-4">
                                            Evidence Source
                                        </th>
                                        <th className="py-3 px-4">
                                            JEV Decision
                                        </th>
                                        <th className="py-3 px-4">
                                            Category / Confidence
                                        </th>
                                        <th className="py-3 px-4">
                                            Admin Status
                                        </th>
                                        <th className="py-3 px-4">
                                            Concurrence
                                        </th>
                                        <th className="py-3 px-4">Latency</th>
                                        <th className="py-3 px-4">
                                            Evaluated At
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                    {filteredAudit.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="py-8 text-center text-slate-500"
                                            >
                                                No policy evaluation records
                                                found matching your filter.
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredAudit.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="hover:bg-slate-50/80 dark:hover:bg-slate-900/30 transition-colors"
                                            >
                                                <td className="py-3 px-4 font-mono text-[11px] font-bold text-slate-500">
                                                    #{item.id}
                                                </td>
                                                <td className="py-3 px-4">
                                                    <div className="font-medium text-slate-900 dark:text-slate-100">
                                                        {item.label}
                                                    </div>
                                                    <div className="text-[11px] text-slate-500 truncate max-w-xs">
                                                        {item.reason}
                                                    </div>
                                                </td>
                                                <td className="py-3 px-4">
                                                    {item.jev_decision ===
                                                        'match' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                        >
                                                            <CheckCircle2 className="mr-1 h-3 w-3 text-emerald-600" />
                                                            Match
                                                        </Badge>
                                                    )}
                                                    {item.jev_decision ===
                                                        'needs_review' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300"
                                                        >
                                                            <AlertCircle className="mr-1 h-3 w-3 text-amber-600" />
                                                            Needs Review
                                                        </Badge>
                                                    )}
                                                    {item.jev_decision ===
                                                        'hold' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300"
                                                        >
                                                            <ShieldAlert className="mr-1 h-3 w-3 text-rose-600" />
                                                            Safety Hold
                                                        </Badge>
                                                    )}
                                                    {item.jev_decision ===
                                                        'exempt' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-300"
                                                        >
                                                            Exempt
                                                        </Badge>
                                                    )}
                                                    {item.jev_decision ===
                                                        'in_progress' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-slate-50 text-slate-700 border-slate-200"
                                                        >
                                                            <RefreshCw className="mr-1 h-3 w-3 animate-spin text-slate-500" />
                                                            Running
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="py-3 px-4">
                                                    {item.category_name ? (
                                                        <div>
                                                            <span className="font-semibold text-slate-800 dark:text-slate-200">
                                                                {
                                                                    item.category_name
                                                                }
                                                            </span>
                                                            {item.confidence_percent !==
                                                                null && (
                                                                <span className="ml-1.5 text-[11px] text-slate-500 font-mono">
                                                                    (
                                                                    {
                                                                        item.confidence_percent
                                                                    }
                                                                    %)
                                                                </span>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-slate-400 italic">
                                                            Held for human
                                                            review
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3 px-4">
                                                    {item.staff_status ===
                                                        'approved' && (
                                                        <span className="inline-flex items-center text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                                            <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                                                            Approved
                                                        </span>
                                                    )}
                                                    {item.staff_status ===
                                                        'held' && (
                                                        <span className="inline-flex items-center text-xs font-semibold text-amber-600 dark:text-amber-400">
                                                            <ShieldAlert className="mr-1 h-3.5 w-3.5" />
                                                            Held by Staff
                                                        </span>
                                                    )}
                                                    {item.staff_status ===
                                                        'rejected' && (
                                                        <span className="inline-flex items-center text-xs font-semibold text-rose-600 dark:text-rose-400">
                                                            <XCircle className="mr-1 h-3.5 w-3.5" />
                                                            Rejected
                                                        </span>
                                                    )}
                                                    {item.staff_status ===
                                                        'pending_review' && (
                                                        <span className="inline-flex items-center text-xs text-slate-400 italic">
                                                            <Clock className="mr-1 h-3.5 w-3.5" />
                                                            Awaiting Review
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3 px-4">
                                                    {item.concurrence ===
                                                        true && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-emerald-50 text-emerald-700 border-emerald-200 text-[10px] py-0"
                                                        >
                                                            Concurred
                                                        </Badge>
                                                    )}
                                                    {item.concurrence ===
                                                        false && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-amber-50 text-amber-700 border-amber-200 text-[10px] py-0"
                                                        >
                                                            Override
                                                        </Badge>
                                                    )}
                                                    {item.concurrence ===
                                                        null && (
                                                        <span className="text-slate-400 text-[11px]">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3 px-4 text-slate-500 font-mono text-[11px]">
                                                    {item.time_to_approval ??
                                                        '—'}
                                                </td>
                                                <td className="py-3 px-4 text-slate-500 text-[11px] whitespace-nowrap">
                                                    {new Date(
                                                        item.created_at,
                                                    ).toLocaleString(
                                                        undefined,
                                                        {
                                                            month: 'short',
                                                            day: 'numeric',
                                                            hour: '2-digit',
                                                            minute: '2-digit',
                                                        },
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </FramePanel>
                </Frame>
            </div>
        </div>
    );
}
