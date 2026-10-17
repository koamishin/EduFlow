import {
    Activity,
    AlertTriangle,
    Bot,
    BrainCircuit,
    CheckCircle2,
    Cpu,
    ExternalLink,
    Power,
    RefreshCw,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useState } from 'react';
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

export type JevStatus = {
    status: 'online' | 'draft_only' | 'no_policy';
    policy_id: number | null;
    policy_title: string;
    policy_digest: string | null;
    has_active_controls: boolean;
    categories_count: number;
    clauses_count: number;
    published_at: string | null;
    message: string;
};

export type AiStatus = {
    status: 'online' | 'disabled' | 'offline' | 'degraded' | 'misconfigured';
    provider: string;
    model: string;
    endpoint: string;
    advisory_enabled: boolean;
    disclosure_accepted: boolean;
    may_call_provider: boolean;
    latency_ms: number | null;
    message: string;
    tested_at: string | null;
};

export type JevAiHealth = {
    jev: JevStatus;
    ai: AiStatus;
    timestamp: string;
};

type Props = {
    initialHealth?: JevAiHealth | null;
    mode?: 'full' | 'compact' | 'simulator-banner';
    onHealthChange?: (health: JevAiHealth) => void;
    className?: string;
};

export function AgentHealthStatus({
    initialHealth,
    mode = 'full',
    onHealthChange,
    className = '',
}: Props) {
    const defaultHealth: JevAiHealth = {
        jev: {
            status: 'online',
            policy_id: null,
            policy_title: 'Deterministic Policy Engine',
            policy_digest: null,
            has_active_controls: true,
            categories_count: 0,
            clauses_count: 0,
            published_at: null,
            message: 'Policy engine ready.',
        },
        ai: {
            status: 'online',
            provider: 'local',
            model: 'ag/gemini-3.8-flash-high',
            endpoint: 'https://9router.koamishin.com/v1',
            advisory_enabled: true,
            disclosure_accepted: true,
            may_call_provider: true,
            latency_ms: null,
            message: 'AI Agent ready.',
            tested_at: null,
        },
        timestamp: new Date().toISOString(),
    };

    const [health, setHealth] = useState<JevAiHealth>(initialHealth ?? defaultHealth);
    const [testing, setTesting] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [testFeedback, setTestFeedback] = useState<string | null>(null);

    const getCsrfToken = () => {
        return (
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                ?.content ?? ''
        );
    };

    const handleTestConnection = async () => {
        setTesting(true);
        setTestFeedback(null);
        try {
            const res = await fetch('/finance/ai/health?force_ping=1', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });
            const json = await res.json();
            if (json.data) {
                setHealth(json.data);
                onHealthChange?.(json.data);
                if (json.data.ai?.status === 'online') {
                    setTestFeedback(
                        `AI Agent Online! Latency: ${json.data.ai.latency_ms ?? 0}ms`,
                    );
                } else {
                    setTestFeedback(
                        json.data.ai?.message || 'AI Agent is currently unreachable.',
                    );
                }
            }
        } catch (err: any) {
            setTestFeedback(err?.message || 'Connection test failed.');
        } finally {
            setTesting(false);
        }
    };

    const handleToggleAdvisory = async (enable: boolean) => {
        setToggling(true);
        try {
            const res = await fetch('/finance/ai/toggle-advisory', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ enable }),
            });
            const json = await res.json();
            if (json.data) {
                setHealth(json.data);
                onHealthChange?.(json.data);
                setTestFeedback(json.message);
            }
        } catch (err: any) {
            setTestFeedback(err?.message || 'Failed to update advisory settings.');
        } finally {
            setToggling(false);
        }
    };

    // Compact Mode (for header bars or quick status indicators)
    if (mode === 'compact') {
        return (
            <div
                className={`inline-flex flex-wrap items-center gap-2 rounded-lg border border-border/70 bg-card/60 px-3 py-1.5 backdrop-blur-xs text-xs ${className}`}
            >
                {/* JEV Status */}
                <div className="flex items-center gap-1.5">
                    <span
                        className={`size-2 rounded-full ${
                            health.jev.status === 'online'
                                ? 'bg-emerald-500 animate-pulse'
                                : health.jev.status === 'draft_only'
                                  ? 'bg-amber-500'
                                  : 'bg-rose-500'
                        }`}
                    />
                    <span className="font-semibold text-foreground">JEV Brain:</span>
                    <Badge
                        variant={
                            health.jev.status === 'online'
                                ? 'success-light'
                                : health.jev.status === 'draft_only'
                                  ? 'warning-light'
                                  : 'destructive-light'
                        }
                        size="xs"
                    >
                        {health.jev.status === 'online'
                            ? `Policy Active (#${health.jev.policy_id ?? '1'})`
                            : health.jev.status === 'draft_only'
                              ? 'Draft Mode'
                              : 'No Policy'}
                    </Badge>
                </div>

                <span className="text-muted-foreground/40">|</span>

                {/* AI Agent Status */}
                <div className="flex items-center gap-1.5">
                    <span
                        className={`size-2 rounded-full ${
                            health.ai.status === 'online'
                                ? 'bg-emerald-500'
                                : health.ai.status === 'disabled'
                                  ? 'bg-amber-500'
                                  : 'bg-rose-500'
                        }`}
                    />
                    <span className="font-semibold text-foreground">AI Agent:</span>
                    <Badge
                        variant={
                            health.ai.status === 'online'
                                ? 'success-light'
                                : health.ai.status === 'disabled'
                                  ? 'warning-light'
                                  : 'destructive-light'
                        }
                        size="xs"
                    >
                        {health.ai.status === 'online'
                            ? `Online (${health.ai.latency_ms ?? '~2.4s'})`
                            : health.ai.status === 'disabled'
                              ? 'Advisory Disabled'
                              : 'Offline'}
                    </Badge>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={testing}
                    onClick={handleTestConnection}
                    className="h-6 gap-1 px-2 text-xs font-medium text-muted-foreground hover:text-foreground"
                    title="Ping AI Agent & Check JEV Status"
                >
                    <RefreshCw className={`size-3 ${testing ? 'animate-spin' : ''}`} />
                    {testing ? 'Testing...' : 'Test Connection'}
                </Button>
            </div>
        );
    }

    // Simulator Pre-Flight Banner Mode
    if (mode === 'simulator-banner') {
        return (
            <div className={`space-y-3 ${className}`}>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    {/* JEV Engine Tile */}
                    <div className="flex items-start gap-3 rounded-xl border border-border/80 bg-card p-3.5 shadow-xs">
                        <IconTile
                            variant="soft"
                            className={
                                health.jev.status === 'online'
                                    ? 'text-emerald-500'
                                    : health.jev.status === 'draft_only'
                                      ? 'text-amber-500'
                                      : 'text-rose-500'
                            }
                        >
                            <BrainCircuit className="size-4" />
                        </IconTile>
                        <div className="min-w-0 flex-1 space-y-1">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    JEV Policy Brain
                                </span>
                                <Badge
                                    variant={
                                        health.jev.status === 'online'
                                            ? 'success-light'
                                            : health.jev.status === 'draft_only'
                                              ? 'warning-light'
                                              : 'destructive-light'
                                    }
                                    size="sm"
                                >
                                    {health.jev.status === 'online'
                                        ? 'Active & Ready'
                                        : health.jev.status === 'draft_only'
                                          ? 'Draft Mode'
                                          : 'Missing Policy'}
                                </Badge>
                            </div>
                            <p className="truncate text-sm font-medium text-foreground">
                                {health.jev.policy_title}
                            </p>
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span>{health.jev.categories_count} Categories</span>
                                <span>·</span>
                                <span>{health.jev.clauses_count} Clauses</span>
                                {health.jev.has_active_controls && (
                                    <>
                                        <span>·</span>
                                        <span className="text-emerald-600 dark:text-emerald-400">
                                            Controls Active
                                        </span>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* AI Agent Tile */}
                    <div className="flex items-start gap-3 rounded-xl border border-border/80 bg-card p-3.5 shadow-xs">
                        <IconTile
                            variant="soft"
                            className={
                                health.ai.status === 'online'
                                    ? 'text-primary'
                                    : health.ai.status === 'disabled'
                                      ? 'text-amber-500'
                                      : 'text-rose-500'
                            }
                        >
                            <Bot className="size-4" />
                        </IconTile>
                        <div className="min-w-0 flex-1 space-y-1">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    AI Advisory Agent
                                </span>
                                <Badge
                                    variant={
                                        health.ai.status === 'online'
                                            ? 'success-light'
                                            : health.ai.status === 'disabled'
                                              ? 'warning-light'
                                              : 'destructive-light'
                                    }
                                    size="sm"
                                >
                                    {health.ai.status === 'online'
                                        ? `Online ${health.ai.latency_ms ? `(${health.ai.latency_ms}ms)` : ''}`
                                        : health.ai.status === 'disabled'
                                          ? 'Advisory Disabled'
                                          : 'Offline'}
                                </Badge>
                            </div>
                            <p className="truncate text-sm font-medium font-mono text-foreground">
                                {health.ai.model}
                            </p>
                            <div className="flex items-center justify-between text-xs text-muted-foreground">
                                <span className="truncate max-w-[200px]" title={health.ai.endpoint}>
                                    {health.ai.endpoint.replace('https://', '')}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={testing}
                                    onClick={handleTestConnection}
                                    className="h-5 px-1.5 text-xs text-primary hover:text-primary/80"
                                >
                                    <RefreshCw className={`size-3 mr-1 ${testing ? 'animate-spin' : ''}`} />
                                    {testing ? 'Testing...' : 'Ping Agent'}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Status Notice or Feedback */}
                {testFeedback && (
                    <Alert
                        variant={health.ai.status === 'online' ? 'success' : 'warning'}
                        className="py-2 text-xs"
                    >
                        <CheckCircle2 className="size-3.5" />
                        <AlertTitle className="text-xs font-medium">Connectivity Test Result</AlertTitle>
                        <AlertDescription className="text-xs">
                            {testFeedback}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Warning if AI is disabled */}
                {health.ai.status === 'disabled' && (
                    <Alert variant="warning" className="py-2.5">
                        <AlertTriangle className="size-4" />
                        <div className="space-y-1">
                            <AlertTitle className="text-xs font-semibold">
                                AI Advisory is currently disabled
                            </AlertTitle>
                            <AlertDescription className="text-xs text-muted-foreground">
                                JEV will simulate using deterministic policy matching. To enable AI neural classification, acknowledge disclosure:
                            </AlertDescription>
                            <div className="pt-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={toggling}
                                    onClick={() => handleToggleAdvisory(true)}
                                    className="gap-1 h-6 text-xs"
                                >
                                    <Power className="size-3" />
                                    {toggling ? 'Enabling...' : 'Enable AI Advisory'}
                                </Button>
                            </div>
                        </div>
                    </Alert>
                )}
            </div>
        );
    }

    // Full Executive Frame Mode (for Analytics Page & Policy Header)
    return (
        <Frame spacing="default" className={className}>
            <FrameHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <IconTile variant="soft" className="text-primary">
                            <Cpu className="size-4" />
                        </IconTile>
                        <div>
                            <FrameTitle className="text-base font-semibold">
                                JEV Engine & AI Agent Operational Telemetry
                            </FrameTitle>
                            <FrameDescription className="text-xs">
                                Live health, neural response latency, and fail-closed deterministic policy status
                            </FrameDescription>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={testing}
                            onClick={handleTestConnection}
                            className="gap-1.5 text-xs font-medium"
                        >
                            <RefreshCw
                                className={`size-3.5 ${testing ? 'animate-spin' : ''}`}
                            />
                            {testing ? 'Testing Agent...' : 'Test AI Connection'}
                        </Button>
                    </div>
                </div>
            </FrameHeader>

            <FramePanel>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/* Left: JEV Policy Engine Status */}
                    <div className="rounded-xl border border-border/80 bg-background/50 p-4 space-y-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <IconTile
                                    variant="soft"
                                    className={
                                        health.jev.status === 'online'
                                            ? 'text-emerald-500'
                                            : health.jev.status === 'draft_only'
                                              ? 'text-amber-500'
                                              : 'text-rose-500'
                                    }
                                >
                                    <ShieldCheck className="size-4" />
                                </IconTile>
                                <div>
                                    <h4 className="text-sm font-semibold text-foreground">
                                        JEV Policy Brain
                                    </h4>
                                    <p className="text-xs text-muted-foreground">
                                        Authoritative deterministic engine
                                    </p>
                                </div>
                            </div>

                            <Badge
                                variant={
                                    health.jev.status === 'online'
                                        ? 'success-light'
                                        : health.jev.status === 'draft_only'
                                          ? 'warning-light'
                                          : 'destructive-light'
                                }
                                size="sm"
                            >
                                {health.jev.status === 'online'
                                    ? 'Online & Enforcing'
                                    : health.jev.status === 'draft_only'
                                      ? 'Draft Policy'
                                      : 'No Policy Active'}
                            </Badge>
                        </div>

                        <div className="rounded-lg bg-muted/40 p-3 space-y-2 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Active Document:</span>
                                <span className="font-medium text-foreground truncate max-w-[220px]" title={health.jev.policy_title}>
                                    {health.jev.policy_title}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Rules Loaded:</span>
                                <span className="font-semibold text-foreground">
                                    {health.jev.categories_count} Categories · {health.jev.clauses_count} Clauses
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Financial Controls:</span>
                                <Badge
                                    variant={health.jev.has_active_controls ? 'success-light' : 'warning-light'}
                                    size="sm"
                                >
                                    {health.jev.has_active_controls ? 'Active & Enforcing' : 'Standby / Paused'}
                                </Badge>
                            </div>
                            {health.jev.published_at && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Published At:</span>
                                    <span className="text-foreground">
                                        {new Date(health.jev.published_at).toLocaleString()}
                                    </span>
                                </div>
                            )}
                        </div>

                        <p className="text-[11px] text-muted-foreground flex items-center gap-1.5">
                            <Shield className="size-3 text-emerald-500 shrink-0" />
                            <span>
                                Fail-closed architecture: JEV holds any ambiguous transactions for dual-custody human signoff.
                            </span>
                        </p>
                    </div>

                    {/* Right: AI Advisory Agent Status */}
                    <div className="rounded-xl border border-border/80 bg-background/50 p-4 space-y-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <IconTile
                                    variant="soft"
                                    className={
                                        health.ai.status === 'online'
                                            ? 'text-primary'
                                            : health.ai.status === 'disabled'
                                              ? 'text-amber-500'
                                              : 'text-rose-500'
                                    }
                                >
                                    <Bot className="size-4" />
                                </IconTile>
                                <div>
                                    <h4 className="text-sm font-semibold text-foreground">
                                        Autonomous AI Agent
                                    </h4>
                                    <p className="text-xs text-muted-foreground">
                                        Neural policy classifier & pipeline advisor
                                    </p>
                                </div>
                            </div>

                            <Badge
                                variant={
                                    health.ai.status === 'online'
                                        ? 'success-light'
                                        : health.ai.status === 'disabled'
                                          ? 'warning-light'
                                          : 'destructive-light'
                                }
                                size="sm"
                            >
                                {health.ai.status === 'online'
                                    ? `Online (${health.ai.latency_ms ?? '~2.4s'})`
                                    : health.ai.status === 'disabled'
                                      ? 'Advisory Disabled'
                                      : 'Offline'}
                            </Badge>
                        </div>

                        <div className="rounded-lg bg-muted/40 p-3 space-y-2 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">AI Model:</span>
                                <span className="font-mono font-medium text-foreground">
                                    {health.ai.model}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Gateway Endpoint:</span>
                                <span className="font-mono text-muted-foreground truncate max-w-[200px]" title={health.ai.endpoint}>
                                    {health.ai.endpoint}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Roundtrip Latency:</span>
                                <span className="font-semibold text-foreground">
                                    {health.ai.latency_ms ? `${health.ai.latency_ms} ms` : 'Awaiting ping test'}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Provider Disclosure:</span>
                                <Badge
                                    variant={health.ai.disclosure_accepted ? 'success-light' : 'warning-light'}
                                    size="sm"
                                >
                                    {health.ai.disclosure_accepted ? 'Acknowledged' : 'Pending Signoff'}
                                </Badge>
                            </div>
                        </div>

                        {/* Actions & Feedback */}
                        {health.ai.status === 'disabled' ? (
                            <div className="flex items-center justify-between gap-2 pt-1">
                                <p className="text-[11px] text-amber-600 dark:text-amber-400">
                                    Advisory calls are disabled.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={toggling}
                                    onClick={() => handleToggleAdvisory(true)}
                                    className="gap-1 text-xs"
                                >
                                    <Power className="size-3" />
                                    {toggling ? 'Enabling...' : 'Enable AI Advisory'}
                                </Button>
                            </div>
                        ) : (
                            <p className="text-[11px] text-muted-foreground flex items-center gap-1.5">
                                <Sparkles className="size-3 text-amber-500 shrink-0" />
                                <span>
                                    {health.ai.status === 'online'
                                        ? 'Autonomous advisory agent is actively scoring live evidence and simulator scenarios.'
                                        : 'Agent is unreachable. JEV runs fail-closed deterministic policy checks.'}
                                </span>
                            </p>
                        )}
                    </div>
                </div>

                {testFeedback && (
                    <div className="mt-4">
                        <Alert
                            variant={health.ai.status === 'online' ? 'success' : 'warning'}
                            className="text-xs py-2"
                        >
                            <CheckCircle2 className="size-3.5" />
                            <AlertTitle className="text-xs font-medium">Connectivity Test Status</AlertTitle>
                            <AlertDescription className="text-xs">
                                {testFeedback}
                            </AlertDescription>
                        </Alert>
                    </div>
                )}
            </FramePanel>
        </Frame>
    );
}
