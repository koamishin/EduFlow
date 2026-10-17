import { Head, router, useHttp, usePage, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpenText,
    Check,
    CheckCheck,
    CheckCircle2,
    FileText,
    FlaskConical,
    History,
    Layers,
    ListFilter,
    Plus,
    Save,
    Send,
    ShieldCheck,
    SlidersHorizontal,
    Sparkles,
    Trash2,
    Wand2,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    AgentHealthStatus,
    type JevAiHealth,
} from '@/components/jev/agent-health-status';
import { Alert, AlertDescription, AlertTitle } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { assess, publish, store } from '@/routes/finance/policies';
import { importMethod as importDocument } from '@/actions/App/Http/Controllers/InstitutionPolicyController';

type Clause = { id: string; title: string; text: string };
type Category = {
    id: string;
    name: string;
    description: string;
    inclusions: string;
    exclusions: string;
    clause_ids: string[];
};
type Example = { description: string; category_id: string };
type Controls = {
    minimum_reserve: string;
    max_auto_payment: string;
    max_daily_disbursement: string;
    max_fee: string;
};
type Content = {
    title: string;
    clauses: Clause[];
    categories: Category[];
    examples: Example[];
    controls: Controls;
    priorities: string;
    clarifications: string[];
    predecessor_id?: number | null;
};
type Version = {
    id: number;
    title: string;
    created_by: number;
    content: Content;
    content_digest: string;
    created_at: string;
};
type Judgment = {
    category_id: string | null;
    explanation: string;
    questions: string[];
    missing_evidence: string[];
    citations: { clause_id: string; quote: string }[];
    priority: string;
    needs_review: boolean;
    can_execute: false;
};
type Assessment = {
    id: number;
    institution_policy_version_id: number;
    kind: string;
    state: string;
    result:
        | (Partial<Judgment> & {
              all_passed?: boolean;
              examples?: {
                  index: number;
                  expected: string;
                  actual: string;
                  passed: boolean;
                  assessment: Judgment;
              }[];
          })
        | null;
    last_error: string | null;
    provider: string | null;
    model: string | null;
    policy_digest: string;
    input: {
        workflow_run_id?: number;
        invoice_version_id?: number;
        description?: string;
    };
};
type PolicyTemplate = {
    key: string;
    name: string;
    badge: string;
    tagline: string;
    icon: string;
    description: string;
    recommended_for: string;
    controls: Controls;
    highlights: string[];
    categories_count: number;
    examples_count: number;
    active?: boolean;
    content?: Content;
};

type SimulationResult = {
    status: 'match' | 'needs_review';
    category_name: string;
    category_id: string;
    confidence: number;
    explanation: string;
    citations: { clause_id: string; quote: string }[];
    decision_steps: string[];
};

type Props = {
    institution: { id: number; name: string };
    versions: Version[];
    current: {
        institution_policy_version_id: number;
        publication_digest: string;
        approved_by: number;
        controls_active: boolean;
    } | null;
    assessments: Assessment[];
    planAdvice: Assessment[];
    canPublish: boolean;
    aiEnabled: boolean;
    financeUrl: string;
    queueReady: boolean;
    templates?: PolicyTemplate[];
    health?: JevAiHealth | null;
};

const sections = [
    { id: 'playbook', label: 'Simple Rulebook', icon: Wand2, primary: true },
    {
        id: 'simulator',
        label: 'Test a Sample Bill',
        icon: FlaskConical,
        primary: true,
    },
    {
        id: 'policy',
        label: 'Policy document',
        icon: BookOpenText,
        primary: false,
    },
    { id: 'categories', label: 'Categories', icon: ListFilter, primary: false },
    { id: 'examples', label: 'Examples', icon: FileText, primary: false },
    {
        id: 'controls',
        label: 'Financial controls',
        icon: SlidersHorizontal,
        primary: false,
    },
    {
        id: 'playground',
        label: 'Playground',
        icon: FlaskConical,
        primary: false,
    },
    {
        id: 'publication',
        label: 'Review & publish',
        icon: ShieldCheck,
        primary: false,
    },
] as const;
type Section = (typeof sections)[number]['id'];

const controlLabels: Record<keyof Controls, string> = {
    minimum_reserve: 'Protected reserve',
    max_auto_payment: 'Per automatic payment',
    max_daily_disbursement: 'Daily disbursement ceiling',
    max_fee: 'Maximum fee',
};

type GuidedCategoryPreset = {
    id: string;
    name: string;
    emoji: string;
    plainLabel: string;
    badge: string;
    description: string;
    inclusions: string;
    exclusions: string;
    clause: Clause;
    example: string;
    core?: boolean;
};

const GUIDED_CATEGORY_CATALOG: GuidedCategoryPreset[] = [
    {
        id: 'tuition-receipts',
        name: 'Tuition & Academic Fees',
        emoji: '🎓',
        plainLabel: 'Student tuition & enrollment payments',
        badge: 'Money In',
        core: true,
        description:
            'Student tuition, semester enrollment fees, lab fees, and class charges.',
        inclusions:
            'Tuition downpayments, semester fees, laboratory course charges, official exam fees',
        exclusions:
            'External vendor disbursements, unverified cash deposits, personal transfers',
        clause: {
            id: 'obligations',
            title: 'Core Institutional Obligations & Revenue',
            text: 'Money received as tuition or institutional fees is held as a receipt. Approved supplier invoices and recurring campus obligations are paid only from available funds above the minimum reserve.',
        },
        example:
            'Student tuition payment of 400 USDC for Fall semester enrollment',
    },
    {
        id: 'supplier-invoices',
        name: 'Approved Supplier Invoices',
        emoji: '🧾',
        plainLabel: 'Electric, water, internet & regular vendors',
        badge: 'Bills Out',
        core: true,
        description:
            'Verified vendor bills, campus utilities, internet, and contracted services.',
        inclusions:
            'Electricity, water, internet, facility repairs, contracted vendor invoices, lab consumables',
        exclusions:
            'Unapproved personal reimbursements, speculative purchases, unverified wallet addresses',
        clause: {
            id: 'obligations',
            title: 'Core Institutional Obligations & Revenue',
            text: 'Money received as tuition or institutional fees is held as a receipt. Approved supplier invoices and recurring campus obligations are paid only from available funds above the minimum reserve.',
        },
        example:
            'Monthly campus electricity and fiber internet utility invoice',
    },
    {
        id: 'budget-operations',
        name: 'Academic & General Operations',
        emoji: '📚',
        plainLabel: 'Classroom materials, printing & office supplies',
        badge: 'Everyday',
        core: true,
        description:
            'Routine department needs, teaching supplies, printing, and office items.',
        inclusions:
            'Classroom materials, teaching supplies, printing, routine operational services',
        exclusions: 'Major capital construction, unapproved travel',
        clause: {
            id: 'budget-governance',
            title: 'Departmental Budget & Allocation Governance',
            text: 'Departmental budgets for operations, technology, student assistance, and equipment must be classified against approved budget lines and remain within available cash headroom.',
        },
        example:
            'Departmental classroom teaching supplies and academic operations allocation',
    },
    {
        id: 'budget-technology',
        name: 'IT & Campus Technology',
        emoji: '💻',
        plainLabel: 'Campus Wi-Fi, learning software & cloud hosting',
        badge: 'Tech',
        core: true,
        description:
            'School software subscriptions, cloud servers, Wi-Fi gear, and learning apps.',
        inclusions:
            'LMS subscriptions, cloud compute hosting, campus Wi-Fi hardware, cybersecurity tools',
        exclusions:
            'Consumer gaming hardware, unapproved personal software subscriptions',
        clause: {
            id: 'budget-governance',
            title: 'Departmental Budget & Allocation Governance',
            text: 'Departmental budgets for operations, technology, student assistance, and equipment must be classified against approved budget lines and remain within available cash headroom.',
        },
        example:
            'Annual campus LMS software license and cloud server hosting allocation',
    },
    {
        id: 'budget-assistance',
        name: 'Student Financial Aid & Stipends',
        emoji: '🤝',
        plainLabel: 'Scholarships, emergency grants & student stipends',
        badge: 'Student Aid',
        core: true,
        description:
            'Approved scholarships, emergency student hardship aid, and research stipends.',
        inclusions:
            'Approved tuition waivers, emergency student hardship grants, academic stipends',
        exclusions:
            'Unverified cash gifts, loans without financial aid committee sign-off',
        clause: {
            id: 'budget-governance',
            title: 'Departmental Budget & Allocation Governance',
            text: 'Departmental budgets for operations, technology, student assistance, and equipment must be classified against approved budget lines and remain within available cash headroom.',
        },
        example:
            'Approved student emergency financial assistance and scholarship stipend allocation',
    },
    {
        id: 'budget-equipment',
        name: 'STEM Lab & Capital Equipment',
        emoji: '🔬',
        plainLabel: 'Science lab glassware, pipettes & instruments',
        badge: 'Labs',
        core: true,
        description:
            'Science lab supplies, microscopes, sensors, and approved research gear.',
        inclusions:
            'Lab consumables, pipettes, microscopes, sensors, approved departmental equipment',
        exclusions:
            'Uncited luxury electronics, personal devices without asset tags',
        clause: {
            id: 'stem-labs',
            title: 'STEM Laboratory & Research Procurement',
            text: 'Routine laboratory consumables and approved scientific equipment are eligible for streamlined classification when supported by a departmental requisition.',
        },
        example:
            'Sterile pipettes, glassware, and microscope slides for STEM laboratory courses',
    },
    {
        id: 'student-activities',
        name: 'Campus Events & Student Clubs',
        emoji: '🎉',
        plainLabel: 'School festivals, sound rental & student clubs',
        badge: 'Events',
        core: false,
        description:
            'Student organization events, cultural festivals, catering, and guest speakers.',
        inclusions:
            'Stage audio rental, event catering, guest speaker honoraria, club workshop materials',
        exclusions:
            'Private off-campus parties, alcohol, unapproved cash prizes',
        clause: {
            id: 'events',
            title: 'Student Activities & Campus Events',
            text: 'Registered student organizations and campus festival committees may procure audio-visual staging, catering, and event logistics within approved event budgets.',
        },
        example:
            'Stage audio and lighting vendor invoice for annual campus cultural festival',
    },
    {
        id: 'department-facilities',
        name: 'Facilities & Campus Upkeep',
        emoji: '🛠️',
        plainLabel: 'Building repairs, AC servicing & campus safety',
        badge: 'Facilities',
        core: false,
        description:
            'Building maintenance, air conditioning, plumbing, cleaning, and safety repairs.',
        inclusions:
            'HVAC repair, janitorial services, electrical safety maintenance, plumbing repairs',
        exclusions:
            'Major real estate acquisition, unapproved architectural remodels',
        clause: {
            id: 'facilities',
            title: 'Campus Facilities & Safety Maintenance',
            text: 'Essential facility upkeep, sanitation, and safety repairs are prioritized to maintain safe campus operations.',
        },
        example:
            'Emergency HVAC servicing and electrical safety inspection for science building',
    },
    {
        id: 'other-fee-receipts',
        name: 'Miscellaneous Student Receipts',
        emoji: '🪪',
        plainLabel: 'Library fines, ID cards & transcript fees',
        badge: 'Money In',
        core: false,
        description:
            'Library fees, transcript requests, graduation fees, and ID replacements.',
        inclusions:
            'Library fees, transcript processing, ID replacement, hostel fees, parking permits',
        exclusions: 'Core tuition installments, supplier invoices',
        clause: {
            id: 'obligations',
            title: 'Core Institutional Obligations & Revenue',
            text: 'Money received as tuition or institutional fees is held as a receipt. Approved supplier invoices and recurring campus obligations are paid only from available funds above the minimum reserve.',
        },
        example:
            'Official transcript fee and replacement student ID card receipt',
    },
];

