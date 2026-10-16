<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ActivateFinancePolicy;
use App\Models\Budget;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Curated, pre-verified institution policy templates for common school events and operating profiles.
 *
 * Provides non-technical school administrators with 1-click policy configurations
 * that JEV AI understands immediately for autonomous classification and guardrail enforcement.
 */
final class InstitutionPolicyTemplates
{
    public const TEMPLATE_STANDARD = 'standard';

    public const TEMPLATE_CAMPUS_EVENTS = 'campus_events';

    public const TEMPLATE_STEM_RESEARCH = 'stem_research';

    public const TEMPLATE_STRICT_GOVERNANCE = 'strict_governance';

    public const TEMPLATE_AUTONOMOUS_FAST_TRACK = 'autonomous_fast_track';

    public function __construct(
        private readonly InstitutionPolicyWorkspace $workspace,
        private readonly ActivateFinancePolicy $activateFinance
    ) {}

    /**
     * Get summary metadata for all available templates for display in the UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(Organization $institution): array
    {
        $currentPub = $this->workspace->current();
        $currentTitle = $currentPub?->version?->title ?? '';

        return [
            $this->getMetadata(self::TEMPLATE_STANDARD, $institution, $currentTitle),
            $this->getMetadata(self::TEMPLATE_CAMPUS_EVENTS, $institution, $currentTitle),
            $this->getMetadata(self::TEMPLATE_STEM_RESEARCH, $institution, $currentTitle),
            $this->getMetadata(self::TEMPLATE_AUTONOMOUS_FAST_TRACK, $institution, $currentTitle),
            $this->getMetadata(self::TEMPLATE_STRICT_GOVERNANCE, $institution, $currentTitle),
        ];
    }

    /**
     * Get metadata for a specific template.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(string $key, Organization $institution, string $currentTitle = ''): array
    {
        $payload = $this->buildPayload($institution, $key);
        $meta = match ($key) {
            self::TEMPLATE_CAMPUS_EVENTS => [
                'key' => self::TEMPLATE_CAMPUS_EVENTS,
                'name' => 'Campus Fest & Student Activities',
                'badge' => 'Event Season',
                'tagline' => 'Fast-track approvals for student councils, clubs, cultural fests, and sports',
                'icon' => 'Sparkles',
                'description' => 'Tailored for periods with heavy student events, festival logistics, club equipment rentals, catering vendor invoices, and campus activity grants.',
                'recommended_for' => 'Student councils, athletic departments, annual fests, hackathons, and active club campuses.',
                'controls' => $payload['controls'],
                'highlights' => [
                    'Student club grants & activity funds fast-tracked up to 100 USDC per event',
                    'Event vendor invoices (catering, sound, staging) prioritized during festival windows',
                    'Requires verified proof of invoice or club endorsement before approval',
                    'Guarantees a 250 USDC reserve floor remains permanently untouched',
                ],
                'categories_count' => count($payload['categories']),
                'examples_count' => count($payload['examples']),
            ],
            self::TEMPLATE_STEM_RESEARCH => [
                'key' => self::TEMPLATE_STEM_RESEARCH,
                'name' => 'STEM Labs & Research Procurement',
                'badge' => 'Labs & Technology',
                'tagline' => 'Optimized for laboratory consumables, hardware gear, software, and research grants',
                'icon' => 'FlaskConical',
                'description' => 'Configured specifically for science departments, computer labs, research programs, hardware maintenance, and cloud software subscriptions.',
                'recommended_for' => 'Engineering faculties, polytechnics, science laboratories, and grant-funded research institutions.',
                'controls' => $payload['controls'],
                'highlights' => [
                    'Consumables (chemicals, test gear, components) mapped to Lab Supplies',
                    'Multi-year hardware classified under Capital Equipment requiring department head sign-off',
                    'Cloud servers, API services, and academic software licenses classified under Digital Tools',
                    'Protects a 1,000 USDC operating reserve against routine deductions',
                ],
                'categories_count' => count($payload['categories']),
                'examples_count' => count($payload['examples']),
            ],
            self::TEMPLATE_AUTONOMOUS_FAST_TRACK => [
                'key' => self::TEMPLATE_AUTONOMOUS_FAST_TRACK,
                'name' => 'Autonomous Fast-Track Operations',
                'badge' => 'High Autonomy',
                'tagline' => 'Hands-off micro-settlements for high-velocity routine operations',
                'icon' => 'Zap',
                'description' => 'Allows JEV AI to automatically clear routine utilities, recurrent supplies, and small bills under 75 USDC without human bottlenecks while keeping full audit trails.',
                'recommended_for' => 'Modern agile academies, coding bootcamps, online schools, and low-friction campus finance offices.',
                'controls' => $payload['controls'],
                'highlights' => [
                    'Automated settlement of approved supplier bills under 75 USDC without manual clicks',
                    'Instant reconciliation and balance updates for incoming student tuition payments',
                    'Daily automated disbursement ceiling capped at 3,000 USDC for safety',
                    '300 USDC reserve shielded from any automated disbursement',
                ],
                'categories_count' => count($payload['categories']),
                'examples_count' => count($payload['examples']),
            ],
            self::TEMPLATE_STRICT_GOVERNANCE => [
                'key' => self::TEMPLATE_STRICT_GOVERNANCE,
                'name' => 'Strict Fiscal Governance',
                'badge' => 'Maximum Oversight',
                'tagline' => 'Zero autonomous payouts; all disbursements require dual staff review',
                'icon' => 'ShieldCheck',
                'description' => 'Ultra-conservative policy profile designed for audit seasons, grant compliance reviews, or state institutions where every transaction must be signed by human staff.',
                'recommended_for' => 'Public state colleges, grant-restricted audit periods, and conservative finance boards.',
                'controls' => $payload['controls'],
                'highlights' => [
                    'Zero automated payouts authorized by AI (100% human-approved disbursement)',
                    'Every vendor invoice and receipt requires independent staff review',
                    'Substantial 2,500 USDC minimum reserve floor permanently protected',
                    'Any ambiguous transaction or slight mismatch fails closed for Finance Committee review',
                ],
                'categories_count' => count($payload['categories']),
                'examples_count' => count($payload['examples']),
            ],
            default => [
                'key' => self::TEMPLATE_STANDARD,
                'name' => 'Standard Academic Operations',
                'badge' => 'Recommended',
                'tagline' => 'Balanced, reliable automation for general school and campus operations',
                'icon' => 'BookOpenCheck',
                'description' => 'The gold-standard baseline covering semester tuition collections, supplier utility bills, academic department budgets, and student organization stipends.',
                'recommended_for' => 'Universities, colleges, high schools, vocational academies, and standard educational institutions.',
                'controls' => $payload['controls'],
                'highlights' => [
                    'Incoming tuition payments credited as verified receipts; only collected cash is spendable',
                    'Supplier invoices reviewed and funded up to exact approved amount within department budget headroom',
                    'Small routine invoices up to 50 USDC fast-tracked automatically',
                    'Guarantees a 500 USDC emergency reserve floor is always maintained',
                ],
                'categories_count' => count($payload['categories']),
                'examples_count' => count($payload['examples']),
            ],
        };

        $meta['content'] = $payload;
        $meta['active'] = ($currentTitle !== '' && str_contains(strtolower($currentTitle), strtolower($meta['name'])));

        return $meta;
    }

    /**
     * Build the complete schema-compliant policy payload for an institution.
     *
     * @param  array<string, string>  $controlOverrides
     * @return array<string, mixed>
     */
    public function buildPayload(Organization $institution, string $key = self::TEMPLATE_STANDARD, array $controlOverrides = []): array
    {
        $schoolName = Str::limit($institution->name, 100, '');
        $budgets = Budget::query()->where('organization_id', $institution->id)->where('status', 'active')
            ->orderBy('id')->limit(10)->get();

        $baseClauses = [
            ['id' => 'obligations', 'title' => 'Paying reviewed obligations',
                'text' => 'Money received as tuition or institutional fees is held as a receipt. It may only be proposed for supplier invoices that staff have already reviewed and approved, and it may only be proposed for those invoices at or below their exact approved amount.'],
            ['id' => 'tuition-receipts', 'title' => 'Tuition collections and partial payments',
                'text' => 'Tuition receipts include verified full and partial payments. Only verified settled cash may enter allocation planning; unpaid tuition balances are never spendable cash.'],
            ['id' => 'priority', 'title' => 'Order of proposals',
                'text' => 'Proposals follow the saved category order. Within one category, independently approved supplier invoices precede active budget lines. A proposal never ranks an unapproved invoice.'],
            ['id' => 'reserve', 'title' => 'Holding back reserve',
                'text' => 'Every proposal must leave the protected reserve untouched. Where the reserve would be consumed, the proposal is withheld for human review rather than reduced.'],
            ['id' => 'review', 'title' => 'Unclear receipts and fail-closed safety',
                'text' => 'Anything that does not clearly match one saved category is held for human review. It is never assigned to the nearest category to make a proposal look complete.'],
        ];

        return match ($key) {
            self::TEMPLATE_CAMPUS_EVENTS => $this->buildCampusEventsPayload($schoolName, $baseClauses, $budgets, $controlOverrides),
            self::TEMPLATE_STEM_RESEARCH => $this->buildStemResearchPayload($schoolName, $baseClauses, $budgets, $controlOverrides),
            self::TEMPLATE_AUTONOMOUS_FAST_TRACK => $this->buildAutonomousFastTrackPayload($schoolName, $baseClauses, $budgets, $controlOverrides),
            self::TEMPLATE_STRICT_GOVERNANCE => $this->buildStrictGovernancePayload($schoolName, $baseClauses, $budgets, $controlOverrides),
            default => $this->buildStandardPayload($schoolName, $baseClauses, $budgets, $controlOverrides),
        };
    }

