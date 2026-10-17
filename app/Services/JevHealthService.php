<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class JevHealthService
{
    public function __construct(
        private AiSettings $settings,
        private InstitutionPolicyWorkspace $workspace,
    ) {}

    /**
     * Inspect both JEV policy engine and AI advisory agent status.
     *
     * @return array{
     *     jev: array{
     *         status: string,
     *         policy_id: int|null,
     *         policy_title: string,
     *         policy_digest: string|null,
     *         has_active_controls: bool,
     *         categories_count: int,
     *         clauses_count: int,
     *         published_at: string|null,
     *         message: string
     *     },
     *     ai: array{
     *         status: string,
     *         provider: string,
     *         model: string,
     *         endpoint: string,
     *         advisory_enabled: bool,
     *         disclosure_accepted: bool,
     *         may_call_provider: bool,
     *         latency_ms: int|null,
     *         message: string,
     *         tested_at: string|null
     *     },
     *     timestamp: string
     * }
     */
    public function inspect(Organization $institution, bool $forcePing = false): array
    {
        $jev = $this->inspectJev($institution);
        $ai = $this->inspectAi($forcePing);

        return [
            'jev' => $jev,
            'ai' => $ai,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Inspect JEV policy engine status.
     *
     * @return array<string, mixed>
     */
    public function inspectJev(Organization $institution): array
    {
        $current = $this->workspace->current();

        if ($current instanceof InstitutionPolicyPublication) {
            $version = $current->version;
            $categories = is_array($version->content['categories'] ?? null) ? $version->content['categories'] : [];
            $clauses = is_array($version->content['clauses'] ?? null) ? $version->content['clauses'] : [];

            return [
                'status' => 'online',
                'policy_id' => $version->id,
                'policy_title' => $version->title,
                'policy_digest' => $current->publication_digest,
                'has_active_controls' => $current->hasActiveControls(),
                'categories_count' => count($categories),
                'clauses_count' => count($clauses),
                'published_at' => $current->created_at?->toIso8601String(),
                'message' => 'Active policy published and enforcing controls.',
            ];
        }

        $latestDraft = InstitutionPolicyVersion::query()
            ->where('organization_id', $institution->id)
            ->latest('id')
            ->first();

        if ($latestDraft instanceof InstitutionPolicyVersion && $latestDraft->hasValidContent()) {
            $categories = is_array($latestDraft->content['categories'] ?? null) ? $latestDraft->content['categories'] : [];
            $clauses = is_array($latestDraft->content['clauses'] ?? null) ? $latestDraft->content['clauses'] : [];

            return [
                'status' => 'draft_only',
                'policy_id' => $latestDraft->id,
                'policy_title' => $latestDraft->title,
                'policy_digest' => $latestDraft->content_digest,
                'has_active_controls' => false,
                'categories_count' => count($categories),
                'clauses_count' => count($clauses),
                'published_at' => null,
                'message' => 'Policy revision is in draft mode. Simulator will test draft rules.',
            ];
        }

        return [
            'status' => 'no_policy',
            'policy_id' => null,
            'policy_title' => 'No Policy Published',
            'policy_digest' => null,
            'has_active_controls' => false,
            'categories_count' => 0,
            'clauses_count' => 0,
            'published_at' => null,
            'message' => 'No institution policy created yet. Choose a template or create rules.',
        ];
    }

    /**
     * Inspect AI Agent connectivity and configuration.
     *
     * @return array<string, mixed>
     */
    public function inspectAi(bool $forcePing = false): array
    {
        $provider = (string) config('ai.default', 'local');
        $endpoint = (string) config("ai.providers.{$provider}.url", config('ai.providers.local.url', 'http://localhost:11434/v1'));
        $model = (string) config("ai.providers.{$provider}.models.text.default", config('ai.providers.local.models.text.default', 'ag/gemini-3.8-flash-high'));
        $key = (string) config("ai.providers.{$provider}.key", config('ai.providers.local.key', ''));

        $advisoryEnabled = $this->settings->advisory_enabled;
        $disclosureAccepted = $this->settings->disclosure_accepted;
        $mayCall = $this->settings->mayCallProvider();

        if (! $mayCall) {
            return [
                'status' => 'disabled',
                'provider' => $provider,
                'model' => $model,
                'endpoint' => $endpoint,
                'advisory_enabled' => $advisoryEnabled,
                'disclosure_accepted' => $disclosureAccepted,
                'may_call_provider' => false,
                'latency_ms' => null,
                'message' => 'AI Advisory is disabled in settings. Enable disclosure to activate.',
                'tested_at' => now()->toIso8601String(),
            ];
        }

        if ($endpoint === '' || $endpoint === '0') {
            return [
                'status' => 'misconfigured',
                'provider' => $provider,
                'model' => $model,
                'endpoint' => $endpoint,
                'advisory_enabled' => $advisoryEnabled,
                'disclosure_accepted' => $disclosureAccepted,
                'may_call_provider' => true,
                'latency_ms' => null,
                'message' => 'AI endpoint URL is not configured.',
                'tested_at' => now()->toIso8601String(),
            ];
        }

        $cacheKey = 'jev_ai_health_ping_'.md5($endpoint.$model);

        if (! $forcePing && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return array_merge($cached, [
                    'provider' => $provider,
                    'model' => $model,
                    'endpoint' => $endpoint,
                    'advisory_enabled' => $advisoryEnabled,
                    'disclosure_accepted' => $disclosureAccepted,
                    'may_call_provider' => true,
                ]);
            }
        }

        // Run live ping
        $ping = $this->pingEndpoint($endpoint, $model, $key);
        Cache::put($cacheKey, $ping, now()->addMinutes(2));

        return array_merge($ping, [
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $endpoint,
            'advisory_enabled' => $advisoryEnabled,
            'disclosure_accepted' => $disclosureAccepted,
            'may_call_provider' => true,
        ]);
    }

    /**
     * Perform live probe against OpenAI-compatible endpoint.
     *
     * @return array{status: string, latency_ms: int|null, message: string, tested_at: string}
     */
    public function pingEndpoint(string $endpoint, string $model, string $key): array
    {
        $start = microtime(true);
        $timeout = max(15, $this->settings->timeout_seconds);

        try {
            $client = Http::timeout($timeout);
            if ($key !== '' && $key !== '0') {
                $client = $client->withToken($key);
            }

            $url = rtrim($endpoint, '/').'/chat/completions';
            $response = $client->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'ping'],
                ],
                'max_tokens' => 1,
                'stream' => false,
            ]);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return [
                    'status' => 'online',
                    'latency_ms' => $latency,
                    'message' => "AI Agent responded successfully ({$latency}ms). Model {$model} is ready.",
                    'tested_at' => now()->toIso8601String(),
                ];
            }

            return [
                'status' => 'degraded',
                'latency_ms' => $latency,
                'message' => "Endpoint responded with HTTP {$response->status()}.",
                'tested_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status' => 'offline',
                'latency_ms' => null,
                'message' => 'Connection failed: '.$e->getMessage(),
                'tested_at' => now()->toIso8601String(),
            ];
        }
    }
}