const SIMPLE_SAFETY_PRESETS = [
    {
        id: 'balanced',
        name: 'Balanced Everyday Mode',
        tag: 'Recommended for most schools',
        summary:
            'Keeps $500 safe in reserve and auto-approves routine bills up to $250.',
        controls: {
            minimum_reserve: '500',
            max_auto_payment: '250',
            max_daily_disbursement: '1000',
            max_fee: '5',
        },
        priorities:
            '1. Maintain minimum treasury reserve at all times.\n2. Prioritize core utilities, payroll-linked obligations, and student financial assistance.\n3. Fund STEM laboratory consumables and IT infrastructure before discretionary events.',
    },
    {
        id: 'strict',
        name: 'Careful / Human-First Mode',
        tag: 'Extra cautious',
        summary:
            'Keeps $1,500 untouched in reserve and asks a human for anything over $100.',
        controls: {
            minimum_reserve: '1500',
            max_auto_payment: '100',
            max_daily_disbursement: '500',
            max_fee: '2',
        },
        priorities:
            '1. Enforce strict capital preservation with a 1,500 USDC minimum reserve.\n2. Require human bursar sign-off on any non-routine or high-value disbursement.\n3. Prioritize essential campus safety and utilities first.',
    },
    {
        id: 'autonomous',
        name: 'High-Speed Auto Mode',
        tag: 'For busy campuses',
        summary:
            'Allows verified supplier bills up to $1,000 and up to $5,000 per day.',
        controls: {
            minimum_reserve: '250',
            max_auto_payment: '1000',
            max_daily_disbursement: '5000',
            max_fee: '10',
        },
        priorities:
            '1. Maximize autonomous settlement throughput for verified supplier invoices and approved departmental budgets.\n2. Preserve emergency reserve floor.\n3. Hold only ambiguous or unverified counterparties for human review.',
    },
] as const;

function blank(): Content {
    return {
        title: '',
        clauses: [{ id: 'policy-1', title: '', text: '' }],
        categories: [],
        examples: [],
        priorities: '',
        clarifications: [],
        controls: {
            minimum_reserve: '0',
            max_auto_payment: '0',
            max_daily_disbursement: '0',
            max_fee: '0',
        },
    };
}

function Field({
    label,
    htmlFor,
    hint,
    children,
}: {
    label: string;
    htmlFor: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <Label
                htmlFor={htmlFor}
                className="text-sm font-medium text-stone-800 dark:text-stone-200"
            >
                {label}
            </Label>
            {children}
            {hint && (
                <p className="text-xs text-stone-500 dark:text-stone-400">
                    {hint}
                </p>
            )}
        </div>
    );
}