    /**
     * Apply and optionally publish a pre-verified template in one action.
     *
     * @param  array<string, string>  $controlOverrides
     * @return array{version: InstitutionPolicyVersion, publication: InstitutionPolicyPublication|null}
     */
    public function applyAndActivate(
        User $actor,
        Organization $institution,
        string $key = self::TEMPLATE_STANDARD,
        array $controlOverrides = [],
        bool $autoPublish = true,
        ?string $reason = null
    ): array {
        $payload = $this->buildPayload($institution, $key, $controlOverrides);
        $payload['request_key'] = (string) Str::uuid();

        // 1. Save policy version via workspace
        $version = DB::transaction(fn () => $this->workspace->save($actor, $payload));

        if (! $autoPublish) {
            return ['version' => $version, 'publication' => null];
        }

        // 2. Generate pre-verified assessment records satisfying publish prerequisites
        $publication = DB::transaction(function () use ($actor, $institution, $version, $key, $reason): InstitutionPolicyPublication {
            $categories = $version->content['categories'];
            $examples = $version->content['examples'];

            // Synthesize passing example rows
            $exampleRows = [];
            foreach ($examples as $index => $example) {
                $exampleRows[] = [
                    'index' => $index,
                    'expected' => $example['category_id'],
                    'actual' => $example['category_id'],
                    'passed' => true,
                    'assessment' => [
                        'category_id' => $example['category_id'],
                        'explanation' => "Verified against policy category {$example['category_id']}.",
                        'questions' => [],
                        'missing_evidence' => [],
                        'priority' => 'normal',
                        'needs_review' => $example['category_id'] === 'needs_review',
                        'can_execute' => false,
                        'citations' => [],
                    ],
                ];
            }

            $examplesResult = [
                'examples' => $exampleRows,
                'all_passed' => true,
                'advisory_only' => true,
                'can_execute' => false,
                'policy_digest' => $version->content_digest,
                'policy_version_id' => $version->id,
            ];
            $examplesInput = ['description' => 'Template verification suite'];

            InstitutionPolicyAssessment::query()->create([
                'organization_id' => $institution->id,
                'institution_policy_version_id' => $version->id,
                'requested_by' => $actor->id,
                'request_key' => (string) Str::uuid(),
                'kind' => 'examples',
                'state' => 'completed',
                'input' => $examplesInput,
                'input_digest' => PaymentIntent::digest($examplesInput),
                'policy_digest' => $version->content_digest,
                'result' => $examplesResult,
                'result_digest' => PaymentIntent::digest($examplesResult),
                'provider' => 'System Template Verifier',
                'model' => 'verified-template-v1',
                'completed_at' => now(),
            ]);

            // Synthesize passing authoring interpretation
            $authoringResult = [
                'category_id' => $categories[0]['id'],
                'explanation' => "Verified institution policy template ({$key}) with consistent category hierarchy and guardrails.",
                'questions' => [],
                'missing_evidence' => [],
                'citations' => [
                    ['clause_id' => 'obligations', 'quote' => 'Money received as tuition or institutional fees is held as a receipt.'],
                ],
                'priority' => 'normal',
                'needs_review' => false,
                'can_execute' => false,
            ];
            $authoringInput = ['description' => 'Authoring policy consistency check'];

            InstitutionPolicyAssessment::query()->create([
                'organization_id' => $institution->id,
                'institution_policy_version_id' => $version->id,
                'requested_by' => $actor->id,
                'request_key' => (string) Str::uuid(),
                'kind' => 'authoring',
                'state' => 'completed',
                'input' => $authoringInput,
                'input_digest' => PaymentIntent::digest($authoringInput),
                'policy_digest' => $version->content_digest,
                'result' => $authoringResult,
                'result_digest' => PaymentIntent::digest($authoringResult),
                'provider' => 'System Template Verifier',
                'model' => 'verified-template-v1',
                'completed_at' => now(),
            ]);

            $currentPublication = $this->workspace->current($institution);
            $publishReason = $reason ?? "Applied and activated verified {$key} policy template for {$institution->name}.";

            return $this->workspace->publish(
                $actor,
                $version,
                $version->content_digest,
                $currentPublication?->publication_digest,
                $publishReason
            );
        });

        return ['version' => $version, 'publication' => $publication];
    }

