<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstitutionPolicyRequest;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\PaymentIntent;
use App\Services\Advisory\JevPolicyClassifier;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyDocuments;
use App\Services\InstitutionPolicyTemplates;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InstitutionPolicyController extends Controller
{
    public function import(Request $request, InstitutionPolicyDocuments $documents): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', InstitutionPolicyVersion::class);
        $request->validate(['document' => ['required', 'file', 'max:2048', 'extensions:txt,md,pdf', 'mimetypes:text/plain,text/markdown,application/pdf']]);

        return response()->json(['data' => $documents->extract($request->file('document'))])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, InstallationInstitution $institutions, InstitutionPolicyWorkspace $workspace, AiSettings $settings, InstitutionPolicyTemplates $templates): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', InstitutionPolicyVersion::class);
        $institution = $institutions->require();
        $versions = InstitutionPolicyVersion::query()->where('organization_id', $institution->id)->latest('id')->limit(100)->get();
        $current = $workspace->current();
        // Automated advice is shown in its own section. Mixing it into the
        // playground list would let a background bill classification answer
        // for the description the reviewer typed, which they never saw.
        $advice = InstitutionPolicyAssessment::query()->where('organization_id', $institution->id)->whereNull('requested_by')->latest('id')->limit(50)->get();
        // The playground answers "what did the agent make of MY description",
        // so it is scoped to the signed-in author. Another reviewer's run
        // describes evidence this person never typed.
        $playground = InstitutionPolicyAssessment::query()->where('organization_id', $institution->id)
            ->where('requested_by', $request->user()->id)->latest('id')->limit(50)->get();

        return Inertia::render('finance/policies/index', [
            'institution' => ['id' => $institution->id, 'name' => $institution->name],
            'versions' => $versions->map(fn (InstitutionPolicyVersion $version): array => $version->only(['id', 'title', 'created_by', 'content', 'content_digest', 'created_at']))->all(),
            'current' => $current instanceof InstitutionPolicyPublication ? [...$current->only(['institution_policy_version_id', 'publication_digest', 'approved_by']),
                'controls_active' => $current->hasActiveControls()] : null,
            'assessments' => $playground,
            'planAdvice' => $advice,
            'templates' => $templates->all($institution),
            'financeUrl' => url('/finance'),
            'queueReady' => in_array(config('queue.connections.'.config('queue.default', 'database').'.driver'), ['database', 'redis'], true)
                && (int) config('queue.connections.'.config('queue.default', 'database').'.retry_after', 0) > 180,
            'canPublish' => $request->user()->hasAnyRole(['admin', 'super_admin']),
            'aiEnabled' => $settings->mayCallProvider(),
        ]);
    }

    public function store(StoreInstitutionPolicyRequest $request, InstitutionPolicyWorkspace $workspace): JsonResponse
    {
        $version = $workspace->save($request->user(), $request->validated());

        return response()->json(['data' => $version->only(['id', 'title', 'created_by', 'content', 'content_digest', 'created_at'])])->header('Cache-Control', 'no-store, private');
    }

    public function publish(Request $request, InstitutionPolicyVersion $institutionPolicyVersion, InstitutionPolicyWorkspace $workspace): JsonResponse
    {
        Gate::forUser($request->user())->authorize('publish', $institutionPolicyVersion);
        $data = $request->validate(['expected_digest' => ['required', 'string', 'size:64'], 'expected_current_digest' => ['nullable', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:1000'], 'attestation' => ['required', 'accepted']]);
        $publication = $workspace->publish($request->user(), $institutionPolicyVersion, $data['expected_digest'], $data['expected_current_digest'] ?? null, $data['reason']);

        return response()->json(['data' => $publication, 'can_execute' => false])->header('Cache-Control', 'no-store, private');
    }

    public function assess(Request $request, InstitutionPolicyVersion $institutionPolicyVersion): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $institutionPolicyVersion);
        $data = $request->validate(['kind' => ['required', Rule::in(['authoring', 'examples', 'classification'])], 'request_key' => ['required', 'uuid'],
            'expected_digest' => ['required', 'string', 'size:64'], 'description' => ['nullable', 'string', 'max:6000']]);
        if ($data['expected_digest'] !== $institutionPolicyVersion->content_digest || ! $institutionPolicyVersion->hasValidContent()) {
            throw ValidationException::withMessages(['assessment' => 'Save and select exact intact policy version before testing.']);
        }
        if ($data['kind'] === 'examples' && ($institutionPolicyVersion->content['examples'] ?? []) === []) {
            throw ValidationException::withMessages(['assessment' => 'Add at least one expected example before testing publication readiness.']);
        }
        $connection = (string) config('queue.default', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 180) {
            throw ValidationException::withMessages(['assessment' => 'Policy assessments require a durable queue with retry_after greater than 180 seconds.']);
        }
        $input = ['description' => $data['description'] ?? ''];
        $assessment = InstitutionPolicyAssessment::query()->firstOrCreate(['request_key' => $data['request_key']], [
            'organization_id' => $institutionPolicyVersion->organization_id, 'institution_policy_version_id' => $institutionPolicyVersion->id,
            'requested_by' => $request->user()->id, 'kind' => $data['kind'], 'input' => $input,
            'policy_digest' => $institutionPolicyVersion->content_digest, 'input_digest' => PaymentIntent::digest($input)]);
        if ($assessment->organization_id !== $institutionPolicyVersion->organization_id || $assessment->institution_policy_version_id !== $institutionPolicyVersion->id
            || $assessment->requested_by !== $request->user()->id || $assessment->kind !== $data['kind'] || $assessment->input_digest !== PaymentIntent::digest($input)) {
            throw ValidationException::withMessages(['assessment' => 'Assessment identity belongs to different evidence or user.']);
        }
        if ($assessment->wasRecentlyCreated) {
            try {
                Bus::dispatch(new AssessInstitutionPolicy($assessment->id));
            } catch (Throwable $exception) {
                report($exception);
                $assessment->update(['state' => 'needs_review', 'last_error' => 'Queue unavailable. Retry with a new assessment; no data was evaluated.', 'completed_at' => now()]);
            }
        }

        return response()->json(['data' => $assessment->fresh()])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Apply and optionally activate a pre-verified template in 1-click.
     */
    public function applyTemplate(
        Request $request,
        InstallationInstitution $institutions,
        InstitutionPolicyTemplates $templates
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', InstitutionPolicyVersion::class);
        $institution = $institutions->require();

        $data = $request->validate([
            'template_key' => ['required', 'string', Rule::in([
                InstitutionPolicyTemplates::TEMPLATE_STANDARD,
                InstitutionPolicyTemplates::TEMPLATE_CAMPUS_EVENTS,
                InstitutionPolicyTemplates::TEMPLATE_STEM_RESEARCH,
                InstitutionPolicyTemplates::TEMPLATE_STRICT_GOVERNANCE,
                InstitutionPolicyTemplates::TEMPLATE_AUTONOMOUS_FAST_TRACK,
            ])],
            'controls' => ['nullable', 'array'],
            'auto_publish' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $autoPublish = (bool) ($data['auto_publish'] ?? true);
        if ($autoPublish && ! $request->user()->hasAnyRole(['admin', 'super_admin'])) {
            throw ValidationException::withMessages([
                'publication' => 'Only administrators and finance officers can activate policy templates.',
            ]);
        }

        $result = $templates->applyAndActivate(
            $request->user(),
            $institution,
            $data['template_key'],
            $data['controls'] ?? [],
            $autoPublish,
            $data['reason'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => $autoPublish
                ? 'Policy template successfully applied and activated for JEV AI.'
                : 'Policy template saved as a draft revision in your workspace.',
            'version' => $result['version']->only(['id', 'title', 'content', 'content_digest', 'created_at']),
            'publication' => $result['publication'] ? [
                'id' => $result['publication']->id,
                'publication_digest' => $result['publication']->publication_digest,
                'controls_active' => $result['publication']->hasActiveControls(),
            ] : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Interactive JEV Policy Decision Simulator for testing transactions in real-time.
     */
    public function simulate(
        Request $request,
        InstallationInstitution $institutions,
        JevPolicyClassifier $classifier,
        AiSettings $settings,
        InstitutionPolicyWorkspace $workspace
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('viewAny', InstitutionPolicyVersion::class);
        $institution = $institutions->require();

        $data = $request->validate([
            'description' => ['required', 'string', 'max:1000'],
            'version_id' => ['nullable', 'integer'],
        ]);

        $policy = null;
        if (! empty($data['version_id'])) {
            $policy = InstitutionPolicyVersion::query()
                ->where('organization_id', $institution->id)
                ->find($data['version_id']);
        }

        if (! $policy) {
            $currentPublication = $workspace->current();
            if ($currentPublication) {
                $policy = $currentPublication->version;
            }
        }

        if (! $policy) {
            $policy = InstitutionPolicyVersion::query()
                ->where('organization_id', $institution->id)
                ->latest('id')
                ->first();
        }

        if (! $policy || ! $policy->hasValidContent()) {
            return response()->json([
                'data' => [
                    'status' => 'needs_review',
                    'category_name' => 'Needs Review',
                    'category_id' => 'needs_review',
                    'confidence' => 100,
                    'explanation' => 'No published institution policy found. JEV fails closed and holds transaction for human review.',
                    'citations' => [],
                    'decision_steps' => [
                        'No active policy in force.',
                        'Fail-closed safety triggered.',
                    ],
                ],
            ]);
        }

        $evidence = [
            'description' => $data['description'],
            'source_stream' => 'simulator',
            'received_minor_units' => 0,
        ];

        // If AI is enabled and configured, run JevPolicyClassifier
        if ($settings->mayCallProvider()) {
            try {
                $result = $classifier->classify($policy, $evidence, 15);

                $categoryName = $result['category_id']
                    ? (collect($policy->content['categories'])->firstWhere('id', $result['category_id'])['name'] ?? $result['category_id'])
                    : 'Needs Review';

                return response()->json([
                    'data' => [
                        'status' => $result['needs_review'] ? 'needs_review' : 'match',
                        'category_name' => $categoryName,
                        'category_id' => $result['category_id'] ?? 'needs_review',
                        'confidence' => $result['confidence'] ?? 85,
                        'explanation' => $result['explanation'] ?? '',
                        'citations' => $result['citations'] ?? [],
                        'decision_steps' => $result['decision_steps'] ?? [],
                    ],
                ]);
            } catch (Throwable) {
                // Fall back to rule-based simulation below
            }
        }

        // Rule-based heuristic simulation matching policy categories
        $text = strtolower($data['description']);
        $categories = $policy->content['categories'] ?? [];
        $matchedCategory = null;
        $highestScore = 0;

        foreach ($categories as $cat) {
            $score = 0;
            $keywords = explode(' ', strtolower($cat['name'].' '.($cat['inclusions'] ?? '').' '.($cat['description'] ?? '')));
            foreach ($keywords as $kw) {
                $kw = trim($kw, " ,;.:'\"");
                if (strlen($kw) > 3 && str_contains($text, $kw)) {
                    $score += 2;
                }
            }

            // Exclusions check
            $exclusions = explode(' ', strtolower($cat['exclusions'] ?? ''));
            foreach ($exclusions as $ex) {
                $ex = trim($ex, " ,;.:'\"");
                if (strlen($ex) > 3 && str_contains($text, $ex)) {
                    $score -= 3;
                }
            }

            if ($score > $highestScore) {
                $highestScore = $score;
                $matchedCategory = $cat;
            }
        }

        if ($matchedCategory && $highestScore >= 2) {
            $clauses = array_column($policy->content['clauses'] ?? [], 'text', 'id');
            $citations = array_map(fn ($id) => ['clause_id' => $id, 'quote' => $clauses[$id] ?? ''], $matchedCategory['clause_ids'] ?? []);

            return response()->json([
                'data' => [
                    'status' => 'match',
                    'category_name' => $matchedCategory['name'],
                    'category_id' => $matchedCategory['id'],
                    'confidence' => min(98, 80 + ($highestScore * 3)),
                    'explanation' => "JEV matches this description to '{$matchedCategory['name']}' based on policy category inclusions.",
                    'citations' => $citations,
                    'decision_steps' => [
                        "Evaluated transaction text against {$policy->title} categories.",
                        "Highest affinity matched category '{$matchedCategory['name']}'.",
                        'Confidence exceeds safety threshold (>= 80%).',
                        "Decision: Transaction routed according to {$matchedCategory['name']} rules.",
                    ],
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'status' => 'needs_review',
                'category_name' => 'Needs Review',
                'category_id' => 'needs_review',
                'confidence' => 90,
                'explanation' => 'JEV did not find a sufficiently clear category match. Held for human review to guarantee financial safety.',
                'citations' => [],
                'decision_steps' => [
                    "Evaluated transaction text against {$policy->title}.",
                    'No category scored above the 80% safety confidence threshold.',
                    'Fail-closed rule invoked.',
                    'Decision: Hold for human review.',
                ],
            ],
        ]);
    }
}