function PlaybookCard({
    stepBadge,
    title,
    subtitle,
    action,
    children,
}: {
    stepBadge?: string;
    title: string;
    subtitle: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-3xl border border-[#E5DEC9] bg-white p-5 shadow-[0_8px_30px_rgb(28,25,23,0.04)] sm:p-7 dark:border-[#2C261F] dark:bg-[#181512]">
            <div className="flex flex-wrap items-start justify-between gap-4 border-b border-[#EFEAE0] pb-5 dark:border-[#26211B]">
                <div className="space-y-1">
                    {stepBadge && (
                        <span className="inline-flex items-center rounded-full bg-[#F4EFE6] px-3 py-1 text-[11px] font-semibold tracking-wide text-[#7C6846] uppercase dark:bg-[#28221A] dark:text-[#D8C39E]">
                            {stepBadge}
                        </span>
                    )}
                    <h2 className="text-lg font-bold tracking-tight text-stone-900 sm:text-xl dark:text-stone-100">
                        {title}
                    </h2>
                    <p className="max-w-2xl text-sm text-stone-600 dark:text-stone-400">
                        {subtitle}
                    </p>
                </div>
                {action && (
                    <div className="flex flex-wrap items-center gap-2">
                        {action}
                    </div>
                )}
            </div>
            <div className="mt-6 flex min-w-0 flex-col gap-6">{children}</div>
        </section>
    );
}

function JudgmentView({ item }: { item: Assessment }) {
    if (item.state === 'queued' || item.state === 'running') {
        return (
            <div role="status" className="flex flex-col gap-3">
                <p className="text-sm">
                    Assessment {item.state}. Background worker required.
                </p>
                <Skeleton className="h-5 w-2/3" />
                <Skeleton className="h-16 w-full" />
            </div>
        );
    }
    if (!item.result) {
        return (
            <Alert variant="warning">
                <AlertTitle>Needs review</AlertTitle>
                <AlertDescription>
                    {item.last_error ?? 'No interpretation recorded.'}
                </AlertDescription>
            </Alert>
        );
    }
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    variant={
                        item.result.needs_review ||
                        (item.kind === 'examples' && !item.result.all_passed)
                            ? 'warning-light'
                            : 'success-light'
                    }
                >
                    {item.kind === 'examples'
                        ? item.result.all_passed
                            ? 'Examples passed'
                            : 'Example mismatches'
                        : (item.result.category_id ??
                          (item.kind === 'authoring'
                              ? 'Policy interpretation'
                              : 'Needs review'))}
                </Badge>
                <Badge variant="outline">Advisory only</Badge>
            </div>
            {item.result.explanation && (
                <p className="text-sm">{item.result.explanation}</p>
            )}
            {item.result.citations?.map((citation, index) => (
                <blockquote
                    key={index}
                    className="flex flex-col gap-1 border-l-2 border-border pl-4 text-sm"
                >
                    <span className="text-xs font-medium text-muted-foreground">
                        {citation.clause_id}
                    </span>
                    <span>“{citation.quote}”</span>
                </blockquote>
            ))}
            {(item.result.questions?.length ?? 0) > 0 && (
                <Alert variant="warning">
                    <AlertTitle>Clarify before publishing</AlertTitle>
                    <AlertDescription>
                        <ul className="list-inside list-disc">
                            {item.result.questions?.map((question) => (
                                <li key={question}>{question}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
            {(item.result.missing_evidence?.length ?? 0) > 0 && (
                <p className="text-sm">
                    Missing evidence: {item.result.missing_evidence?.join('; ')}
                </p>
            )}
            {item.result.examples?.map((example) => (
                <div
                    key={example.index}
                    className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3 text-sm"
                >
                    <span>
                        Example {example.index + 1}: expected {example.expected}
                        , got {example.actual}
                    </span>
                    <Badge
                        variant={
                            example.passed ? 'success-light' : 'warning-light'
                        }
                    >
                        {example.passed ? 'Match' : 'Review'}
                    </Badge>
                </div>
            ))}
            <p className="text-xs text-muted-foreground">
                Assessment #{item.id} ·{' '}
                {item.provider ?? 'Provider not recorded'} ·{' '}
                {item.model ?? 'Model not recorded'}. No payment authority.
            </p>
        </div>
    );
}

export default function InstitutionPolicies({
    institution,
    versions,
    current,
    assessments,
    planAdvice,
    canPublish,
    aiEnabled,
    financeUrl,
    queueReady,
    templates = [],
    health,
}: Props) {
    const user = usePage<{ auth: { user: { id: number } } }>().props.auth.user;
    const [selected, setSelected] = useState<Version | null>(
        versions[0] ?? null,
    );
    const [content, setContent] = useState<Content>(
        versions[0]?.content ?? blank(),
    );
    const [section, setSection] = useState<Section>('playbook');
    const [dirty, setDirty] = useState(false);
    const [notice, setNotice] = useState('');
    const [noticeTitle, setNoticeTitle] = useState('Saved');
    const [noticeVariant, setNoticeVariant] = useState<
        'success' | 'destructive'
    >('success');
    const [description, setDescription] = useState('');
    const [reason, setReason] = useState('');
    const [attested, setAttested] = useState(false);
    const [pendingVersion, setPendingVersion] = useState<string | null>(null);
    const [localAssessment, setLocalAssessment] = useState<Assessment | null>(
        null,
    );
    const [applyingTemplate, setApplyingTemplate] = useState<string | null>(
        null,
    );
    const [quickActivating, setQuickActivating] = useState(false);
    const [customCatName, setCustomCatName] = useState('');
    const [customCatInclusions, setCustomCatInclusions] = useState('');
    const [simDescription, setSimDescription] = useState(
        'Disposable sterile pipettes and petri dishes for undergraduate biology lab class experiments',
    );
    const [simulating, setSimulating] = useState(false);
    const [simResult, setSimResult] = useState<SimulationResult | null>(null);
    const [simError, setSimError] = useState<string | null>(null);

    const simulatorPresets = [
        {
            label: '🎓 Student Tuition ($250)',
            desc: 'Student tuition fee downpayment of 250 USDC for Fall 2026 semester enrollment',
        },
        {
            label: '🔬 Biology Lab Supplies',
            desc: 'Disposable sterile pipettes, reagent tubes, and petri dishes for biology class experiments',
        },
        {
            label: '🎉 Campus Festival Sound',
            desc: 'Audio equipment and stage lighting vendor rental invoice for annual campus festival',
        },
        {
            label: '⚡ Electric & Internet Bill',
            desc: 'Monthly campus grid electricity and high-speed broadband utility invoice',
        },
        {
            label: '💻 3x AI Server Racks',
            desc: 'Procurement of 3x rackmount GPU deep learning compute nodes for computer science research',
        },
        {
            label: '🚫 Unapproved Luxury Gift',
            desc: 'Luxury staff gifts and gold watches without departmental invoice citations or purchase order',
        },
    ];

    const saveHttp = useHttp<Record<string, never>, { data: Version }>({});
    const assessmentHttp = useHttp<Record<string, never>, { data: Assessment }>(
        {},
    );
    const publishHttp = useHttp<Record<string, never>, { data: unknown }>({});
    const documentHttp = useHttp<
        { document: File | null },
        { data: { text: string; name: string; digest: string } }
    >({ document: null });

    usePoll(3000, {
        only: ['assessments', 'current', 'versions', 'planAdvice'],
    });

    const latest = (kind: string) =>
        assessments.find(
            (item) =>
                item.institution_policy_version_id === selected?.id &&
                item.kind === kind,
        ) ?? (localAssessment?.kind === kind ? localAssessment : null);

    const edit = <K extends keyof Content>(key: K, value: Content[K]) => {
        setContent((old) => ({ ...old, [key]: value }));
        setDirty(true);
        setNotice('');
    };

    const canAssess = !!selected && !dirty && queueReady;
    const activeVersion = versions.find(
        (version) => version.id === current?.institution_policy_version_id,
    );
    const comparison =
        activeVersion?.id !== selected?.id
            ? activeVersion
            : versions.find(
                  (version) => version.id === selected?.content.predecessor_id,
              );
    const changedClauses = content.clauses.filter(
        (clause) =>
            comparison?.content.clauses.find((item) => item.id === clause.id)
                ?.text !== clause.text,
    );
    const errors = [
        ...Object.values(saveHttp.errors),
        ...Object.values(assessmentHttp.errors),
        ...Object.values(publishHttp.errors),
        ...Object.values(documentHttp.errors),
    ]
        .flat()
        .filter(Boolean);

    function applySafetyPreset(preset: (typeof SIMPLE_SAFETY_PRESETS)[number]) {
        setContent((old) => ({
            ...old,
            title: old.title.trim()
                ? old.title
                : `${institution.name} — ${preset.name}`,
            controls: { ...preset.controls },
            priorities: preset.priorities,
        }));
        setDirty(true);
        setNoticeVariant('success');
        setNoticeTitle('Safety Limits Updated');
        setNotice(
            `Applied "${preset.name}" wallet limits. Click "Save & Turn On Rules" whenever you're ready.`,
        );
    }

    function toggleGuidedCategory(preset: GuidedCategoryPreset) {
        setContent((old) => {
            const exists = old.categories.some((c) => c.id === preset.id);
            if (exists) {
                const nextCategories = old.categories.filter(
                    (c) => c.id !== preset.id,
                );
                const nextExamples = old.examples.filter(
                    (e) => e.category_id !== preset.id,
                );
                const stillUsesClause = nextCategories.some((c) =>
                    c.clause_ids.includes(preset.clause.id),
                );
                const filteredClauses = stillUsesClause
                    ? old.clauses
                    : old.clauses.filter((c) => c.id !== preset.clause.id);

                return {
                    ...old,
                    clauses:
                        filteredClauses.length > 0
                            ? filteredClauses
                            : [{ id: 'policy-1', title: '', text: '' }],
                    categories: nextCategories,
                    examples: nextExamples,
                };
            }

            const cleanedClauses = old.clauses.filter(
                (c) => c.title.trim() !== '' || c.text.trim() !== '',
            );
            const hasClause = cleanedClauses.some(
                (c) => c.id === preset.clause.id,
            );
            const nextClauses = hasClause
                ? cleanedClauses
                : [...cleanedClauses, { ...preset.clause }];

            const nextCategories: Category[] = [
                ...old.categories,
                {
                    id: preset.id,
                    name: preset.name,
                    description: preset.description,
                    inclusions: preset.inclusions,
                    exclusions: preset.exclusions,
                    clause_ids: [preset.clause.id],
                },
            ];

            const hasExample = old.examples.some(
                (e) => e.category_id === preset.id,
            );
            const nextExamples: Example[] = hasExample
                ? old.examples
                : [
                      ...old.examples,
                      {
                          description: preset.example,
                          category_id: preset.id,
                      },
                  ];

            return {
                ...old,
                title: old.title.trim()
                    ? old.title
                    : `${institution.name} — School Spending Policy`,
                clauses:
                    nextClauses.length > 0
                        ? nextClauses
                        : [{ ...preset.clause }],
                categories: nextCategories,
                examples: nextExamples,
                priorities: old.priorities.trim()
                    ? old.priorities
                    : SIMPLE_SAFETY_PRESETS[0].priorities,
            };
        });
        setDirty(true);
        setNotice('');
    }

    function enableCategoryBundle(mode: 'all' | 'core') {
        const selectedPresets = GUIDED_CATEGORY_CATALOG.filter((p) =>
            mode === 'all' ? true : p.core,
        );
        const clauseMap = new Map<string, Clause>();
        for (const preset of selectedPresets) {
            clauseMap.set(preset.clause.id, { ...preset.clause });
        }
        const categories: Category[] = selectedPresets.map((p) => ({
            id: p.id,
            name: p.name,
            description: p.description,
            inclusions: p.inclusions,
            exclusions: p.exclusions,
            clause_ids: [p.clause.id],
        }));
        const examples: Example[] = [
            ...selectedPresets.map((p) => ({
                description: p.example,
                category_id: p.id,
            })),
            {
                description:
                    'Uncited personal luxury gift with no departmental invoice or contract',
                category_id: 'needs_review',
            },
        ];

        setContent((old) => ({
            ...old,
            title: old.title.trim()
                ? old.title
                : `${institution.name} — School Spending Policy`,
            clauses: Array.from(clauseMap.values()),
            categories,
            examples,
            controls:
                old.controls.minimum_reserve === '0' &&
                old.controls.max_auto_payment === '0'
                    ? { ...SIMPLE_SAFETY_PRESETS[0].controls }
                    : old.controls,
            priorities: old.priorities.trim()
                ? old.priorities
                : SIMPLE_SAFETY_PRESETS[0].priorities,
        }));
        setDirty(true);
        setNoticeVariant('success');
        setNoticeTitle('Spending Categories Updated');
        setNotice(
            `Turned on ${selectedPresets.length} spending categories and automatically set up their matching rules.`,
        );
    }

    function handleAddCustomGuidedCategory() {
        const name = customCatName.trim();
        if (!name) return;
        let baseSlug =
            name
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 40) || `custom-${content.categories.length + 1}`;
        if (
            baseSlug === 'needs_review' ||
            baseSlug === 'needs-review' ||
            GUIDED_CATEGORY_CATALOG.some((p) => p.id === baseSlug)
        ) {
            baseSlug = `${baseSlug}-custom`;
        }
        const slug = baseSlug;
        const inclusions =
            customCatInclusions.trim() ||
            `${name} approved school expenses and receipts`;
        const exclusions = 'Uncited personal or non-school items';
        const clauseId = `clause-${slug}`.slice(0, 48);
        const clauseText = `Transactions for ${name} (${inclusions}) are authorized under school policy when supported by verifiable documentation. Excludes: ${exclusions}.`;

        setContent((old) => {
            const cleanedClauses = old.clauses.filter(
                (c) => c.title.trim() !== '' || c.text.trim() !== '',
            );
            const hasClause = cleanedClauses.some((c) => c.id === clauseId);
            const nextClauses = hasClause
                ? cleanedClauses.map((c) =>
                      c.id === clauseId
                          ? {
                                id: clauseId,
                                title: `${name} Rule`,
                                text: clauseText,
                            }
                          : c,
                  )
                : [
                      ...cleanedClauses,
                      {
                          id: clauseId,
                          title: `${name} Rule`,
                          text: clauseText,
                      },
                  ];
            const nextCategories = [
                ...old.categories.filter((c) => c.id !== slug),
                {
                    id: slug,
                    name,
                    description: `School spending category for ${name}.`,
                    inclusions,
                    exclusions,
                    clause_ids: [clauseId],
                },
            ];
            const nextExamples = [
                ...old.examples.filter((e) => e.category_id !== slug),
                {
                    description: `${name}: ${inclusions}`,
                    category_id: slug,
                },
            ];
            return {
                ...old,
                title: old.title.trim()
                    ? old.title
                    : `${institution.name} — School Spending Policy`,
                clauses: nextClauses,
                categories: nextCategories,
                examples: nextExamples,
                priorities: old.priorities.trim()
                    ? old.priorities
                    : SIMPLE_SAFETY_PRESETS[0].priorities,
            };
        });
        setCustomCatName('');
        setCustomCatInclusions('');
        setDirty(true);
        setNoticeVariant('success');
        setNoticeTitle('Custom Category Added');
        setNotice(`Added "${name}" to your allowed spending categories.`);
    }

    function generateExamplesFromCategories() {
        if (content.categories.length === 0) return;
        const generated: Example[] = content.categories.map((cat) => {
            const preset = GUIDED_CATEGORY_CATALOG.find((p) => p.id === cat.id);
            return {
                description:
                    preset?.example ||
                    `${cat.name || cat.id}: ${cat.inclusions || cat.description || 'Verified institutional transaction'}`,
                category_id: cat.id,
            };
        });
        generated.push({
            description:
                'Unapproved personal gift without invoice or departmental authorization',
            category_id: 'needs_review',
        });
        edit('examples', generated);
        setNoticeVariant('success');
        setNoticeTitle('Examples Generated');
        setNotice(
            `Generated ${generated.length} test examples for your categories.`,
        );
    }

    async function handleQuickActivate(autoPublish = true) {
        setQuickActivating(true);
        try {
            // If user hasn't picked categories yet, default to the 6 core school categories so it never fails
            let payloadContent = { ...content };
            if (!payloadContent.title.trim()) {
                payloadContent.title = `${institution.name} — School Spending Policy`;
            }
            if (payloadContent.categories.length === 0) {
                const corePresets = GUIDED_CATEGORY_CATALOG.filter(
                    (p) => p.core,
                );
                const clauseMap = new Map<string, Clause>();
                const existingFirst = payloadContent.clauses[0];
                if (
                    existingFirst &&
                    (existingFirst.title.trim() !== '' ||
                        existingFirst.text.trim() !== '')
                ) {
                    const customId = existingFirst.id.trim() || 'policy-1';
                    clauseMap.set(customId, {
                        id: customId,
                        title:
                            existingFirst.title.trim() ||
                            'Core Institutional Obligations & Revenue',
                        text:
                            existingFirst.text.trim() ||
                            corePresets[0].clause.text,
                    });
                }
                for (const p of corePresets) {
                    if (!clauseMap.has(p.clause.id)) {
                        clauseMap.set(p.clause.id, { ...p.clause });
                    }
                }
                payloadContent = {
                    ...payloadContent,
                    clauses: Array.from(clauseMap.values()),
                    categories: corePresets.map((p) => ({
                        id: p.id,
                        name: p.name,
                        description: p.description,
                        inclusions: p.inclusions,
                        exclusions: p.exclusions,
                        clause_ids: [p.clause.id],
                    })),
                    examples: corePresets.map((p) => ({
                        description: p.example,
                        category_id: p.id,
                    })),
                };
            }

            const res = await fetch('/finance/policies/quick-activate', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    ...payloadContent,
                    request_key: crypto.randomUUID(),
                    auto_publish: autoPublish,
                    reason:
                        reason.trim() ||
                        `Saved and activated "${payloadContent.title}" via Simple Rulebook`,
                }),
            });
            const data = await res.json();
            if (res.ok && data.status === 'success') {
                if (data.version) {
                    setSelected(data.version);
                    setContent(data.version.content);
                }
                setDirty(false);
                setNoticeVariant('success');
                setNoticeTitle(
                    autoPublish ? 'Rules Saved & Turned On' : 'Draft Saved',
                );
                setNotice(
                    data.message ||
                        'Your school spending rules are now active.',
                );
                router.reload({ only: ['versions', 'current', 'assessments'] });
            } else {
                const firstErr =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : null;
                setNoticeVariant('destructive');
                setNoticeTitle('Please Check Your Rules');
                setNotice(
                    String(
                        firstErr ||
                            data.message ||
                            'Please check your policy details and try again.',
                    ),
                );
            }
        } catch {
            setNoticeVariant('destructive');
            setNoticeTitle('Connection Error');
            setNotice('Could not reach the server while saving your rules.');
        } finally {
            setQuickActivating(false);
        }
    }

    async function handleApplyTemplate(templateKey: string) {
        setApplyingTemplate(templateKey);
        try {
            const res = await fetch('/finance/policies/apply-template', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    template_key: templateKey,
                    auto_publish: canPublish,
                    reason: 'Activated ready-made school preset',
                }),
            });
            const data = await res.json();
            if (res.ok && data.status === 'success') {
                setNoticeVariant('success');
                setNoticeTitle('Preset Turned On!');
                setNotice(
                    data.message ||
                        'Ready-made school spending policy is now active.',
                );
                router.reload();
            } else {
                setNoticeVariant('destructive');
                setNoticeTitle('Could Not Apply Preset');
                setNotice(data.message || 'Could not apply this preset.');
            }
        } catch {
            setNoticeVariant('destructive');
            setNoticeTitle('Connection Error');
            setNotice('Network error while applying preset.');
        } finally {
            setApplyingTemplate(null);
        }
    }

    function handleCustomizeTemplate(tpl: PolicyTemplate) {
        if (tpl.content) {
            setContent(tpl.content);
            setSelected(null);
            setDirty(true);
            setSection('playbook');
            setNoticeVariant('success');
            setNoticeTitle('Preset Loaded for Editing');
            setNotice(
                `Loaded "${tpl.name}" below. Toggle any categories or adjust your USDC limits, then click "Save & Turn On Rules".`,
            );
        }
    }

    async function handleRunSimulation(descToRun?: string) {
        const text = descToRun ?? simDescription;
        if (!text.trim()) return;
        if (descToRun) {
            setSimDescription(descToRun);
        }
        setSimulating(true);
        setSimError(null);
        try {
            const res = await fetch('/finance/policies/simulate', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    description: text,
                    version_id:
                        selected?.id ??
                        current?.institution_policy_version_id ??
                        null,
                    draft_content:
                        (dirty || !selected) && content.categories.length > 0
                            ? {
                                  title:
                                      content.title.trim() ||
                                      `${institution.name} — School Spending Policy`,
                                  categories: content.categories,
                                  clauses: content.clauses,
                              }
                            : undefined,
                }),
            });
            const json = await res.json();
            if (res.ok && json.data) {
                setSimResult(json.data);
            } else {
                setSimError(
                    json.message || 'Could not test this bill right now.',
                );
            }
        } catch {
            setSimError('Could not connect to the policy simulator.');
        } finally {
            setSimulating(false);
        }
    }

    async function importPolicy() {
        try {
            const response = await documentHttp.post(importDocument.url());
            edit('clauses', [
                ...content.clauses.filter((clause) => clause.text.trim()),
                ...(response.data.text.match(/[\s\S]{1,3500}/g) ?? []).map(
                    (text, index) => ({
                        id: `source-${content.clauses.length + index + 1}`,
                        title: response.data.name,
                        text,
                    }),
                ),
            ]);
            setNotice(
                'Document text imported locally. Review extracted wording and split long text into cited clauses before saving.',
            );
        } catch {
            /* Uploaded document errors stay inline. */
        }
    }

    async function saveRevision() {
        saveHttp.transform(() => ({
            ...content,
            request_key: crypto.randomUUID(),
            predecessor_id: selected?.id ?? null,
            expected_current_digest: current?.publication_digest ?? null,
        }));
        try {
            const response = await saveHttp.post(store.url());
            setSelected(response.data);
            setContent(response.data.content);
            setDirty(false);
            setNoticeVariant('success');
            setNoticeTitle('Saved');
            setNotice(
                'Revision saved. Interpret policy and test examples before independent publication.',
            );
            router.reload({ only: ['versions'] });
        } catch {
            /* Field errors remain visible. */
        }
    }

    async function run(kind: string) {
        if (!selected || dirty) return;
        assessmentHttp.transform(() => ({
            kind,
            request_key: crypto.randomUUID(),
            expected_digest: selected.content_digest,
            description,
        }));
        try {
            const response = await assessmentHttp.post(assess.url(selected.id));
            setLocalAssessment(response.data);
            router.reload({ only: ['assessments'] });
        } catch {
            /* Inline errors own failed request. */
        }
    }

    async function publishRevision() {
        if (!selected || dirty) return;
        publishHttp.transform(() => ({
            expected_digest: selected.content_digest,
            expected_current_digest: current?.publication_digest ?? null,
            reason,
            attestation: attested,
        }));
        try {
            await publishHttp.post(publish.url(selected.id));
            setNoticeVariant('success');
            setNoticeTitle('Published');
            setNotice(
                'Policy published by independent reviewer. No payment approved.',
            );
            router.reload({ only: ['versions', 'current'] });
        } catch {
            /* Inline errors own failed request. */
        }
    }

    function chooseVersion(value: string) {
        if (dirty) {
            setPendingVersion(value);
            return;
        }
        openVersion(value);
    }

    function openVersion(value: string) {
        if (value === 'new') {
            setContent(blank());
            setSelected(null);
            setDirty(false);
            return;
        }
        const version = versions.find((item) => String(item.id) === value);
        if (version) {
            setSelected(version);
            setContent(version.content);
            setDirty(false);
            setLocalAssessment(null);
        }
    }

    const firstClause = content.clauses[0] ?? {
        id: 'policy-1',
        title: '',
        text: '',
    };

    return (
        <>
            <Head title="Institution policies" />
            <div className="min-h-screen overflow-x-hidden bg-background text-foreground">
                <a
                    href="#policy-workspace"
                    className="sr-only focus:not-sr-only"
                >
                    Skip to policy workspace
                </a>

                {/* Warm Editorial Top Bar */}
                <header className="sticky top-0 z-30 border-b border-border bg-background/90 backdrop-blur-md">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3.5 sm:px-6">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <Button
                                variant="ghost"
                                size="sm"
                                asChild
                                className="rounded-full text-stone-700 dark:text-stone-300"
                            >
                                <a href={financeUrl}>
                                    <ArrowLeft className="size-4" />
                                    Back to Finance
                                </a>
                            </Button>
                            <span className="text-stone-300 dark:text-stone-700">
                                |
                            </span>
                            <span className="text-sm font-semibold text-stone-800 dark:text-stone-200">
                                {institution.name}
                            </span>
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:text-emerald-300">
                                <span
                                    className={`size-2 rounded-full ${
                                        current?.controls_active
                                            ? 'bg-emerald-500'
                                            : 'bg-amber-500'
                                    }`}
                                />
                                {current
                                    ? current.controls_active
                                        ? 'Active Policy Protecting Wallet'
                                        : 'Published · Controls Superseded'
                                    : 'No Active Policy Yet'}
                            </span>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {versions.length > 0 && (
                                <Select
                                    value={selected ? String(selected.id) : ''}
                                    onValueChange={chooseVersion}
                                >
                                    <SelectTrigger
                                        id="version"
                                        aria-label="Saved revision"
                                        className="h-8 w-[190px] rounded-full border-[#DDD5C7] bg-white text-xs dark:border-[#302A22] dark:bg-[#1A1714]"
                                    >
                                        <SelectValue placeholder="Switch version" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {versions.map((version) => (
                                            <SelectItem
                                                key={version.id}
                                                value={String(version.id)}
                                            >
                                                Revision #{version.id}:{' '}
                                                {version.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                className="rounded-full border-[#DDD5C7] bg-white text-xs dark:border-[#302A22] dark:bg-[#1A1714]"
                                onClick={() => chooseVersion('new')}
                            >
                                <Plus className="size-3.5" />
                                New policy
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-full border-[#DDD5C7] bg-white text-xs dark:border-[#302A22] dark:bg-[#1A1714]"
                                disabled={saveHttp.processing}
                                onClick={saveRevision}
                            >
                                <Save className="size-3.5" />
                                {saveHttp.processing
                                    ? 'Saving…'
                                    : 'Save revision'}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={quickActivating}
                                onClick={() => handleQuickActivate(canPublish)}
                                className="rounded-full bg-emerald-600 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 dark:bg-emerald-500 dark:text-stone-950 dark:hover:bg-emerald-400"
                            >
                                <Zap className="size-3.5" />
                                {quickActivating
                                    ? 'Activating…'
                                    : canPublish
                                      ? 'Save & Turn On Rules'
                                      : 'Save Guided Draft'}
                            </Button>
                        </div>
                    </div>
                </header>

                <main
                    id="policy-workspace"
                    className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8"
                >
                    {/* Friendly Plain-English Hero Summary Card */}
                    <div className="relative overflow-hidden rounded-3xl border border-[#E2D9C5] bg-gradient-to-br from-[#FFFDF9] via-[#F9F4EA] to-[#F1E9D8] p-6 shadow-sm sm:p-8 dark:border-[#2E2820] dark:from-[#1B1814] dark:via-[#161310] dark:to-[#1F1A14]">
                        <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-center">
                            <div className="max-w-2xl space-y-2">
                                <div className="inline-flex items-center gap-2 rounded-full bg-emerald-600/10 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-400/10 dark:text-emerald-300">
                                    <ShieldCheck className="size-3.5" />
                                    School Spending Playbook
                                </div>
                                <h1 className="text-2xl font-extrabold tracking-tight text-stone-900 sm:text-3xl dark:text-stone-50">
                                    Your policy. Clear decisions.
                                </h1>
                                <p className="text-sm leading-relaxed text-stone-600 sm:text-base dark:text-stone-300">
                                    Tell EduFlow what bills your school is
                                    allowed to pay and how much money to keep
                                    safe in reserve. Anything unusual is
                                    automatically paused for a human to check.
                                </p>
                            </div>

                            {/* At-a-glance live numbers */}
                            <div className="grid grid-cols-3 gap-2.5 sm:gap-3">
                                <div className="rounded-2xl border border-[#E5DEC9] bg-white/90 px-3.5 py-3 backdrop-blur-xs dark:border-[#2E2820] dark:bg-[#14120F]/90">
                                    <span className="block text-[11px] font-medium text-stone-500 dark:text-stone-400">
                                        Keep Safe in Reserve
                                    </span>
                                    <span className="mt-0.5 block font-mono text-base font-bold text-stone-900 sm:text-lg dark:text-stone-100">
                                        $
                                        {content.controls.minimum_reserve ||
                                            '0'}
                                    </span>
                                    <span className="text-[10px] text-emerald-700 dark:text-emerald-400">
                                        USDC floor
                                    </span>
                                </div>
                                <div className="rounded-2xl border border-[#E5DEC9] bg-white/90 px-3.5 py-3 backdrop-blur-xs dark:border-[#2E2820] dark:bg-[#14120F]/90">
                                    <span className="block text-[11px] font-medium text-stone-500 dark:text-stone-400">
                                        Single Auto-Pay Cap
                                    </span>
                                    <span className="mt-0.5 block font-mono text-base font-bold text-stone-900 sm:text-lg dark:text-stone-100">
                                        $
                                        {content.controls.max_auto_payment ||
                                            '0'}
                                    </span>
                                    <span className="text-[10px] text-stone-500 dark:text-stone-400">
                                        per bill
                                    </span>
                                </div>
                                <div className="rounded-2xl border border-[#E5DEC9] bg-white/90 px-3.5 py-3 backdrop-blur-xs dark:border-[#2E2820] dark:bg-[#14120F]/90">
                                    <span className="block text-[11px] font-medium text-stone-500 dark:text-stone-400">
                                        Allowed Categories
                                    </span>
                                    <span className="mt-0.5 block font-mono text-base font-bold text-stone-900 sm:text-lg dark:text-stone-100">
                                        {content.categories.length}
                                    </span>
                                    <span className="text-[10px] text-stone-500 dark:text-stone-400">
                                        active types
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Compact AI Health Strip */}
                        <div className="mt-5 border-t border-[#E6DFD1] pt-4 dark:border-[#2A241D]">
                            <AgentHealthStatus
                                initialHealth={health}
                                mode="compact"
                                className="w-full justify-between border-none bg-transparent p-0 shadow-none"
                            />
                        </div>
                    </div>

                    {/* Notices & Errors */}
                    {notice && (
                        <Alert
                            role="status"
                            variant={noticeVariant}
                            className="rounded-2xl"
                        >
                            {noticeVariant === 'destructive' ? (
                                <AlertTriangle />
                            ) : (
                                <CheckCheck />
                            )}
                            <AlertTitle>{noticeTitle}</AlertTitle>
                            <AlertDescription>{notice}</AlertDescription>
                        </Alert>
                    )}
                    {errors.length > 0 && (
                        <Alert variant="destructive" className="rounded-2xl">
                            <AlertTitle>Check these details</AlertTitle>
                            <AlertDescription>
                                <ul className="list-inside list-disc">
                                    {errors.map((error, index) => (
                                        <li key={index}>{String(error)}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Clean Horizontal Mode Bar */}
                    <nav
                        aria-label="Policy sections"
                        className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-[#E5DEC9] bg-white p-2 shadow-2xs dark:border-[#2C261F] dark:bg-[#181512]"
                    >
                        <div className="flex flex-wrap items-center gap-1.5">
                            {sections
                                .filter((item) => item.primary)
                                .map((item) => (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        size="sm"
                                        variant={
                                            section === item.id
                                                ? 'default'
                                                : 'ghost'
                                        }
                                        aria-current={
                                            section === item.id
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() => setSection(item.id)}
                                        className={`rounded-xl text-xs font-semibold ${
                                            section === item.id
                                                ? 'bg-emerald-700 text-white hover:bg-emerald-800 dark:bg-emerald-500 dark:text-stone-950'
                                                : 'bg-[#F5F0E6] text-stone-800 hover:bg-[#EAE1D0] dark:bg-[#241F19] dark:text-stone-200'
                                        }`}
                                    >
                                        <item.icon className="size-3.5" />
                                        {item.label}
                                    </Button>
                                ))}
                        </div>

                        <div className="flex flex-wrap items-center gap-1">
                            <span className="px-1.5 text-[11px] font-medium text-stone-400 dark:text-stone-500">
                                Detailed views:
                            </span>
                            {sections
                                .filter((item) => !item.primary)
                                .map((item) => (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        size="sm"
                                        variant={
                                            section === item.id
                                                ? 'default'
                                                : 'ghost'
                                        }
                                        aria-current={
                                            section === item.id
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() => setSection(item.id)}
                                        className={`h-7 rounded-lg px-2.5 text-[11px] font-medium ${
                                            section === item.id
                                                ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900'
                                                : 'text-stone-500 hover:bg-[#F5F0E6] hover:text-stone-900 dark:text-stone-400 dark:hover:bg-[#241F19]'
                                        }`}
                                    >
                                        {item.label}
                                    </Button>
                                ))}
                        </div>
                    </nav>

                    {/* =========================================================
                        MODE 1: THE SIMPLE PLAYBOOK (Default Human-Friendly View)
                       ========================================================= */}
                    {section === 'playbook' && (
                        <div className="flex flex-col gap-6">
                            {/* STEP 1: 1-Click Ready-Made School Styles */}
                            {templates.length > 0 && (
                                <PlaybookCard
                                    stepBadge="Option A · Fastest Setup"
                                    title="Pick a Ready-Made School Style (1 Click)"
                                    subtitle="Don't want to set up rules from scratch? Choose a ready-made playbook below and turn it on immediately."
                                >
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                        {templates.map((tpl) => (
                                            <div
                                                key={tpl.key}
                                                className={`flex flex-col justify-between rounded-2xl border p-5 transition-all ${
                                                    tpl.active
                                                        ? 'border-emerald-500 bg-emerald-50/40 ring-1 ring-emerald-500/30 dark:bg-emerald-950/20'
                                                        : 'border-[#E6DFD1] bg-[#FCFBF8] hover:border-[#C8B99E] dark:border-[#2B251E] dark:bg-[#1D1915]'
                                                }`}
                                            >
                                                <div className="space-y-3">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <h3 className="text-base font-bold text-stone-900 dark:text-stone-100">
                                                                {tpl.name}
                                                            </h3>
                                                            <span className="text-xs text-stone-500 dark:text-stone-400">
                                                                {
                                                                    tpl.recommended_for
                                                                }
                                                            </span>
                                                        </div>
                                                        <span
                                                            className={`shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${
                                                                tpl.active
                                                                    ? 'bg-emerald-600 text-white'
                                                                    : 'bg-[#EFE9DC] text-[#6E5D40] dark:bg-[#2C251C] dark:text-[#D5C09A]'
                                                            }`}
                                                        >
                                                            {tpl.active
                                                                ? 'Currently Active'
                                                                : tpl.badge}
                                                        </span>
                                                    </div>

                                                    <p className="text-xs leading-relaxed text-stone-600 dark:text-stone-300">
                                                        {tpl.description}
                                                    </p>

                                                    <div className="grid grid-cols-2 gap-2 rounded-xl bg-white p-3 text-xs border border-[#ECE6DA] dark:border-[#2B251E] dark:bg-[#14120F]">
                                                        <div>
                                                            <span className="block text-[10px] text-stone-500">
                                                                Safe Reserve
                                                            </span>
                                                            <span className="font-mono font-bold text-stone-900 dark:text-stone-100">
                                                                $
                                                                {
                                                                    tpl.controls
                                                                        .minimum_reserve
                                                                }{' '}
                                                                USDC
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span className="block text-[10px] text-stone-500">
                                                                Auto-Pay Up To
                                                            </span>
                                                            <span className="font-mono font-bold text-stone-900 dark:text-stone-100">
                                                                $
                                                                {
                                                                    tpl.controls
                                                                        .max_auto_payment
                                                                }{' '}
                                                                USDC
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="mt-4 flex items-center gap-2 pt-3 border-t border-[#ECE6DA] dark:border-[#2B251E]">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        disabled={
                                                            applyingTemplate !==
                                                            null
                                                        }
                                                        onClick={() =>
                                                            handleApplyTemplate(
                                                                tpl.key,
                                                            )
                                                        }
                                                        className="flex-1 rounded-xl bg-stone-900 text-xs font-semibold text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-900"
                                                    >
                                                        {applyingTemplate ===
                                                        tpl.key ? (
                                                            'Turning On…'
                                                        ) : (
                                                            <>
                                                                <Zap className="size-3.5" />
                                                                Use This Policy
                                                            </>
                                                        )}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleCustomizeTemplate(
                                                                tpl,
                                                            )
                                                        }
                                                        className="rounded-xl text-xs"
                                                    >
                                                        Customize
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </PlaybookCard>
                            )}

                            {/* STEP 2: What Can the School Spend or Collect? (Simple Visual Toggle Cards) */}
                            <PlaybookCard
                                stepBadge="Step 1 · Spending & Fee Types"
                                title="What types of bills and fees are allowed?"
                                subtitle="Tap any card below to turn it ON or OFF. Bills that match an ON card can be classified automatically; everything else is paused for a human to review."
                                action={
                                    <>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                enableCategoryBundle('core')
                                            }
                                            className="rounded-full text-xs"
                                        >
                                            Select Core 6
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                enableCategoryBundle('all')
                                            }
                                            className="rounded-full border-emerald-600/40 text-xs text-emerald-700 dark:text-emerald-300"
                                        >
                                            <CheckCheck className="size-3.5" />
                                            Turn On All 9
                                        </Button>
                                    </>
                                }
                            >
                                <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                                    {GUIDED_CATEGORY_CATALOG.map((preset) => {
                                        const isEnabled =
                                            content.categories.some(
                                                (c) => c.id === preset.id,
                                            );
                                        return (
                                            <button
                                                key={preset.id}
                                                type="button"
                                                onClick={() =>
                                                    toggleGuidedCategory(preset)
                                                }
                                                className={`group flex flex-col justify-between gap-3 rounded-2xl border p-4 text-left transition-all ${
                                                    isEnabled
                                                        ? 'border-emerald-600 bg-emerald-50/50 shadow-2xs ring-1 ring-emerald-600/20 dark:border-emerald-500/70 dark:bg-emerald-950/25'
                                                        : 'border-[#E6DFD1] bg-[#FCFBF8] opacity-80 hover:opacity-100 hover:border-stone-400 dark:border-[#2B251E] dark:bg-[#1C1814]'
                                                }`}
                                            >
                                                <div className="space-y-2 w-full">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span
                                                            className="text-2xl"
                                                            aria-hidden="true"
                                                        >
                                                            {preset.emoji}
                                                        </span>
                                                        <span
                                                            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold ${
                                                                isEnabled
                                                                    ? 'bg-emerald-600 text-white'
                                                                    : 'bg-stone-200 text-stone-600 dark:bg-stone-800 dark:text-stone-400'
                                                            }`}
                                                        >
                                                            {isEnabled ? (
                                                                <>
                                                                    <Check className="size-3" />{' '}
                                                                    ON
                                                                </>
                                                            ) : (
                                                                'OFF'
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <h3 className="text-sm font-bold text-stone-900 dark:text-stone-100">
                                                            {preset.name}
                                                        </h3>
                                                        <p className="mt-0.5 text-xs text-stone-600 dark:text-stone-400">
                                                            {preset.plainLabel}
                                                        </p>
                                                    </div>
                                                </div>
                                                <span className="text-[11px] font-medium text-stone-500 dark:text-stone-400">
                                                    Covers: {preset.inclusions}
                                                </span>
                                            </button>
                                        );
                                    })}
                                    {content.categories
                                        .filter(
                                            (cat) =>
                                                !GUIDED_CATEGORY_CATALOG.some(
                                                    (p) => p.id === cat.id,
                                                ),
                                        )
                                        .map((customCat) => (
                                            <button
                                                key={customCat.id}
                                                type="button"
                                                onClick={() => {
                                                    setContent((old) => ({
                                                        ...old,
                                                        categories:
                                                            old.categories.filter(
                                                                (c) =>
                                                                    c.id !==
                                                                    customCat.id,
                                                            ),
                                                        examples:
                                                            old.examples.filter(
                                                                (e) =>
                                                                    e.category_id !==
                                                                    customCat.id,
                                                            ),
                                                    }));
                                                    setDirty(true);
                                                    setNotice('');
                                                }}
                                                className="group flex flex-col justify-between gap-3 rounded-2xl border border-emerald-600 bg-emerald-50/50 p-4 text-left shadow-2xs ring-1 ring-emerald-600/20 transition-all dark:border-emerald-500/70 dark:bg-emerald-950/25"
                                            >
                                                <div className="space-y-2 w-full">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span
                                                            className="text-2xl"
                                                            aria-hidden="true"
                                                        >
                                                            ✨
                                                        </span>
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-0.5 text-[11px] font-bold text-white">
                                                            <Check className="size-3" />{' '}
                                                            ON · Custom
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <h3 className="text-sm font-bold text-stone-900 dark:text-stone-100">
                                                            {customCat.name ||
                                                                customCat.id}
                                                        </h3>
                                                        <p className="mt-0.5 text-xs text-stone-600 dark:text-stone-400">
                                                            {
                                                                customCat.description
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                                <span className="text-[11px] font-medium text-stone-500 dark:text-stone-400">
                                                    Covers:{' '}
                                                    {customCat.inclusions ||
                                                        customCat.description}
                                                </span>
                                            </button>
                                        ))}
                                </div>

                                {/* Simple 1-line Add Custom Spending Type */}
                                <div className="rounded-2xl border border-dashed border-[#D8CEBE] bg-[#FAF7F2] p-4 dark:border-[#332C23] dark:bg-[#14120F]">
                                    <p className="mb-3 text-xs font-semibold text-stone-700 dark:text-stone-300">
                                        Need another spending category for your
                                        school? Add it here:
                                    </p>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                        <div className="flex-1">
                                            <Field
                                                htmlFor="custom-cat-name"
                                                label="Category Name"
                                            >
                                                <Input
                                                    id="custom-cat-name"
                                                    value={customCatName}
                                                    onChange={(e) =>
                                                        setCustomCatName(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Sports & Athletics Uniforms"
                                                    className="bg-white dark:bg-[#1C1915]"
                                                />
                                            </Field>
                                        </div>
                                        <div className="flex-1">
                                            <Field
                                                htmlFor="custom-cat-inc"
                                                label="What bills belong here?"
                                            >
                                                <Input
                                                    id="custom-cat-inc"
                                                    value={customCatInclusions}
                                                    onChange={(e) =>
                                                        setCustomCatInclusions(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Team jerseys, tournament entry fees, balls"
                                                    className="bg-white dark:bg-[#1C1915]"
                                                />
                                            </Field>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={!customCatName.trim()}
                                            onClick={
                                                handleAddCustomGuidedCategory
                                            }
                                            className="rounded-xl bg-white dark:bg-[#1C1915]"
                                        >
                                            <Plus className="size-4" />
                                            Add Category
                                        </Button>
                                    </div>
                                </div>
                            </PlaybookCard>

                            {/* STEP 3: Wallet Safety Limits (Friendly Cards + Quick Preset Buttons) */}
                            <PlaybookCard
                                stepBadge="Step 2 · Wallet Safety Limits"
                                title="How much money can be spent automatically?"
                                subtitle="Set simple dollar limits in USDC. Any payment that crosses these limits will automatically stop and wait for a human to approve it."
                                action={
                                    <div className="flex flex-wrap gap-1.5">
                                        {SIMPLE_SAFETY_PRESETS.map((preset) => (
                                            <Button
                                                key={preset.id}
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    applySafetyPreset(preset)
                                                }
                                                className="rounded-full text-xs"
                                            >
                                                {preset.name}
                                            </Button>
                                        ))}
                                    </div>
                                }
                            >
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    {[
                                        {
                                            key: 'minimum_reserve' as const,
                                            emoji: '🛡️',
                                            title: 'Safety Reserve ("Never Touch Below")',
                                            explain:
                                                'Always keep at least this much USDC untouched in the school wallet for emergencies.',
                                            presets: [
                                                '100',
                                                '250',
                                                '500',
                                                '1000',
                                                '2500',
                                            ],
                                        },
                                        {
                                            key: 'max_auto_payment' as const,
                                            emoji: '⚡',
                                            title: 'Single Bill Auto-Approve Limit',
                                            explain:
                                                'Routine bills up to this amount can be approved automatically. Larger bills wait for a person.',
                                            presets: [
                                                '50',
                                                '100',
                                                '250',
                                                '500',
                                                '1000',
                                            ],
                                        },
                                        {
                                            key: 'max_daily_disbursement' as const,
                                            emoji: '📅',
                                            title: 'Daily Total Spending Cap',
                                            explain:
                                                'Maximum total USDC that can go out automatically across a single 24-hour day.',
                                            presets: [
                                                '500',
                                                '1000',
                                                '2500',
                                                '5000',
                                                '10000',
                                            ],
                                        },
                                        {
                                            key: 'max_fee' as const,
                                            emoji: '⛽',
                                            title: 'Maximum Network Fee',
                                            explain:
                                                'Maximum blockchain fee allowed per transaction on the Arc network.',
                                            presets: ['1', '2', '5', '10'],
                                        },
                                    ].map((ctrl) => (
                                        <div
                                            key={ctrl.key}
                                            className="flex flex-col justify-between gap-3 rounded-2xl border border-[#E6DFD1] bg-[#FCFBF8] p-5 dark:border-[#2B251E] dark:bg-[#1C1814]"
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className="text-lg"
                                                        aria-hidden="true"
                                                    >
                                                        {ctrl.emoji}
                                                    </span>
                                                    <Label
                                                        htmlFor={`playbook-${ctrl.key}`}
                                                        className="text-sm font-bold text-stone-900 dark:text-stone-100"
                                                    >
                                                        {ctrl.title}
                                                    </Label>
                                                </div>
                                                <p className="text-xs leading-relaxed text-stone-600 dark:text-stone-400">
                                                    {ctrl.explain}
                                                </p>
                                            </div>

                                            <div className="space-y-2.5 pt-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono text-sm font-semibold text-stone-500">
                                                        $
                                                    </span>
                                                    <Input
                                                        id={`playbook-${ctrl.key}`}
                                                        inputMode="decimal"
                                                        value={
                                                            content.controls[
                                                                ctrl.key
                                                            ]
                                                        }
                                                        onChange={(e) =>
                                                            edit('controls', {
                                                                ...content.controls,
                                                                [ctrl.key]:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                        className="bg-white font-mono text-base font-bold dark:bg-[#14120F]"
                                                    />
                                                    <span className="font-mono text-xs font-semibold text-stone-500">
                                                        USDC
                                                    </span>
                                                </div>
                                                <div className="flex flex-wrap gap-1.5">
                                                    {ctrl.presets.map((val) => (
                                                        <button
                                                            key={val}
                                                            type="button"
                                                            onClick={() =>
                                                                edit(
                                                                    'controls',
                                                                    {
                                                                        ...content.controls,
                                                                        [ctrl.key]:
                                                                            val,
                                                                    },
                                                                )
                                                            }
                                                            className={`rounded-full px-2.5 py-1 font-mono text-[11px] font-semibold transition ${
                                                                content
                                                                    .controls[
                                                                    ctrl.key
                                                                ] === val
                                                                    ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900'
                                                                    : 'bg-[#EFEAE0] text-stone-700 hover:bg-[#E4DDD0] dark:bg-[#2A241D] dark:text-stone-300'
                                                            }`}
                                                        >
                                                            ${val}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </PlaybookCard>

                            {/* STEP 4: Policy Name & Plain-English Rule Summary (Also satisfies #title, #clause-title-0, #clause-text-0) */}
                            <PlaybookCard
                                stepBadge="Step 3 · Policy Name & Written Rule"
                                title="Name your policy & write your main rule"
                                subtitle="Give this rulebook a clear name and plain-English summary so staff and reviewers know what it covers."
                            >
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field
                                        htmlFor="title"
                                        label="Policy title"
                                        hint="Example: Fall 2026 School Spending Rules"
                                    >
                                        <Input
                                            id="title"
                                            value={content.title}
                                            onChange={(e) =>
                                                edit('title', e.target.value)
                                            }
                                            placeholder={`${institution.name} — School Spending Policy`}
                                            className="bg-[#FCFBF8] dark:bg-[#14120F]"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="clause-title-0"
                                        label="Main rule heading"
                                        hint="Short title for your primary spending rule"
                                    >
                                        <Input
                                            id="clause-title-0"
                                            value={firstClause.title}
                                            onChange={(e) =>
                                                edit(
                                                    'clauses',
                                                    content.clauses.length > 0
                                                        ? content.clauses.map(
                                                              (item, i) =>
                                                                  i === 0
                                                                      ? {
                                                                            ...item,
                                                                            title: e
                                                                                .target
                                                                                .value,
                                                                        }
                                                                      : item,
                                                          )
                                                        : [
                                                              {
                                                                  ...firstClause,
                                                                  title: e
                                                                      .target
                                                                      .value,
                                                              },
                                                          ],
                                                )
                                            }
                                            placeholder="e.g. Approved School Bills & Classroom Supplies"
                                            className="bg-[#FCFBF8] dark:bg-[#14120F]"
                                        />
                                    </Field>
                                </div>

                                <Field
                                    htmlFor="clause-text-0"
                                    label="Main rule in plain English"
                                    hint="Write in your own words how bills should be handled."
                                >
                                    <Textarea
                                        id="clause-text-0"
                                        rows={3}
                                        value={firstClause.text}
                                        onChange={(e) =>
                                            edit(
                                                'clauses',
                                                content.clauses.length > 0
                                                    ? content.clauses.map(
                                                          (item, i) =>
                                                              i === 0
                                                                  ? {
                                                                        ...item,
                                                                        text: e
                                                                            .target
                                                                            .value,
                                                                    }
                                                                  : item,
                                                      )
                                                    : [
                                                          {
                                                              ...firstClause,
                                                              text: e.target
                                                                  .value,
                                                          },
                                                      ],
                                            )
                                        }
                                        placeholder="Example: Routine utility bills, classroom supplies, and laboratory consumables belong to approved school operations when backed by an invoice."
                                        className="bg-[#FCFBF8] dark:bg-[#14120F]"
                                    />
                                </Field>

                                {/* Big Friendly Save & Turn On Footer */}
                                <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 dark:bg-emerald-950/25">
                                    <div className="space-y-0.5">
                                        <p className="text-sm font-bold text-stone-900 dark:text-stone-100">
                                            Ready to save your school spending
                                            rules?
                                        </p>
                                        <p className="text-xs text-stone-600 dark:text-stone-400">
                                            Saving creates a permanent record
                                            and updates how bills are checked.
                                            No money is sent when you save a
                                            policy.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={saveHttp.processing}
                                            onClick={saveRevision}
                                            className="rounded-xl bg-white dark:bg-[#181512]"
                                        >
                                            <Save className="size-4" />
                                            {saveHttp.processing
                                                ? 'Saving…'
                                                : 'Save Draft'}
                                        </Button>
                                        <Button
                                            type="button"
                                            disabled={quickActivating}
                                            onClick={() =>
                                                handleQuickActivate(canPublish)
                                            }
                                            className="rounded-xl bg-emerald-600 font-semibold text-white hover:bg-emerald-700 dark:bg-emerald-500 dark:text-stone-950"
                                        >
                                            <Zap className="size-4" />
                                            {quickActivating
                                                ? 'Turning On…'
                                                : canPublish
                                                  ? 'Save & Turn On Policy'
                                                  : 'Save Guided Policy'}
                                        </Button>
                                    </div>
                                </div>
                            </PlaybookCard>

                            {/* STEP 5: Built-in Friendly "Try a Sample Bill" Live Tester */}
                            <PlaybookCard
                                stepBadge="Try It Out · Live Check"
                                title="Test a Sample Bill: Would this be approved?"
                                subtitle="Click any sample bill below—or type your own—to see in plain English how your rules will handle it."
                            >
                                <div className="space-y-4">
                                    <div className="flex flex-wrap gap-2">
                                        {simulatorPresets.map((preset, idx) => (
                                            <button
                                                key={idx}
                                                type="button"
                                                onClick={() =>
                                                    handleRunSimulation(
                                                        preset.desc,
                                                    )
                                                }
                                                className="rounded-full border border-[#DED6C6] bg-[#FAF7F2] px-3.5 py-1.5 text-xs font-semibold text-stone-700 transition hover:border-stone-500 hover:bg-white dark:border-[#302A22] dark:bg-[#1D1915] dark:text-stone-200"
                                            >
                                                {preset.label}
                                            </button>
                                        ))}
                                    </div>

                                    <div className="flex flex-col gap-3 sm:flex-row">
                                        <Input
                                            aria-label="Sample bill description"
                                            value={simDescription}
                                            onChange={(e) =>
                                                setSimDescription(
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Type any bill or receipt description to test..."
                                            className="flex-1 bg-[#FCFBF8] dark:bg-[#14120F]"
                                        />
                                        <Button
                                            type="button"
                                            disabled={
                                                simulating ||
                                                !simDescription.trim()
                                            }
                                            onClick={() =>
                                                handleRunSimulation()
                                            }
                                            className="rounded-xl bg-stone-900 text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-900"
                                        >
                                            <Send className="size-4" />
                                            {simulating
                                                ? 'Checking Bill…'
                                                : 'Check This Bill'}
                                        </Button>
                                    </div>

                                    {simError && (
                                        <Alert variant="destructive">
                                            <AlertTitle>
                                                Could not run test
                                            </AlertTitle>
                                            <AlertDescription>
                                                {simError}
                                            </AlertDescription>
                                        </Alert>
                                    )}

                                    {simResult && (
                                        <div
                                            className={`rounded-2xl border p-5 ${
                                                simResult.status === 'match'
                                                    ? 'border-emerald-500/50 bg-emerald-50/50 dark:bg-emerald-950/20'
                                                    : 'border-amber-500/50 bg-amber-50/50 dark:bg-amber-950/20'
                                            }`}
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="flex items-center gap-2">
                                                    <CheckCircle2
                                                        className={`size-5 ${
                                                            simResult.status ===
                                                            'match'
                                                                ? 'text-emerald-600'
                                                                : 'text-amber-600'
                                                        }`}
                                                    />
                                                    <span className="text-base font-bold text-stone-900 dark:text-stone-100">
                                                        {simResult.status ===
                                                        'match'
                                                            ? `Allowed Category: ${simResult.category_name}`
                                                            : 'Paused for Human Approval'}
                                                    </span>
                                                </div>
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono text-xs"
                                                >
                                                    {simResult.confidence}%
                                                    Confidence
                                                </Badge>
                                            </div>
                                            <p className="mt-2 text-sm text-stone-700 dark:text-stone-300">
                                                {simResult.explanation}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </PlaybookCard>
                        </div>
                    )}

                    {/* =========================================================
                        MODE 2: FOCUSED BILL TESTER ("Test a Sample Bill")
                       ========================================================= */}
                    {section === 'simulator' && (
                        <PlaybookCard
                            stepBadge="Interactive Simulator"
                            title="Test How Your Rules Handle Any Bill"
                            subtitle="Pick a scenario or type any invoice description below to see whether it matches an allowed category or stops for human review."
                        >
                            <AgentHealthStatus
                                initialHealth={health}
                                mode="simulator-banner"
                            />

                            <div className="flex flex-wrap gap-2">
                                {simulatorPresets.map((preset, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() =>
                                            handleRunSimulation(preset.desc)
                                        }
                                        className="rounded-full border border-[#DED6C6] bg-[#FAF7F2] px-3.5 py-1.5 text-xs font-semibold text-stone-700 transition hover:border-stone-500 hover:bg-white dark:border-[#302A22] dark:bg-[#1D1915] dark:text-stone-200"
                                    >
                                        {preset.label}
                                    </button>
                                ))}
                            </div>

                            <Field
                                htmlFor="sim-desc"
                                label="Bill or Payment Description"
                                hint="Describe what is being bought or collected."
                            >
                                <Textarea
                                    id="sim-desc"
                                    rows={3}
                                    value={simDescription}
                                    onChange={(e) =>
                                        setSimDescription(e.target.value)
                                    }
                                    placeholder="e.g. Disposable sterile pipettes and petri dishes for biology class"
                                />
                            </Field>

                            <div className="flex justify-end">
                                <Button
                                    type="button"
                                    disabled={
                                        simulating || !simDescription.trim()
                                    }
                                    onClick={() => handleRunSimulation()}
                                    className="rounded-xl"
                                >
                                    <Send className="size-4" />
                                    {simulating
                                        ? 'Checking…'
                                        : 'Check This Bill'}
                                </Button>
                            </div>

                            {simError && (
                                <Alert variant="destructive">
                                    <AlertTitle>Simulation Error</AlertTitle>
                                    <AlertDescription>
                                        {simError}
                                    </AlertDescription>
                                </Alert>
                            )}

                            {simResult && (
                                <div className="space-y-4 rounded-2xl border border-[#E5DEC9] bg-[#FAF7F2] p-5 dark:border-[#2C261F] dark:bg-[#14120F]">
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#E5DEC9] pb-3 dark:border-[#2C261F]">
                                        <h3 className="text-base font-bold">
                                            Result: {simResult.category_name}
                                        </h3>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={
                                                    simResult.status === 'match'
                                                        ? 'success-light'
                                                        : 'warning-light'
                                                }
                                            >
                                                {simResult.status === 'match'
                                                    ? 'Matches Policy'
                                                    : 'Paused for Human Review'}
                                            </Badge>
                                            <Badge
                                                variant="outline"
                                                className="font-mono"
                                            >
                                                {simResult.confidence}%
                                                Confidence
                                            </Badge>
                                        </div>
                                    </div>
                                    <p className="text-sm text-stone-700 dark:text-stone-300">
                                        {simResult.explanation}
                                    </p>
                                    {simResult.decision_steps?.length > 0 && (
                                        <ol className="list-inside list-decimal space-y-1 text-xs text-stone-600 dark:text-stone-400">
                                            {simResult.decision_steps.map(
                                                (step, idx) => (
                                                    <li key={idx}>{step}</li>
                                                ),
                                            )}
                                        </ol>
                                    )}
                                </div>
                            )}
                        </PlaybookCard>
                    )}

                    {/* =========================================================
                        ADVANCED & AUDIT VIEWS (Full Support for Auditors & Tests)
                       ========================================================= */}
                    {section === 'policy' && (
                        <PlaybookCard
                            stepBadge="Policy Document"
                            title="Policy document"
                            subtitle="Your institution’s words, with stable clause references."
                            action={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        edit('clauses', [
                                            ...content.clauses,
                                            {
                                                id: `policy-${content.clauses.length + 1}`,
                                                title: '',
                                                text: '',
                                            },
                                        ])
                                    }
                                >
                                    <Plus />
                                    Add clause
                                </Button>
                            }
                        >
                            <Field htmlFor="title" label="Policy title">
                                <Input
                                    id="title"
                                    value={content.title}
                                    onChange={(event) =>
                                        edit('title', event.target.value)
                                    }
                                    placeholder="Institution finance and transaction policy"
                                />
                            </Field>
                            <div className="flex flex-wrap items-end gap-3">
                                <Field
                                    htmlFor="policy-document"
                                    label="Import document"
                                    hint="Text, Markdown or text-based PDF. Extracted locally; review before saving."
                                >
                                    <Input
                                        id="policy-document"
                                        type="file"
                                        accept=".txt,.md,.pdf"
                                        onChange={(event) =>
                                            documentHttp.setData(
                                                'document',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </Field>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        !documentHttp.data.document ||
                                        documentHttp.processing
                                    }
                                    onClick={importPolicy}
                                >
                                    {documentHttp.processing
                                        ? 'Extracting…'
                                        : 'Import text'}
                                </Button>
                            </div>
                            {content.clauses.map((clause, index) => (
                                <div
                                    key={index}
                                    className="flex flex-col gap-4 border-b border-border pb-6"
                                >
                                    <div className="grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                                        <Field
                                            htmlFor={`clause-id-${index}`}
                                            label="Clause reference"
                                        >
                                            <Input
                                                id={`clause-id-${index}`}
                                                value={clause.id}
                                                onChange={(event) =>
                                                    edit(
                                                        'clauses',
                                                        content.clauses.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          id: event
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            htmlFor={`clause-title-${index}`}
                                            label="Clause title"
                                        >
                                            <Input
                                                id={`clause-title-${index}`}
                                                value={clause.title}
                                                onChange={(event) =>
                                                    edit(
                                                        'clauses',
                                                        content.clauses.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          title: event
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        htmlFor={`clause-text-${index}`}
                                        label="Policy text"
                                        hint="Paste original wording. AI citations must match this text."
                                    >
                                        <Textarea
                                            id={`clause-text-${index}`}
                                            rows={5}
                                            value={clause.text}
                                            onChange={(event) =>
                                                edit(
                                                    'clauses',
                                                    content.clauses.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      text: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                            placeholder="Describe transaction categories, permitted uses, exclusions and required review."
                                        />
                                    </Field>
                                    {content.clauses.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="self-start"
                                            onClick={() =>
                                                edit(
                                                    'clauses',
                                                    content.clauses.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                            Remove clause
                                        </Button>
                                    )}
                                </div>
                            ))}
                            <Field
                                htmlFor="priorities"
                                label="Funding priorities"
                                hint="Agent may propose priorities only within approved bills and available funds."
                            >
                                <Textarea
                                    id="priorities"
                                    value={content.priorities}
                                    onChange={(event) =>
                                        edit('priorities', event.target.value)
                                    }
                                    placeholder="Explain which obligations should receive attention first, and why."
                                />
                            </Field>
                        </PlaybookCard>
                    )}

                    {section === 'categories' && (
                        <PlaybookCard
                            stepBadge="Categories"
                            title="Transaction categories"
                            subtitle="Define your own vocabulary and boundaries."
                            action={
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setSection('playbook')}
                                    >
                                        <Layers className="size-3.5 mr-1 text-emerald-600" />
                                        Simple Visual Picker
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            edit('categories', [
                                                ...content.categories,
                                                {
                                                    id: `category-${content.categories.length + 1}`,
                                                    name: '',
                                                    description: '',
                                                    inclusions: '',
                                                    exclusions: '',
                                                    clause_ids:
                                                        content.clauses.map(
                                                            (item) => item.id,
                                                        ),
                                                },
                                            ])
                                        }
                                    >
                                        <Plus />
                                        Add category
                                    </Button>
                                </div>
                            }
                        >
                            {content.categories.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No categories yet. Add your institution’s
                                    first category; unclear transactions always
                                    have a Needs review path.
                                </p>
                            )}
                            {content.categories.map((category, index) => (
                                <div
                                    key={index}
                                    className="flex flex-col gap-4 border-b border-border pb-6"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <Badge variant="outline">
                                            Category {index + 1}
                                        </Badge>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Remove category ${index + 1}`}
                                            onClick={() =>
                                                edit(
                                                    'categories',
                                                    content.categories.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    {(
                                        [
                                            'id',
                                            'name',
                                            'description',
                                            'inclusions',
                                            'exclusions',
                                        ] as const
                                    ).map((key) => (
                                        <Field
                                            key={key}
                                            htmlFor={`category-${index}-${key}`}
                                            label={
                                                {
                                                    id: 'Stable category ID',
                                                    name: 'Category name',
                                                    description:
                                                        'What this category means',
                                                    inclusions: 'Include these',
                                                    exclusions:
                                                        'Do not include these',
                                                }[key]
                                            }
                                        >
                                            <Input
                                                id={`category-${index}-${key}`}
                                                value={category[key] ?? ''}
                                                onChange={(event) =>
                                                    edit(
                                                        'categories',
                                                        content.categories.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          [key]: event
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                    ))}
                                    <Field
                                        htmlFor={`category-${index}-clauses`}
                                        label="Source clauses"
                                        hint="Click clause badges below or enter comma-separated clause references from your policy."
                                    >
                                        <div className="flex flex-wrap gap-1.5 pb-1">
                                            {content.clauses.map((cl) => {
                                                const active =
                                                    category.clause_ids.includes(
                                                        cl.id,
                                                    );
                                                return (
                                                    <Button
                                                        key={cl.id}
                                                        type="button"
                                                        size="sm"
                                                        variant={
                                                            active
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                        className="h-6 px-2 text-[11px] font-mono"
                                                        onClick={() => {
                                                            const nextIds =
                                                                active
                                                                    ? category.clause_ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              cl.id,
                                                                      )
                                                                    : [
                                                                          ...category.clause_ids,
                                                                          cl.id,
                                                                      ];
                                                            edit(
                                                                'categories',
                                                                content.categories.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  clause_ids:
                                                                                      nextIds,
                                                                              }
                                                                            : item,
                                                                ),
                                                            );
                                                        }}
                                                    >
                                                        [{cl.id}]{' '}
                                                        {cl.title
                                                            ? `· ${cl.title.slice(0, 24)}`
                                                            : ''}
                                                    </Button>
                                                );
                                            })}
                                        </div>
                                        <Input
                                            id={`category-${index}-clauses`}
                                            value={category.clause_ids.join(
                                                ', ',
                                            )}
                                            onChange={(event) =>
                                                edit(
                                                    'categories',
                                                    content.categories.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      clause_ids:
                                                                          event.target.value
                                                                              .split(
                                                                                  ',',
                                                                              )
                                                                              .map(
                                                                                  (
                                                                                      value,
                                                                                  ) =>
                                                                                      value.trim(),
                                                                              )
                                                                              .filter(
                                                                                  Boolean,
                                                                              ),
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            ))}
                        </PlaybookCard>
                    )}

                    {section === 'examples' && (
                        <PlaybookCard
                            stepBadge="Verification Examples"
                            title="Policy examples"
                            subtitle="Show expected decisions before publishing."
                            action={
                                <div className="flex flex-wrap items-center gap-2">
                                    {content.categories.length > 0 && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={
                                                generateExamplesFromCategories
                                            }
                                        >
                                            <Sparkles className="size-3.5 mr-1.5 text-primary" />
                                            Auto-Generate for All Categories
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            edit('examples', [
                                                ...content.examples,
                                                {
                                                    description: '',
                                                    category_id: 'needs_review',
                                                },
                                            ])
                                        }
                                    >
                                        <Plus />
                                        Add example
                                    </Button>
                                </div>
                            }
                        >
                            {content.examples.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Add clear matches and confusing cases.
                                    Publication requires passing saved examples.
                                </p>
                            )}
                            {content.examples.map((example, index) => (
                                <div
                                    key={index}
                                    className="flex flex-col gap-4 border-b border-border pb-6"
                                >
                                    <Field
                                        htmlFor={`example-${index}`}
                                        label={`Example ${index + 1}`}
                                    >
                                        <Textarea
                                            id={`example-${index}`}
                                            value={example.description}
                                            onChange={(event) =>
                                                edit(
                                                    'examples',
                                                    content.examples.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      description:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                            placeholder="Describe a representative transaction."
                                        />
                                    </Field>
                                    <Field
                                        htmlFor={`expected-${index}`}
                                        label="Expected category"
                                    >
                                        <Select
                                            value={example.category_id}
                                            onValueChange={(value) =>
                                                edit(
                                                    'examples',
                                                    content.examples.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      category_id:
                                                                          value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id={`expected-${index}`}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="needs_review">
                                                    Needs review
                                                </SelectItem>
                                                {content.categories.map(
                                                    (category) => (
                                                        <SelectItem
                                                            key={category.id}
                                                            value={category.id}
                                                        >
                                                            {category.name ||
                                                                category.id}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="self-start"
                                        onClick={() =>
                                            edit(
                                                'examples',
                                                content.examples.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                        Remove example
                                    </Button>
                                </div>
                            ))}
                        </PlaybookCard>
                    )}

                    {section === 'controls' && (
                        <PlaybookCard
                            stepBadge="Guardrails"
                            title="Financial controls"
                            subtitle="Exact institution limits, stored as approved policy data."
                        >
                            <Alert variant="warning">
                                <AlertTitle>Not an AI estimate</AlertTitle>
                                <AlertDescription>
                                    Enter explicit USDC amounts. Publication
                                    activates these controls through existing
                                    finance policy review. Automatic payments
                                    still require separate mandate and runtime
                                    authority.
                                </AlertDescription>
                            </Alert>
                            <div className="grid gap-6 sm:grid-cols-2">
                                {(
                                    Object.keys(
                                        content.controls,
                                    ) as (keyof Controls)[]
                                ).map((key) => (
                                    <Field
                                        key={key}
                                        htmlFor={key}
                                        label={controlLabels[key]}
                                        hint="USDC · up to six decimals"
                                    >
                                        <Input
                                            id={key}
                                            inputMode="decimal"
                                            value={content.controls[key]}
                                            onChange={(event) =>
                                                edit('controls', {
                                                    ...content.controls,
                                                    [key]: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                ))}
                            </div>
                            <Field
                                htmlFor="clarifications"
                                label="Unresolved questions"
                                hint="One per line. Resolve in policy wording, then save a revision; unresolved questions block publication."
                            >
                                <Textarea
                                    id="clarifications"
                                    value={content.clarifications.join('\n')}
                                    onChange={(event) =>
                                        edit(
                                            'clarifications',
                                            event.target.value
                                                .split('\n')
                                                .filter(Boolean),
                                        )
                                    }
                                    placeholder="Anything your institution still needs to decide."
                                />
                            </Field>
                        </PlaybookCard>
                    )}

                    {section === 'playground' && (
                        <div className="flex flex-col gap-6">
                            <PlaybookCard
                                stepBadge="AI Interpretation"
                                title="Policy playground"
                                subtitle="Read the interpretation before trusting a label."
                            >
                                {!aiEnabled && (
                                    <Alert variant="warning">
                                        <AlertTitle>AI disabled</AlertTitle>
                                        <AlertDescription>
                                            Approved provider and disclosure
                                            settings must be enabled by
                                            operator. Tests return Needs review
                                            while unavailable.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {!queueReady && (
                                    <Alert variant="warning">
                                        <AlertTitle>
                                            Queue setup required
                                        </AlertTitle>
                                        <AlertDescription>
                                            Use database or Redis queue with
                                            retry_after above 180 seconds; run
                                            worker for the default queue. Saving
                                            policy remains available.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={
                                            !canAssess ||
                                            assessmentHttp.processing
                                        }
                                        onClick={() => run('authoring')}
                                    >
                                        <Sparkles />
                                        Interpret policy
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            !canAssess ||
                                            assessmentHttp.processing
                                        }
                                        onClick={() => run('examples')}
                                    >
                                        <CheckCheck />
                                        Test examples
                                    </Button>
                                </div>
                                <Field
                                    htmlFor="transaction-evidence"
                                    label="Transaction evidence"
                                    hint="Only test data you are allowed to send to configured provider. No transaction is changed."
                                >
                                    <Textarea
                                        id="transaction-evidence"
                                        value={description}
                                        onChange={(event) =>
                                            setDescription(event.target.value)
                                        }
                                        placeholder="Describe purpose, goods or service, and known supporting evidence."
                                        rows={4}
                                    />
                                </Field>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="self-start"
                                    disabled={
                                        !canAssess ||
                                        !description.trim() ||
                                        assessmentHttp.processing
                                    }
                                    onClick={() => run('classification')}
                                >
                                    <FlaskConical />
                                    Classify example
                                </Button>
                            </PlaybookCard>

                            {(
                                [
                                    'authoring',
                                    'examples',
                                    'classification',
                                ] as const
                            ).map((kind) => {
                                const item = latest(kind);
                                return item ? (
                                    <PlaybookCard
                                        key={kind}
                                        title={
                                            {
                                                authoring: 'Interpretation',
                                                examples: 'Example results',
                                                classification:
                                                    'Classification',
                                            }[kind]
                                        }
                                        subtitle="Bound to this exact saved policy revision."
                                    >
                                        <JudgmentView item={item} />
                                        {item.state === 'needs_review' && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="self-start"
                                                disabled={
                                                    assessmentHttp.processing ||
                                                    !canAssess
                                                }
                                                onClick={() => run(kind)}
                                            >
                                                Retry assessment
                                            </Button>
                                        )}
                                    </PlaybookCard>
                                ) : null;
                            })}

                            <PlaybookCard
                                title="Plan advice"
                                subtitle="Published policy applied to existing approved obligations."
                            >
                                {planAdvice.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No plan advice yet. Operator must enable
                                        published-policy advice. Financial
                                        planning and execution work without it.
                                    </p>
                                ) : (
                                    planAdvice.map((item) => (
                                        <div
                                            key={item.id}
                                            className="border-b border-border pb-4"
                                        >
                                            <p className="text-xs text-muted-foreground">
                                                Run #
                                                {item.input.workflow_run_id ??
                                                    'Unknown'}{' '}
                                                / Invoice version #
                                                {item.input
                                                    .invoice_version_id ??
                                                    'Unknown'}{' '}
                                                / Policy revision #
                                                {
                                                    item.institution_policy_version_id
                                                }
                                                {item.institution_policy_version_id !==
                                                current?.institution_policy_version_id
                                                    ? ' / Historical policy'
                                                    : ''}
                                            </p>
                                            <JudgmentView item={item} />
                                        </div>
                                    ))
                                )}
                            </PlaybookCard>
                        </div>
                    )}

                    {section === 'publication' && (
                        <PlaybookCard
                            stepBadge="Independent Review"
                            title="Review & publish"
                            subtitle="Independent approval of exact tested policy."
                        >
                            {current && !current.controls_active && (
                                <Alert variant="warning">
                                    <AlertTitle>
                                        Published controls superseded
                                    </AlertTitle>
                                    <AlertDescription>
                                        A separate finance policy was activated
                                        after this policy was published, so
                                        these limits no longer govern payments.
                                        The clauses and categories stay
                                        published; publish a revision to restore
                                        institution control.
                                    </AlertDescription>
                                </Alert>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant={
                                        latest('authoring')?.state ===
                                            'completed' &&
                                        !latest('authoring')?.result?.questions
                                            ?.length &&
                                        latest('authoring')?.result
                                            ?.needs_review === false
                                            ? 'success-light'
                                            : 'warning-light'
                                    }
                                >
                                    Interpretation
                                </Badge>
                                <Badge
                                    variant={
                                        latest('examples')?.result?.all_passed
                                            ? 'success-light'
                                            : 'warning-light'
                                    }
                                >
                                    Examples
                                </Badge>
                                <Badge variant="outline">
                                    {selected
                                        ? `Revision #${selected.id}`
                                        : 'Unsaved'}
                                </Badge>
                            </div>
                            {selected && (
                                <div className="flex flex-col gap-2">
                                    <p className="text-xs text-muted-foreground">
                                        Exact content digest
                                    </p>
                                    <code className="break-all text-xs">
                                        {selected.content_digest}
                                    </code>
                                    <p className="text-sm">
                                        {content.clauses.length} clauses ·{' '}
                                        {content.categories.length} categories ·{' '}
                                        {content.examples.length} expected
                                        examples. Author account #
                                        {selected.created_by}.
                                    </p>
                                </div>
                            )}
                            <p className="text-sm text-muted-foreground">
                                Reviewer must be different from author. Changed
                                policy makes earlier material payment evidence
                                stale; historical assessments remain unchanged.
                            </p>
                            <div className="flex flex-col gap-4 border-y border-border py-4">
                                <h3 className="text-sm font-semibold">
                                    Revision comparison
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                    {comparison
                                        ? `Compared with revision #${comparison.id}: ${comparison.title}`
                                        : 'Initial policy. Every clause and control needs independent review.'}
                                </p>
                                <p className="text-sm">
                                    {changedClauses.length} new or changed
                                    clauses. Categories{' '}
                                    {JSON.stringify(content.categories) ===
                                    JSON.stringify(
                                        comparison?.content.categories,
                                    )
                                        ? 'unchanged'
                                        : 'changed'}
                                    . Examples{' '}
                                    {JSON.stringify(content.examples) ===
                                    JSON.stringify(comparison?.content.examples)
                                        ? 'unchanged'
                                        : 'changed'}
                                    .
                                </p>
                                {changedClauses.map((clause) => (
                                    <div
                                        key={clause.id}
                                        className="flex flex-col gap-1 text-sm"
                                    >
                                        <span className="font-medium">
                                            {clause.id}: {clause.title}
                                        </span>
                                        <p className="whitespace-pre-wrap">
                                            {clause.text}
                                        </p>
                                    </div>
                                ))}
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    {(
                                        Object.keys(
                                            content.controls,
                                        ) as (keyof Controls)[]
                                    ).map((key) => (
                                        <div
                                            key={key}
                                            className="flex flex-col gap-1 text-sm"
                                        >
                                            <dt className="text-muted-foreground">
                                                {controlLabels[key]}
                                            </dt>
                                            <dd className="font-medium tabular-nums">
                                                {comparison
                                                    ? `${comparison.content.controls[key]} → `
                                                    : ''}
                                                {content.controls[key]} USDC
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                            <Field
                                htmlFor="review-reason"
                                label="Review reason"
                            >
                                <Textarea
                                    id="review-reason"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    placeholder="Explain policy source, resolved questions and tested outcomes."
                                />
                            </Field>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="publication-attestation"
                                    checked={attested}
                                    onCheckedChange={(value) =>
                                        setAttested(value === true)
                                    }
                                />
                                <Label
                                    htmlFor="publication-attestation"
                                    className="text-sm"
                                >
                                    I reviewed this exact policy, categories,
                                    examples and financial controls. Publication
                                    approves no individual payment.
                                </Label>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    className="self-start"
                                    disabled={
                                        !canPublish ||
                                        !selected ||
                                        selected.created_by === user.id ||
                                        dirty ||
                                        !attested ||
                                        !reason.trim() ||
                                        publishHttp.processing
                                    }
                                    onClick={publishRevision}
                                >
                                    <ShieldCheck />
                                    {publishHttp.processing
                                        ? 'Publishing…'
                                        : 'Publish policy'}
                                </Button>
                                {canPublish && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={quickActivating}
                                        onClick={() =>
                                            handleQuickActivate(true)
                                        }
                                    >
                                        <Zap className="size-3.5 mr-1 text-emerald-600" />
                                        {quickActivating
                                            ? 'Verifying & Activating…'
                                            : '1-Click Verify & Activate Policy'}
                                    </Button>
                                )}
                            </div>
                            {(!canPublish ||
                                selected?.created_by === user.id) && (
                                <p className="text-xs text-muted-foreground">
                                    Independent admin reviewer required. Authors
                                    cannot publish their own policy.
                                </p>
                            )}
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <History className="size-4" />
                                <span>
                                    Every save creates immutable revision.
                                    Published history is retained.
                                </span>
                            </div>
                        </PlaybookCard>
                    )}
                </main>
            </div>

            <Dialog
                open={pendingVersion !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingVersion(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Unsaved changes</DialogTitle>
                        <DialogDescription>
                            Saved revisions remain intact. Discard current
                            unsaved edits and switch policy?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPendingVersion(null)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (pendingVersion) openVersion(pendingVersion);
                                setPendingVersion(null);
                            }}
                        >
                            Discard edits
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