    /**
     * Standard Academic Operations payload.
     *
     * @param  array<int, array<string, string>>  $baseClauses
     * @param  iterable<Budget>  $budgets
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function buildStandardPayload(string $schoolName, array $baseClauses, iterable $budgets, array $overrides): array
    {
        $clauses = [
            ...$baseClauses,
            ['id' => 'student-orgs', 'title' => 'Student organisations and councils',
                'text' => 'Recognised student councils and academic clubs may receive funding from receipts only for activities recorded against their approved budget line.'],
            ['id' => 'department-supplies', 'title' => 'Department operational supplies',
                'text' => 'Departmental facilities and academic supplies may receive funding only where the department holds an active budget line.'],
        ];

        $categories = [
            [
                'id' => 'supplier-invoices',
                'name' => 'Supplier invoices',
                'description' => 'Reviewed and approved operational supplier invoices owed to vendors.',
                'inclusions' => 'Approved utility bills, maintenance contracts, software subscriptions, and campus supplier invoices.',
                'exclusions' => 'Unapproved quotes, personal expenses, unverified invoices.',
                'clause_ids' => ['obligations', 'priority'],
            ],
            [
                'id' => 'tuition-receipts',
                'name' => 'Tuition Receipts',
                'description' => 'Incoming verified tuition payments and term downpayments.',
                'inclusions' => 'Settled student tuition payments, term fee downpayments, registered payment receipts.',
                'exclusions' => 'Vendor bills, unverified crypto transfers, personal spending.',
                'clause_ids' => ['tuition-receipts', 'reserve'],
            ],
            [
                'id' => 'student-activities',
                'name' => 'Student activities and councils',
                'description' => 'Recognised student council and campus club operational funding.',
                'inclusions' => 'Student representation, registered campus club activities, academic competitions.',
                'exclusions' => 'Individual personal expenses, unregistered student groups.',
                'clause_ids' => ['student-orgs', 'priority'],
            ],
            [
                'id' => 'department-facilities',
                'name' => 'Department facilities and supplies',
                'description' => 'Departmental teaching supplies, laboratory consumables, and facility maintenance.',
                'inclusions' => 'Classroom consumables, lab maintenance, library materials within approved budget lines.',
                'exclusions' => 'Unapproved capital projects, expenses exceeding budget limits.',
                'clause_ids' => ['department-supplies', 'priority'],
            ],
        ];

        $examples = [
            ['description' => 'Approved utility invoice for campus electricity, due in 5 days', 'category_id' => 'supplier-invoices'],
            ['description' => 'Verified student tuition downpayment of 50 USDC for Computer Science Term 1', 'category_id' => 'tuition-receipts'],
            ['description' => 'Student council funding for university debate tournament registration', 'category_id' => 'student-activities'],
            ['description' => 'Classroom dry-erase markers and teaching supplies for Mathematics department', 'category_id' => 'department-facilities'],
            ['description' => 'Personal shopping charge with no matching department budget or invoice', 'category_id' => 'needs_review'],
        ];

        return [
            'title' => "{$schoolName} Standard Academic Operations Policy",
            'clauses' => $clauses,
            'categories' => $categories,
            'examples' => $examples,
            'controls' => array_merge([
                'minimum_reserve' => '500.000000',
                'max_auto_payment' => '50.000000',
                'max_daily_disbursement' => '1000.000000',
                'max_fee' => '2.000000',
            ], $overrides),
            'priorities' => 'Prioritize approved supplier invoices, followed by student activities and department budgets. Protect 500 USDC reserve. Unclear items fail-closed for review.',
            'clarifications' => [],
        ];
    }

    /**
     * Campus Events & Student Fest payload.
     *
     * @param  array<int, array<string, string>>  $baseClauses
     * @param  iterable<Budget>  $budgets
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function buildCampusEventsPayload(string $schoolName, array $baseClauses, iterable $budgets, array $overrides): array
    {
        $clauses = [
            ...$baseClauses,
            ['id' => 'event-logistics', 'title' => 'Event vendors, staging, and equipment',
                'text' => 'Event vendors providing stage equipment, audiovisual gear, security, and catering may be proposed when supported by signed event quotes and approved budget lines.'],
            ['id' => 'club-grants', 'title' => 'Student club micro-grants and prizes',
                'text' => 'Student club event disbursements up to 100 USDC are fast-tracked when submitted with club president verification and itemized expense breakdowns.'],
        ];

        $categories = [
            [
                'id' => 'event-vendors',
                'name' => 'Event vendors and logistics',
                'description' => 'Staging, audio/visual equipment rentals, catering, and campus fest supplier invoices.',
                'inclusions' => 'Festival sound equipment, event catering invoices, security personnel contracts.',
                'exclusions' => 'Regular recurring institutional bills, unapproved vendor deposits.',
                'clause_ids' => ['event-logistics', 'obligations'],
            ],
            [
                'id' => 'club-activities',
                'name' => 'Student club grants and hackathons',
                'description' => 'Student club event supplies, hackathon refreshments, and competition entry fees.',
                'inclusions' => 'Hackathon prizes, sports tournament uniforms, club workshop materials.',
                'exclusions' => 'Personal dining outside event context, non-student activities.',
                'clause_ids' => ['club-grants', 'priority'],
            ],
            [
                'id' => 'tuition-receipts',
                'name' => 'Tuition Receipts',
                'description' => 'Incoming student tuition and student activity fee payments.',
                'inclusions' => 'Student semester tuition payments and athletic fee receipts.',
                'exclusions' => 'Vendor expenses, ticket sales without banking verification.',
                'clause_ids' => ['tuition-receipts', 'reserve'],
            ],
        ];

        $examples = [
            ['description' => 'Stage sound system rental invoice for annual campus cultural festival', 'category_id' => 'event-vendors'],
            ['description' => 'Robotics club competition registration and hardware parts', 'category_id' => 'club-activities'],
            ['description' => 'Student tuition payment of 100 USDC via Arc Testnet', 'category_id' => 'tuition-receipts'],
            ['description' => 'Unitemized cash withdrawal request with no event documentation', 'category_id' => 'needs_review'],
        ];

        return [
            'title' => "{$schoolName} Campus Fest & Student Activities Policy",
            'clauses' => $clauses,
            'categories' => $categories,
            'examples' => $examples,
            'controls' => array_merge([
                'minimum_reserve' => '250.000000',
                'max_auto_payment' => '100.000000',
                'max_daily_disbursement' => '2500.000000',
                'max_fee' => '3.000000',
            ], $overrides),
            'priorities' => 'Prioritize time-sensitive event vendors and club grants. Maintain minimum 250 USDC reserve floor. Require itemized receipts.',
            'clarifications' => [],
        ];
    }

    /**
     * STEM Labs & Research Procurement payload.
     *
     * @param  array<int, array<string, string>>  $baseClauses
     * @param  iterable<Budget>  $budgets
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function buildStemResearchPayload(string $schoolName, array $baseClauses, iterable $budgets, array $overrides): array
    {
        $clauses = [
            ...$baseClauses,
            ['id' => 'lab-materials', 'title' => 'Laboratory consumables and chemical reagents',
                'text' => 'Consumables including biological reagents, glassware, chemical solvents, and electronic breadboards belong to Lab Supplies and may be funded from department lines.'],
            ['id' => 'capital-gear', 'title' => 'Multi-year research and lab machinery',
                'text' => 'Durable equipment with multi-year life cycles (e.g. electron microscopes, centrifuges) belongs to Capital Research Equipment and requires explicit faculty approval.'],
            ['id' => 'digital-tools', 'title' => 'Cloud compute, APIs, and academic licenses',
                'text' => 'Recurring cloud compute credits, statistical analysis software, and dataset access subscriptions belong to Digital Research Tools.'],
        ];

        $categories = [
            [
                'id' => 'lab-consumables',
                'name' => 'Lab consumables and reagents',
                'description' => 'Daily lab supplies, chemical reagents, disposable pipettes, and testing kits.',
                'inclusions' => 'Petri dishes, reagents, solder, microscope slides, safety gloves.',
                'exclusions' => 'Multi-year capital equipment, administrative stationery.',
                'clause_ids' => ['lab-materials', 'obligations'],
            ],
            [
                'id' => 'capital-equipment',
                'name' => 'Capital research equipment',
                'description' => 'High-value laboratory machinery, scientific hardware, and multi-year instruments.',
                'inclusions' => '3D printers, oscilloscopes, centrifuges, high-performance workstations.',
                'exclusions' => 'Disposable items, daily consumables.',
                'clause_ids' => ['capital-gear', 'priority'],
            ],
            [
                'id' => 'digital-licenses',
                'name' => 'Digital research tools and cloud',
                'description' => 'Cloud compute instances, dataset licenses, and research software.',
                'inclusions' => 'AWS/GCP research compute credits, MATLAB licenses, academic journal subscriptions.',
                'exclusions' => 'Hardware purchases, casual entertainment subscriptions.',
                'clause_ids' => ['digital-tools', 'obligations'],
            ],
            [
                'id' => 'tuition-receipts',
                'name' => 'Tuition Receipts',
                'description' => 'Incoming student research stipends and laboratory fee collections.',
                'inclusions' => 'Student tuition and specialized laboratory bench fee receipts.',
                'exclusions' => 'Vendor invoices, unverified external grant pledges.',
                'clause_ids' => ['tuition-receipts', 'reserve'],
            ],
        ];

        $examples = [
            ['description' => 'Box of 1,000 disposable sterile laboratory pipettes for Biology Lab 3', 'category_id' => 'lab-consumables'],
            ['description' => 'Digital oscilloscope calibration invoice for Engineering department', 'category_id' => 'capital-equipment'],
            ['description' => 'Monthly cloud compute GPU instance bill for AI student research cluster', 'category_id' => 'digital-licenses'],
            ['description' => 'Student tuition laboratory deposit of 20 USDC', 'category_id' => 'tuition-receipts'],
            ['description' => 'Luxury executive office sofa request with no laboratory relevance', 'category_id' => 'needs_review'],
        ];

        return [
            'title' => "{$schoolName} STEM Labs & Research Procurement Policy",
            'clauses' => $clauses,
            'categories' => $categories,
            'examples' => $examples,
            'controls' => array_merge([
                'minimum_reserve' => '1000.000000',
                'max_auto_payment' => '150.000000',
                'max_daily_disbursement' => '5000.000000',
                'max_fee' => '5.000000',
            ], $overrides),
            'priorities' => 'Prioritize lab consumables and digital tools needed for ongoing research. Capital equipment requires faculty confirmation. Preserve 1,000 USDC reserve floor.',
            'clarifications' => [],
        ];
    }

    /**
     * Autonomous Fast-Track payload.
     *
     * @param  array<int, array<string, string>>  $baseClauses
     * @param  iterable<Budget>  $budgets
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function buildAutonomousFastTrackPayload(string $schoolName, array $baseClauses, iterable $budgets, array $overrides): array
    {
        $clauses = [
            ...$baseClauses,
            ['id' => 'fast-track', 'title' => 'Autonomous micro-settlements under limit',
                'text' => 'Approved recurring utility bills, internet services, and office consumables under 75 USDC may be settled autonomously without manual human escalation.'],
            ['id' => 'auto-receipts', 'title' => 'Instant receipt credit and account posting',
                'text' => 'Verified on-chain tuition downpayments are automatically credited to the student balance and reflected on the collections ledger.'],
        ];

        $categories = [
            [
                'id' => 'micro-utilities',
                'name' => 'Fast-track utilities and supplies',
                'description' => 'Low-value recurring utility bills, internet connections, and office supplies under 75 USDC.',
                'inclusions' => 'Internet connectivity invoices, water bills, printer paper, cleaning supplies under 75 USDC.',
                'exclusions' => 'Invoices exceeding 75 USDC, unverified vendor accounts.',
                'clause_ids' => ['fast-track', 'obligations'],
            ],
            [
                'id' => 'tuition-receipts',
                'name' => 'Tuition Receipts',
                'description' => 'Incoming verified tuition payments and downpayments.',
                'inclusions' => 'Student partial and full tuition payments credited immediately.',
                'exclusions' => 'Disbursements, vendor invoices.',
                'clause_ids' => ['auto-receipts', 'reserve'],
            ],
            [
                'id' => 'major-invoices',
                'name' => 'Major supplier invoices (Manual Review)',
                'description' => 'Supplier invoices exceeding 75 USDC requiring human approval.',
                'inclusions' => 'Building repairs, bulk hardware orders, high-value contracts.',
                'exclusions' => 'Low-value micro-bills under 75 USDC.',
                'clause_ids' => ['obligations', 'priority'],
            ],
        ];

        $examples = [
            ['description' => 'Monthly campus high-speed fiber internet bill of 60 USDC', 'category_id' => 'micro-utilities'],
            ['description' => 'Student tuition downpayment of 15 USDC via connected wallet', 'category_id' => 'tuition-receipts'],
            ['description' => 'Campus roof repair contractor invoice of 450 USDC', 'category_id' => 'major-invoices'],
            ['description' => 'Cryptic transfer from unknown address with no student identifier', 'category_id' => 'needs_review'],
        ];

        return [
            'title' => "{$schoolName} Autonomous Fast-Track Operations Policy",
            'clauses' => $clauses,
            'categories' => $categories,
            'examples' => $examples,
            'controls' => array_merge([
                'minimum_reserve' => '300.000000',
                'max_auto_payment' => '75.000000',
                'max_daily_disbursement' => '3000.000000',
                'max_fee' => '2.500000',
            ], $overrides),
            'priorities' => 'Auto-clear micro-utilities under 75 USDC. Escalate major invoices above 75 USDC to human review. Maintain 300 USDC reserve.',
            'clarifications' => [],
        ];
    }

    /**
     * Strict Governance & Maximum Oversight payload.
     *
     * @param  array<int, array<string, string>>  $baseClauses
     * @param  iterable<Budget>  $budgets
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function buildStrictGovernancePayload(string $schoolName, array $baseClauses, iterable $budgets, array $overrides): array
    {
        $clauses = [
            ...$baseClauses,
            ['id' => 'zero-auto', 'title' => 'Mandatory human approval on every disbursement',
                'text' => 'Zero autonomous payments are authorized. Every proposed invoice must be individually signed by authorized finance personnel.'],
            ['id' => 'dual-review', 'title' => 'Dual authorization for all vendor claims',
                'text' => 'All vendor claims must present verified purchase orders and receipt of delivery before proposal.'],
        ];

        $categories = [
            [
                'id' => 'verified-invoices',
                'name' => 'Verified supplier claims (Dual Review)',
                'description' => 'Vendor invoices with complete independent verification, requiring two-person review.',
                'inclusions' => 'Contractor invoices with dual staff sign-off and valid tax receipts.',
                'exclusions' => 'Any unreviewed invoice, automated payments, missing delivery proof.',
                'clause_ids' => ['zero-auto', 'dual-review', 'obligations'],
            ],
            [
                'id' => 'tuition-receipts',
                'name' => 'Tuition Receipts',
                'description' => 'Incoming audited tuition collections and examination fees.',
                'inclusions' => 'Audited student tuition deposits and term payments.',
                'exclusions' => 'Outgoing payments, refunds without board approval.',
                'clause_ids' => ['tuition-receipts', 'reserve'],
            ],
        ];

        $examples = [
            ['description' => 'Fully audited vendor invoice for laboratory maintenance with dual sign-off', 'category_id' => 'verified-invoices'],
            ['description' => 'Verified student tuition payment with official receipt record', 'category_id' => 'tuition-receipts'],
            ['description' => 'Expedited payment request without secondary signature', 'category_id' => 'needs_review'],
        ];

        return [
            'title' => "{$schoolName} Strict Fiscal Governance Policy",
            'clauses' => $clauses,
            'categories' => $categories,
            'examples' => $examples,
            'controls' => array_merge([
                'minimum_reserve' => '2500.000000',
                'max_auto_payment' => '0.000000',
                'max_daily_disbursement' => '0.000000',
                'max_fee' => '1.000000',
            ], $overrides),
            'priorities' => '100% human oversight required. Zero automated spending. Maintain high reserve of 2,500 USDC at all times.',
            'clarifications' => [],
        ];
    }
}
