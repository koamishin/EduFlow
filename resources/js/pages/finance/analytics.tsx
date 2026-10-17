import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    ArrowLeft,
    ArrowUpRight,
    Award,
    BarChart3,
    Bot,
    BrainCircuit,
    CheckCircle2,
    Clock,
    Cpu,
    ExternalLink,
    Eye,
    FileSpreadsheet,
    Filter,
    Layers,
    RefreshCw,
    Scale,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    UserCheck,
    XCircle,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    AgentHealthStatus,
    type JevAiHealth,
} from '@/components/jev/agent-health-status';
import { Alert, AlertDescription, AlertTitle } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import {
    Frame,
    FrameDescription,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { IconTile } from '@/components/reui/icon-tile';
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
    health?: JevAiHealth | null;
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
    health,
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
                (decisionFilter === 'match' && item.jev_decision === 'match') ||
                (decisionFilter === 'needs_review' &&
                    item.jev_decision === 'needs_review') ||
                (decisionFilter === 'hold' && item.jev_decision === 'hold') ||
                (decisionFilter === 'overrides' && item.is_override);

            return matchesSearch && matchesDecision;
        });
    }, [recentAudit, searchTerm, decisionFilter]);

    // Max volume for trend bar scale
    const maxTrendTotal = useMemo(() => {
        const max = Math.max(...trends.map((t) => t.total), 1);
        return max;
    }, [trends]);

    const categoriesList = useMemo(() => {
        return Object.values(breakdown.categories || {}).sort(
            (a, b) => b.count - a.count,
        );
    }, [breakdown.categories]);

    return (
        <div className="min-h-screen bg-background text-foreground antialiased">
            <Head title={`JEV Decision Telemetry · ${institution.name}`} />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
                {/* Executive Header Section */}
                <div className="flex flex-col gap-5 border-b border-border/80 pb-6 lg:flex-row lg:items-center lg:justify-between">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={urls.evaluationsUrl}
                                className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <ArrowLeft className="size-3.5" />
                                Policy Evaluations
                            </Link>
                            <span className="text-muted-foreground/40">/</span>
                            <span className="text-xs font-semibold uppercase tracking-wider text-primary">
                                AI Governance Telemetry
                            </span>
                            <span className="text-muted-foreground/40">/</span>
                            <Badge variant="primary-light" size="xs">
                                Autonomous Lane
                            </Badge>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl flex items-center gap-2.5">
                                <IconTile
                                    variant="soft"
                                    className="text-primary size-9"
                                >
                                    <Sparkles className="size-5" />
                                </IconTile>
                                <span>JEV Decision & Approval Telemetry</span>
                            </h1>
                        </div>

                        <p className="text-sm text-muted-foreground max-w-3xl leading-relaxed">
                            {institution.name} · Real-time empirical breakdown
                            of JEV AI classifications, fail-closed hold rates,
                            and dual-custody human officer approval concurrence.
                        </p>
                    </div>

                    {/* Quick Action Navigation Bar */}
                    <div className="flex flex-wrap items-center gap-2.5">
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className="gap-1.5 text-xs"
                        >
                            <Link href={urls.policiesUrl}>
                                <BrainCircuit className="size-3.5 text-primary" />
                                Policy Rules & Simulator
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className="gap-1.5 text-xs"
                        >
                            <Link href={urls.evaluationsUrl}>
                                <FileSpreadsheet className="size-3.5" />
                                Live Evaluations
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className="gap-1.5 text-xs"
                        >
                            <a href={urls.financeSupervisorUrl}>
                                <Scale className="size-3.5" />
                                Supervisor
                            </a>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className="gap-1.5 text-xs"
                        >
                            <a href={urls.financeDashboardUrl}>
                                <Activity className="size-3.5" />
                                Finance Console
                            </a>
                        </Button>
                    </div>
                </div>

                {/* Agent & Engine Health Telemetry Banner */}
                <AgentHealthStatus initialHealth={health} mode="full" />

                {/* Filter Toolbar Frame */}
                <Frame spacing="sm">
                    <FramePanel>
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
                            <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                <Filter className="size-3.5 text-primary" />
                                <span className="font-semibold uppercase tracking-wider text-foreground">
                                    Telemetry Scope:
                                </span>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        Time Horizon:
                                    </span>
                                    <Select
                                        value={filters.period}
                                        onValueChange={(val) =>
                                            handleFilterChange('period', val)
                                        }
                                    >
                                        <SelectTrigger className="h-8 w-36 text-xs bg-background">
                                            <SelectValue placeholder="Period" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All Time
                                            </SelectItem>
                                            <SelectItem value="today">
                                                Today
                                            </SelectItem>
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
                                    <span className="text-xs text-muted-foreground">
                                        Evidence Stream:
                                    </span>
                                    <Select
                                        value={filters.source_type}
                                        onValueChange={(val) =>
                                            handleFilterChange(
                                                'source_type',
                                                val,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="h-8 w-44 text-xs bg-background">
                                            <SelectValue placeholder="Evidence Source" />
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
                    </FramePanel>
                </Frame>

                {/* Primary KPI Metrics Grid */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    {/* Metric 1: Total Evaluations */}
                    <div className="rounded-xl border border-border/80 bg-card p-4 space-y-2 shadow-xs transition-shadow hover:shadow-sm">
                        <div className="flex items-center justify-between text-muted-foreground">
                            <span className="text-xs font-semibold uppercase tracking-wider">
                                Evaluations
                            </span>
                            <IconTile
                                variant="soft"
                                className="size-6 text-primary"
                            >
                                <Sparkles className="size-3.5" />
                            </IconTile>
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-foreground">
                            {breakdown.total}
                        </div>
                        <p className="text-xs text-muted-foreground flex items-center gap-1">
                            <span>{breakdown.match} matches</span>
                            <span>·</span>
                            <span>{breakdown.in_progress} in queue</span>
                        </p>
                    </div>

                    {/* Metric 2: Matches */}
                    <div className="rounded-xl border border-border/80 bg-card p-4 space-y-2 shadow-xs transition-shadow hover:shadow-sm">
                        <div className="flex items-center justify-between text-muted-foreground">
                            <span className="text-xs font-semibold uppercase tracking-wider">
                                Auto Matches
                            </span>
                            <IconTile
                                variant="soft"
                                className="size-6 text-emerald-500"
                            >
                                <CheckCircle2 className="size-3.5" />
                            </IconTile>
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                            {breakdown.match}{' '}
                            <span className="text-sm font-normal text-muted-foreground">
                                ({breakdown.match_rate}%)
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Avg confidence: {breakdown.avg_confidence}%
                        </p>
                    </div>

                    {/* Metric 3: Needs Review & Holds */}
                    <div className="rounded-xl border border-border/80 bg-card p-4 space-y-2 shadow-xs transition-shadow hover:shadow-sm">
                        <div className="flex items-center justify-between text-muted-foreground">
                            <span className="text-xs font-semibold uppercase tracking-wider">
                                Review / Holds
                            </span>
                            <IconTile
                                variant="soft"
                                className="size-6 text-amber-500"
                            >
                                <ShieldAlert className="size-3.5" />
                            </IconTile>
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                            {breakdown.needs_review + breakdown.hold}{' '}
                            <span className="text-sm font-normal text-muted-foreground">
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
                        <p className="text-xs text-muted-foreground">
                            {breakdown.hold} holds · {breakdown.needs_review}{' '}
                            ambiguous
                        </p>
                    </div>

                    {/* Metric 4: Staff Approval Rate */}
                    <div className="rounded-xl border border-border/80 bg-card p-4 space-y-2 shadow-xs transition-shadow hover:shadow-sm">
                        <div className="flex items-center justify-between text-muted-foreground">
                            <span className="text-xs font-semibold uppercase tracking-wider">
                                Officer Approval
                            </span>
                            <IconTile
                                variant="soft"
                                className="size-6 text-indigo-500"
                            >
                                <UserCheck className="size-3.5" />
                            </IconTile>
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                            {approvals.approval_rate}%
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {approvals.total_approved} of{' '}
                            {approvals.total_reviewed} approved
                        </p>
                    </div>

                    {/* Metric 5: Concurrence Rate */}
                    <div className="rounded-xl border border-border/80 bg-card p-4 space-y-2 shadow-xs transition-shadow hover:shadow-sm">
                        <div className="flex items-center justify-between text-muted-foreground">
                            <span className="text-xs font-semibold uppercase tracking-wider">
                                Concurrence
                            </span>
                            <IconTile
                                variant="soft"
                                className="size-6 text-emerald-500"
                            >
                                <Scale className="size-3.5" />
                            </IconTile>
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-foreground">
                            {approvals.concurrence_rate}%
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {approvals.concurrence_count} agreed ·{' '}
                            {approvals.override_count} overrides
                        </p>
                    </div>

                    {/* Metric 6: Review Latency */}
                    <div className="rounded-xl border border-border/80 bg-card p-4 space-y-2 shadow-xs transition-shadow hover:shadow-sm">
                        <div className="flex items-center justify-between text-muted-foreground">
                            <span className="text-xs font-semibold uppercase tracking-wider">
                                Avg Signoff Latency
                            </span>
                            <IconTile
                                variant="soft"
                                className="size-6 text-sky-500"
                            >
                                <Clock className="size-3.5" />
                            </IconTile>
                        </div>
                        <div className="text-2xl font-bold tracking-tight text-sky-600 dark:text-sky-400">
                            {approvals.avg_time_to_approval_human}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {approvals.pending_review} pending dual-signoff
                        </p>
                    </div>
                </div>

                {/* Analytical Breakdown Grid */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Decision Portfolio & Categories */}
                    <Frame spacing="default">
                        <FrameHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <IconTile
                                        variant="soft"
                                        className="text-primary size-7"
                                    >
                                        <BarChart3 className="size-4" />
                                    </IconTile>
                                    <div>
                                        <FrameTitle className="text-base font-semibold">
                                            Decision Classification Portfolio
                                        </FrameTitle>
                                        <FrameDescription className="text-xs">
                                            Proportion of transactions
                                            classified by JEV AI vs held for
                                            human review
                                        </FrameDescription>
                                    </div>
                                </div>
                                <Badge variant="outline" size="sm">
                                    {breakdown.total} Items
                                </Badge>
                            </div>
                        </FrameHeader>

                        <FramePanel>
                            <div className="space-y-5">
                                {/* Segmented Progress Bar */}
                                <div className="space-y-1.5">
                                    <div className="flex h-3 w-full overflow-hidden rounded-full bg-muted/60 p-0.5">
                                        {breakdown.match > 0 && (
                                            <div
                                                style={{
                                                    width: `${breakdown.match_rate}%`,
                                                }}
                                                className="bg-emerald-500 rounded-l-full transition-all duration-500"
                                                title={`Match: ${breakdown.match_rate}%`}
                                            />
                                        )}
                                        {breakdown.needs_review > 0 && (
                                            <div
                                                style={{
                                                    width: `${breakdown.needs_review_rate}%`,
                                                }}
                                                className="bg-amber-500 transition-all duration-500"
                                                title={`Needs Review: ${breakdown.needs_review_rate}%`}
                                            />
                                        )}
                                        {breakdown.hold > 0 && (
                                            <div
                                                style={{
                                                    width: `${breakdown.hold_rate}%`,
                                                }}
                                                className="bg-rose-500 transition-all duration-500"
                                                title={`Hold: ${breakdown.hold_rate}%`}
                                            />
                                        )}
                                        {breakdown.exempt > 0 && (
                                            <div
                                                style={{
                                                    width: `${breakdown.exempt_rate}%`,
                                                }}
                                                className="bg-indigo-500 rounded-r-full transition-all duration-500"
                                                title={`Exempt: ${breakdown.exempt_rate}%`}
                                            />
                                        )}
                                    </div>

                                    {/* Legend */}
                                    <div className="flex flex-wrap items-center justify-between gap-2 pt-1 text-xs">
                                        <div className="flex items-center gap-1.5">
                                            <span className="size-2 rounded-full bg-emerald-500" />
                                            <span className="text-muted-foreground">
                                                Match:
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {breakdown.match} (
                                                {breakdown.match_rate}%)
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <span className="size-2 rounded-full bg-amber-500" />
                                            <span className="text-muted-foreground">
                                                Needs Review:
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {breakdown.needs_review} (
                                                {breakdown.needs_review_rate}%)
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <span className="size-2 rounded-full bg-rose-500" />
                                            <span className="text-muted-foreground">
                                                Hold:
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {breakdown.hold} (
                                                {breakdown.hold_rate}%)
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <span className="size-2 rounded-full bg-indigo-500" />
                                            <span className="text-muted-foreground">
                                                Exempt:
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {breakdown.exempt} (
                                                {breakdown.exempt_rate}%)
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Active Category Volume Matrix */}
                                <div className="space-y-2 pt-2 border-t border-border/60">
                                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Policy Category Match Distribution
                                    </h4>

                                    {categoriesList.length === 0 ? (
                                        <div className="py-6 text-center text-xs text-muted-foreground">
                                            No policy matches recorded for this
                                            filter window.
                                        </div>
                                    ) : (
                                        <div className="space-y-2 max-h-[300px] overflow-y-auto pr-1">
                                            {(() => {
                                                const totalHits =
                                                    categoriesList.reduce(
                                                        (sum, item) =>
                                                            sum + item.count,
                                                        0,
                                                    );
                                                const denominator = Math.max(
                                                    breakdown.total,
                                                    totalHits,
                                                    1,
                                                );
                                                return categoriesList.map(
                                                    (cat) => {
                                                        const pct = Math.min(
                                                            100,
                                                            Math.round(
                                                                (cat.count /
                                                                    denominator) *
                                                                    100,
                                                            ),
                                                        );
                                                        return (
                                                            <div
                                                                key={cat.id}
                                                                className="flex items-center justify-between rounded-lg border border-border/70 bg-muted/20 px-3 py-2 text-xs"
                                                            >
                                                                <div className="min-w-0 flex-1 space-y-0.5">
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="font-medium text-foreground truncate">
                                                                            {
                                                                                cat.name
                                                                            }
                                                                        </span>
                                                                        <Badge
                                                                            variant="outline"
                                                                            size="xs"
                                                                        >
                                                                            {
                                                                                cat.count
                                                                            }{' '}
                                                                            hits
                                                                        </Badge>
                                                                    </div>
                                                                    <div className="h-1.5 w-full max-w-[200px] overflow-hidden rounded-full bg-muted">
                                                                        <div
                                                                            style={{
                                                                                width: `${pct}%`,
                                                                            }}
                                                                            className="h-full bg-primary rounded-full"
                                                                        />
                                                                    </div>
                                                                </div>

                                                                <div className="text-right pl-3">
                                                                    <div className="font-semibold text-foreground">
                                                                        {pct}%
                                                                    </div>
                                                                    <div className="text-[10px] text-muted-foreground">
                                                                        {
                                                                            cat.avg_confidence
                                                                        }
                                                                        % conf
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        );
                                                    },
                                                );
                                            })()}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </FramePanel>
                    </Frame>

                    {/* Dual-Custody Staff Concurrence & SLA Metrics */}
                    <Frame spacing="default">
                        <FrameHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <IconTile
                                        variant="soft"
                                        className="text-emerald-500 size-7"
                                    >
                                        <Scale className="size-4" />
                                    </IconTile>
                                    <div>
                                        <FrameTitle className="text-base font-semibold">
                                            Dual-Custody Human Governance
                                        </FrameTitle>
                                        <FrameDescription className="text-xs">
                                            Officer alignment vs manual
                                            overrides under non-custodial
                                            financial controls
                                        </FrameDescription>
                                    </div>
                                </div>
                                <Badge variant="success-light" size="sm">
                                    {approvals.concurrence_rate}% Agreement
                                </Badge>
                            </div>
                        </FrameHeader>

                        <FramePanel>
                            <div className="space-y-4">
                                {/* Concurrence Split Card */}
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3.5 space-y-1">
                                        <div className="flex items-center justify-between text-emerald-600 dark:text-emerald-400">
                                            <span className="text-xs font-semibold uppercase tracking-wider">
                                                Concurred (Agreed)
                                            </span>
                                            <CheckCircle2 className="size-4" />
                                        </div>
                                        <div className="text-2xl font-bold text-foreground">
                                            {approvals.concurrence_count}
                                        </div>
                                        <p className="text-[11px] text-muted-foreground">
                                            Officer upheld JEV classification
                                        </p>
                                    </div>

                                    <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-3.5 space-y-1">
                                        <div className="flex items-center justify-between text-amber-600 dark:text-amber-400">
                                            <span className="text-xs font-semibold uppercase tracking-wider">
                                                Overrides
                                            </span>
                                            <ShieldAlert className="size-4" />
                                        </div>
                                        <div className="text-2xl font-bold text-foreground">
                                            {approvals.override_count}
                                        </div>
                                        <p className="text-[11px] text-muted-foreground">
                                            Officer modified AI classification
                                        </p>
                                    </div>
                                </div>

                                {/* Review Turnaround Latencies */}
                                <div className="rounded-xl border border-border/80 bg-muted/20 p-3.5 space-y-2.5">
                                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Review Latency Distribution
                                    </h4>

                                    <div className="grid grid-cols-3 gap-2 text-center">
                                        <div className="rounded-lg bg-card p-2 border border-border/60">
                                            <div className="text-xs text-muted-foreground">
                                                Fastest Signoff
                                            </div>
                                            <div className="text-sm font-bold text-foreground mt-0.5">
                                                {approvals.fastest_approval_seconds !==
                                                null
                                                    ? `${Math.round(approvals.fastest_approval_seconds)}s`
                                                    : 'N/A'}
                                            </div>
                                        </div>
                                        <div className="rounded-lg bg-card p-2 border border-border/60">
                                            <div className="text-xs text-muted-foreground">
                                                Average SLA
                                            </div>
                                            <div className="text-sm font-bold text-primary mt-0.5">
                                                {
                                                    approvals.avg_time_to_approval_human
                                                }
                                            </div>
                                        </div>
                                        <div className="rounded-lg bg-card p-2 border border-border/60">
                                            <div className="text-xs text-muted-foreground">
                                                Pending Queue
                                            </div>
                                            <div className="text-sm font-bold text-amber-600 dark:text-amber-400 mt-0.5">
                                                {approvals.pending_review} items
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Compliance Guarantee Box */}
                                <Alert
                                    variant="default"
                                    className="py-2.5 text-xs"
                                >
                                    <ShieldCheck className="size-4 text-emerald-500" />
                                    <AlertTitle className="text-xs font-semibold">
                                        Dual-Custody Invariant Guaranteed
                                    </AlertTitle>
                                    <AlertDescription className="text-xs text-muted-foreground">
                                        No autonomous transaction settles to Arc
                                        without either verified rule concordance
                                        or explicit multi-signature human
                                        authorization.
                                    </AlertDescription>
                                </Alert>
                            </div>
                        </FramePanel>
                    </Frame>
                </div>

                {/* 14-Day Trajectory Trend Visualizer */}
                <Frame spacing="default">
                    <FrameHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <IconTile
                                    variant="soft"
                                    className="text-primary size-7"
                                >
                                    <Activity className="size-4" />
                                </IconTile>
                                <div>
                                    <FrameTitle className="text-base font-semibold">
                                        14-Day Evaluation & Approval Trajectory
                                    </FrameTitle>
                                    <FrameDescription className="text-xs">
                                        Daily decision volumes, match ratios,
                                        and officer approvals over the last two
                                        weeks
                                    </FrameDescription>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 text-xs">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <span className="size-2 rounded-full bg-emerald-500" />{' '}
                                    Matches
                                </span>
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <span className="size-2 rounded-full bg-amber-500" />{' '}
                                    Review / Holds
                                </span>
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <span className="size-2 rounded-full bg-indigo-500" />{' '}
                                    Staff Approved
                                </span>
                            </div>
                        </div>
                    </FrameHeader>

                    <FramePanel>
                        {trends.length === 0 ? (
                            <div className="py-12 text-center text-xs text-muted-foreground">
                                No historical trajectory data recorded for this
                                horizon.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <div className="grid grid-cols-7 sm:grid-cols-14 gap-1.5 items-end h-44 pt-6 pb-2 px-2 border-b border-border/60">
                                    {trends.map((point) => {
                                        const heightPct = Math.max(
                                            Math.round(
                                                (point.total / maxTrendTotal) *
                                                    100,
                                            ),
                                            point.total > 0 ? 8 : 2,
                                        );
                                        return (
                                            <div
                                                key={point.date}
                                                className="group relative flex flex-col items-center h-full justify-end"
                                            >
                                                {/* Tooltip on hover */}
                                                <div className="absolute -top-14 z-20 hidden group-hover:flex flex-col items-center rounded-lg bg-popover px-2.5 py-1.5 text-[11px] shadow-md border border-border whitespace-nowrap pointer-events-none">
                                                    <span className="font-semibold text-popover-foreground">
                                                        {point.label}:{' '}
                                                        {point.total} total
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {point.matches} matches
                                                        · {point.approved}{' '}
                                                        approved
                                                    </span>
                                                </div>

                                                {/* Bar Stack */}
                                                <div
                                                    style={{
                                                        height: `${heightPct}%`,
                                                    }}
                                                    className="w-full max-w-[24px] rounded-t-sm bg-muted flex flex-col justify-end overflow-hidden transition-all duration-300 group-hover:ring-2 group-hover:ring-primary/40"
                                                >
                                                    {point.matches > 0 && (
                                                        <div
                                                            style={{
                                                                height: `${Math.round((point.matches / (point.total || 1)) * 100)}%`,
                                                            }}
                                                            className="w-full bg-emerald-500"
                                                        />
                                                    )}
                                                    {point.needs_review +
                                                        point.holds >
                                                        0 && (
                                                        <div
                                                            style={{
                                                                height: `${Math.round(((point.needs_review + point.holds) / (point.total || 1)) * 100)}%`,
                                                            }}
                                                            className="w-full bg-amber-500"
                                                        />
                                                    )}
                                                </div>

                                                <span className="mt-2 text-[10px] text-muted-foreground truncate w-full text-center">
                                                    {point.label.split(' ')[0]}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </FramePanel>
                </Frame>

                {/* Audit Ledger & Decision Stream */}
                <Frame spacing="default">
                    <FrameHeader>
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div className="flex items-center gap-2">
                                <IconTile
                                    variant="soft"
                                    className="text-primary size-7"
                                >
                                    <FileSpreadsheet className="size-4" />
                                </IconTile>
                                <div>
                                    <FrameTitle className="text-base font-semibold">
                                        Policy Decision Audit Ledger
                                    </FrameTitle>
                                    <FrameDescription className="text-xs">
                                        Immutable log of evidence evaluated, JEV
                                        AI determinations, and officer signoffs
                                    </FrameDescription>
                                </div>
                            </div>

                            {/* Filter and Search Bar */}
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
                                    <Input
                                        placeholder="Search decisions..."
                                        value={searchTerm}
                                        onChange={(e) =>
                                            setSearchTerm(e.target.value)
                                        }
                                        className="h-8 w-44 sm:w-56 pl-8 text-xs bg-background"
                                    />
                                </div>

                                <div className="flex items-center gap-1 rounded-lg border border-border/80 bg-background/60 p-0.5 text-xs">
                                    <button
                                        type="button"
                                        onClick={() => setDecisionFilter('all')}
                                        className={`px-2 py-1 rounded-md font-medium transition-colors ${
                                            decisionFilter === 'all'
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        All ({recentAudit.length})
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDecisionFilter('match')
                                        }
                                        className={`px-2 py-1 rounded-md font-medium transition-colors ${
                                            decisionFilter === 'match'
                                                ? 'bg-emerald-600 text-white'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        Matches
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDecisionFilter('needs_review')
                                        }
                                        className={`px-2 py-1 rounded-md font-medium transition-colors ${
                                            decisionFilter === 'needs_review'
                                                ? 'bg-amber-600 text-white'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        Review
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDecisionFilter('overrides')
                                        }
                                        className={`px-2 py-1 rounded-md font-medium transition-colors ${
                                            decisionFilter === 'overrides'
                                                ? 'bg-rose-600 text-white'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        Overrides
                                    </button>
                                </div>
                            </div>
                        </div>
                    </FrameHeader>

                    <FramePanel>
                        {filteredAudit.length === 0 ? (
                            <div className="py-12 text-center text-xs text-muted-foreground">
                                No decisions matched the current search or
                                filters.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-border/80 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                            <th className="py-2.5 px-3">
                                                Evidence Ref
                                            </th>
                                            <th className="py-2.5 px-3">
                                                JEV Decision
                                            </th>
                                            <th className="py-2.5 px-3">
                                                Category Match
                                            </th>
                                            <th className="py-2.5 px-3">
                                                Confidence
                                            </th>
                                            <th className="py-2.5 px-3">
                                                Officer Signoff
                                            </th>
                                            <th className="py-2.5 px-3">
                                                Timestamp
                                            </th>
                                            <th className="py-2.5 px-3 text-right">
                                                Action
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border/50">
                                        {filteredAudit.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="transition-colors hover:bg-muted/30"
                                            >
                                                <td className="py-3 px-3">
                                                    <div
                                                        className="font-medium text-foreground truncate max-w-[200px]"
                                                        title={item.label}
                                                    >
                                                        {item.label}
                                                    </div>
                                                    <div className="text-[10px] text-muted-foreground">
                                                        #{item.source_id} ·{' '}
                                                        {item.source_type}
                                                    </div>
                                                </td>

                                                <td className="py-3 px-3">
                                                    <Badge
                                                        variant={
                                                            item.jev_decision ===
                                                            'match'
                                                                ? 'success-light'
                                                                : item.jev_decision ===
                                                                    'needs_review'
                                                                  ? 'warning-light'
                                                                  : item.jev_decision ===
                                                                      'hold'
                                                                    ? 'destructive-light'
                                                                    : 'outline'
                                                        }
                                                        size="xs"
                                                    >
                                                        {item.jev_decision}
                                                    </Badge>
                                                </td>

                                                <td className="py-3 px-3">
                                                    <span className="font-medium text-foreground">
                                                        {item.category_name ??
                                                            '—'}
                                                    </span>
                                                </td>

                                                <td className="py-3 px-3">
                                                    {item.confidence_percent !==
                                                    null ? (
                                                        <span className="font-mono text-xs font-semibold text-foreground">
                                                            {
                                                                item.confidence_percent
                                                            }
                                                            %
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="py-3 px-3">
                                                    <div className="flex items-center gap-1.5">
                                                        <Badge
                                                            variant={
                                                                item.staff_status ===
                                                                'approved'
                                                                    ? 'success-light'
                                                                    : item.staff_status ===
                                                                        'rejected'
                                                                      ? 'destructive-light'
                                                                      : item.staff_status ===
                                                                          'held'
                                                                        ? 'warning-light'
                                                                        : 'outline'
                                                            }
                                                            size="xs"
                                                        >
                                                            {item.staff_status}
                                                        </Badge>
                                                        {item.is_override && (
                                                            <Badge
                                                                variant="destructive"
                                                                size="xs"
                                                            >
                                                                Overridden
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {item.reviewer_name && (
                                                        <div className="text-[10px] text-muted-foreground mt-0.5">
                                                            by{' '}
                                                            {item.reviewer_name}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="py-3 px-3 text-muted-foreground">
                                                    {new Date(
                                                        item.created_at,
                                                    ).toLocaleString()}
                                                </td>

                                                <td className="py-3 px-3 text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                        className="h-7 gap-1 px-2 text-xs text-primary hover:text-primary/80"
                                                    >
                                                        <a
                                                            href={`${urls.evaluationsUrl}/${item.id}`}
                                                        >
                                                            <Eye className="size-3" />
                                                            Inspect
                                                        </a>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </FramePanel>
                </Frame>
            </div>
        </div>
    );
}
