import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    BadgeCheck,
    Eye,
    EyeOff,
    ReceiptText,
    Sparkles,
    Wallet,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { AskEduFlow } from '@/components/ask-eduflow';
import { RequestStatus } from '@/components/financial-aid';
import { Badge } from '@/components/ui/badge';
import {
    formatAidLabel,
    formatSubmittedAt,
    formatUsdc,
} from '@/lib/financial-aid';
import { show } from '@/routes/assistance';
import { index as financialAssistanceIndex } from '@/routes/financial-assistance';
import { index as paymentsIndex } from '@/routes/payments';
import { dashboard } from '@/routes/student';
import type { StudentDashboardProps } from '@/types/financial-aid';

function statusPillClass(status: string): string {
    switch (status) {
        case 'confirmed':
            return 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]';
        case 'pending':
            return 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]';
        case 'failed':
            return 'bg-[var(--status-urgent)]/10 text-[var(--status-urgent)]';
        default:
            return 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]';
    }
}

export default function StudentDashboard({
    student,
    tuitionAccount,
    requests,
    currency,
    suggestedQuestions,
    wallet,
    totals,
    recentTransactions,
    eligibility,
}: StudentDashboardProps) {
    const [balanceVisible, setBalanceVisible] = useState(true);
    const [eligibilityVisible, setEligibilityVisible] = useState(false);
    const eligibilityRef = useRef<HTMLElement | null>(null);
    const firstName = student.name.split(' ')[0];
    const transactions = recentTransactions ?? [];

    const openEligibility = () => {
        setEligibilityVisible(true);
        // Let the card mount before scrolling it into view.
        requestAnimationFrame(() => {
            eligibilityRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    };

    return (
        <>
            <Head title="Student dashboard" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-6 sm:gap-10 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    {/* -- Header: greeting + eligibility CTA -- */}
                    <header className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div className="min-w-0">
                            <p className="bulletin-eyebrow">
                                EduFlow &middot; Northstar Learning Center
                            </p>
                            <h1 className="clay-h1 mt-2">
                                Welcome back,{' '}
                                <span className="text-[var(--clay-primary-bright)]">
                                    {firstName}
                                </span>
                            </h1>
                            <p className="clay-body mt-1.5 max-w-md">
                                {student.program} &middot; Year{' '}
                                {student.year_level} &middot;{' '}
                                {student.student_number}
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={openEligibility}
                            className="clay-focus h-11 shrink-0 gap-2 rounded-full bg-[var(--clay-primary)] px-6 text-sm font-semibold text-[var(--clay-primary-foreground)] transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-primary-bright)] active:translate-y-0 active:scale-[0.98]"
                        >
                            <span className="inline-flex items-center gap-2">
                                <BadgeCheck
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Check eligibility
                            </span>
                        </button>
                    </header>

                    {/* -- Balance hero -- */}
                    <section
                        aria-labelledby="balance-heading"
                        className="grid gap-4 lg:grid-cols-3"
                    >
                        <div className="clay-card p-5 sm:p-8 lg:col-span-2">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <p
                                        id="balance-heading"
                                        className="bulletin-eyebrow"
                                    >
                                        Outstanding tuition balance
                                    </p>
                                    <p className="clay-meta mt-1">
                                        {tuitionAccount?.term ??
                                            'No active term'}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setBalanceVisible((value) => !value)
                                    }
                                    className="clay-focus inline-flex size-9 items-center justify-center rounded-full text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                    aria-label={
                                        balanceVisible
                                            ? 'Hide balance'
                                            : 'Show balance'
                                    }
                                >
                                    {balanceVisible ? (
                                        <Eye className="size-4" />
                                    ) : (
                                        <EyeOff className="size-4" />
                                    )}
                                </button>
                            </div>

                            {tuitionAccount ? (
                                <>
                                    <p className="bulletin-figure mt-4 text-4xl sm:text-5xl">
                                        {balanceVisible
                                            ? formatUsdc(
                                                  tuitionAccount.remaining_amount,
                                              )
                                            : '••••••••'}
                                    </p>
                                    {tuitionAccount.remaining_amount_fiat && (
                                        <p className="clay-meta mt-2 text-[var(--clay-text-muted)]">
                                            ≈{' '}
                                            {
                                                tuitionAccount.remaining_amount_fiat
                                            }
                                        </p>
                                    )}

                                    <dl className="mt-6 grid grid-cols-2 gap-4 border-t border-[var(--clay-border)] pt-5">
                                        <div>
                                            <dt className="bulletin-eyebrow">
                                                Total tuition
                                            </dt>
                                            <dd className="clay-title mt-1.5 tabular-nums">
                                                {balanceVisible
                                                    ? formatUsdc(
                                                          tuitionAccount.total_amount,
                                                      )
                                                    : '••••'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="bulletin-eyebrow">
                                                Paid
                                            </dt>
                                            <dd className="clay-title mt-1.5 tabular-nums text-[var(--status-resolved)]">
                                                {balanceVisible
                                                    ? formatUsdc(
                                                          tuitionAccount.paid_amount,
                                                      )
                                                    : '••••'}
                                            </dd>
                                        </div>
                                    </dl>
                                </>
                            ) : (
                                <div className="mt-4 space-y-1.5">
                                    <p className="clay-h2">
                                        No tuition account yet.
                                    </p>
                                    <p className="clay-body">
                                        Your term and balance will appear here
                                        when an account is available.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* -- Wallet / payouts card -- */}
                        <div className="clay-card flex flex-col p-5 sm:p-6">
                            <p className="bulletin-eyebrow">Total received</p>
                            <p className="bulletin-figure mt-3 text-3xl">
                                {totals?.confirmed ?? 0}{' '}
                                <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                    {totals?.currency ?? 'USDC'}
                                </span>
                            </p>

                            <div className="mt-auto pt-6">
                                {wallet?.address ? (
                                    <Link
                                        href={paymentsIndex()}
                                        className="clay-focus flex items-center gap-2.5 rounded-full border border-[var(--clay-border)] px-4 py-2.5 text-xs transition-colors hover:bg-[var(--clay-surface-soft)]"
                                    >
                                        <Wallet className="size-4 text-[var(--clay-text-muted)]" />
                                        <span className="clay-meta truncate">
                                            {wallet.address.slice(0, 6)}…
                                            {wallet.address.slice(-4)}
                                        </span>
                                    </Link>
                                ) : (
                                    <Link
                                        href={financialAssistanceIndex()}
                                        className="clay-focus flex items-center gap-2.5 rounded-full border border-dashed border-[var(--clay-border)] px-4 py-2.5 text-xs text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)]"
                                    >
                                        <Wallet className="size-4" />
                                        Link a payout wallet
                                    </Link>
                                )}
                            </div>
                        </div>
                    </section>

                    {/* -- Assistance eligibility: on-demand check, no request needed -- */}
                    {eligibilityVisible && (
                        <section
                            ref={eligibilityRef}
                            aria-labelledby="eligibility-heading"
                            className="clay-card scroll-mt-6 p-5 sm:p-6"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p
                                        id="eligibility-heading"
                                        className="bulletin-eyebrow"
                                    >
                                        Assistance eligibility
                                    </p>
                                    <p className="clay-meta mt-1">
                                        {eligibility?.policy_version
                                            ? `Policy ${eligibility.policy_version} · checked automatically`
                                            : 'No active policy'}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    <span
                                        className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${
                                            eligibility?.eligible
                                                ? 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]'
                                                : 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]'
                                        }`}
                                    >
                                        <BadgeCheck className="size-3.5" />
                                        {eligibility?.eligible
                                            ? 'Eligible'
                                            : 'Not eligible'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setEligibilityVisible(false)
                                        }
                                        className="clay-meta clay-focus rounded-full transition-colors hover:text-[var(--clay-text)]"
                                    >
                                        Hide
                                    </button>
                                </div>
                            </div>

                            {eligibility?.eligible ? (
                                <p className="bulletin-figure mt-4 text-4xl sm:text-5xl">
                                    {formatUsdc(
                                        eligibility.eligible_amount_base_units,
                                    )}
                                </p>
                            ) : (
                                <p className="clay-body mt-4 max-w-md">
                                    You do not currently meet the assistance
                                    gates below. They are re-checked
                                    automatically — nothing to file.
                                </p>
                            )}

                            <ul className="mt-6 grid gap-x-6 gap-y-2.5 border-t border-[var(--clay-border)] pt-5 sm:grid-cols-2">
                                {(eligibility?.checks ?? []).map((check) => (
                                    <li
                                        key={check.key}
                                        className="flex items-center gap-2.5 text-sm"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`size-2 shrink-0 rounded-full ${
                                                check.passed
                                                    ? 'bg-[var(--status-resolved)]'
                                                    : 'bg-[var(--status-urgent)]'
                                            }`}
                                        />
                                        <span
                                            className={
                                                check.passed
                                                    ? 'text-[var(--clay-text)]'
                                                    : 'text-[var(--clay-text-muted)]'
                                            }
                                        >
                                            {check.label}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            <p className="clay-meta mt-5">
                                A preview, not an approval — any disbursement
                                still follows school review.
                            </p>
                        </section>
                    )}

                    {/* -- Quick actions -- */}
                    <nav
                        aria-label="Quick actions"
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    >
                        {[
                            {
                                href: paymentsIndex().url,
                                label: 'Payments',
                                icon: ReceiptText,
                            },
                            {
                                href: financialAssistanceIndex().url,
                                label: 'Financial aid',
                                icon: ArrowDownLeft,
                            },
                            {
                                href: '#ask-eduflow',
                                label: 'Ask EduFlow',
                                icon: Sparkles,
                            },
                        ].map(({ href, label, icon: Icon }) =>
                            href.startsWith('#') ? (
                                <a
                                    key={label}
                                    href={href}
                                    className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-sm font-medium transition-transform hover:-translate-y-0.5"
                                >
                                    <span className="clay-icon-chip size-9">
                                        <Icon className="size-4" />
                                    </span>
                                    {label}
                                </a>
                            ) : (
                                <Link
                                    key={label}
                                    href={href}
                                    className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-sm font-medium transition-transform hover:-translate-y-0.5"
                                >
                                    <span className="clay-icon-chip size-9">
                                        <Icon className="size-4" />
                                    </span>
                                    {label}
                                </Link>
                            ),
                        )}
                    </nav>

                    {/* -- Recent activity -- */}
                    <section
                        aria-labelledby="activity-heading"
                        className="space-y-4"
                    >
                        <div className="flex items-baseline justify-between gap-4">
                            <h2 id="activity-heading" className="clay-h2">
                                Recent activity
                            </h2>
                            <Link
                                href={paymentsIndex()}
                                className="clay-meta transition-colors hover:text-[var(--clay-text)]"
                            >
                                View all
                            </Link>
                        </div>

                        {transactions.length === 0 ? (
                            <div className="clay-card p-8 text-center">
                                <p className="clay-title">No payouts yet.</p>
                                <p className="clay-body mx-auto mt-1.5 max-w-md">
                                    Approved assistance lands here as confirmed{' '}
                                    {totals?.currency ?? 'USDC'} transactions.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {transactions.map((tx, index) => (
                                    <li
                                        key={tx.id}
                                        className="clay-card clay-rise p-4 sm:p-5"
                                        style={
                                            {
                                                '--stagger': Math.min(index, 8),
                                            } as React.CSSProperties
                                        }
                                    >
                                        <div className="flex items-start gap-3">
                                            <span className="clay-icon-chip size-9 shrink-0">
                                                <ArrowDownLeft className="size-4" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span className="clay-title">
                                                        +{tx.amount}{' '}
                                                        {tx.currency}
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${statusPillClass(tx.status)}`}
                                                    >
                                                        {tx.status_label}
                                                    </span>
                                                    <span className="clay-meta">
                                                        {tx.type_label}
                                                    </span>
                                                </div>
                                                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    <span className="clay-meta">
                                                        {tx.network}
                                                    </span>
                                                    <span className="clay-meta">
                                                        {tx.executed_at}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* -- Ask EduFlow -- */}
                    <section id="ask-eduflow">
                        <AskEduFlow
                            suggestedQuestions={suggestedQuestions}
                            displayCurrency={currency?.display}
                            rateDescription={currency?.rate_description}
                        />
                    </section>

                    {/* -- Assistance requests -- */}
                    <section
                        aria-labelledby="requests-heading"
                        className="space-y-4"
                    >
                        <div className="flex items-baseline justify-between gap-4">
                            <h2 id="requests-heading" className="clay-h2">
                                Your assistance requests
                            </h2>
                            <span className="clay-meta">
                                {requests.length} total
                            </span>
                        </div>

                        {requests.length === 0 ? (
                            <div className="clay-card p-8 text-center">
                                <p className="clay-title">
                                    No assistance cases on file.
                                </p>
                                <p className="clay-body mx-auto mt-1.5 max-w-md">
                                    When the school evaluates you for support,
                                    the decision and its explanation appear
                                    here.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {requests.map((request, index) => (
                                    <li
                                        key={request.id}
                                        className="clay-card clay-rise p-4 sm:p-5"
                                        style={
                                            {
                                                '--stagger': Math.min(index, 8),
                                            } as React.CSSProperties
                                        }
                                    >
                                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="min-w-0 space-y-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={show({
                                                            assistanceRequest:
                                                                request.id,
                                                        })}
                                                        className="clay-focus inline-flex items-center gap-2 rounded-sm font-semibold underline-offset-4 hover:underline"
                                                    >
                                                        {formatAidLabel(
                                                            request.type,
                                                        )}{' '}
                                                        assistance{' '}
                                                        <span className="text-[var(--clay-text-muted)]">
                                                            #{request.id}
                                                        </span>
                                                        <ArrowRight
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                    </Link>

                                                    {request.is_split && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="gap-1 border border-[var(--status-pending)]/40 bg-[var(--status-pending)]/10 text-[10px] text-[var(--status-pending)]"
                                                        >
                                                            <Sparkles className="size-2.5" />
                                                            Autonomous Split
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="clay-meta">
                                                    Submitted{' '}
                                                    <time
                                                        dateTime={
                                                            request.submitted_at
                                                        }
                                                    >
                                                        {formatSubmittedAt(
                                                            request.submitted_at,
                                                        )}
                                                    </time>
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap items-center justify-between gap-4 sm:justify-end">
                                                <div className="text-right">
                                                    <span className="tabular-nums font-semibold">
                                                        {formatUsdc(
                                                            request.requested_amount,
                                                        )}
                                                    </span>
                                                    {request.is_split && (
                                                        <p className="clay-meta">
                                                            {formatUsdc(
                                                                request.auto_approved_amount,
                                                            )}{' '}
                                                            auto · remainder
                                                            pending
                                                        </p>
                                                    )}
                                                </div>
                                                <RequestStatus
                                                    status={request.status}
                                                />
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

StudentDashboard.layout = {
    breadcrumbs: [{ title: 'Student dashboard', href: dashboard() }],
};
