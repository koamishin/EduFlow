<?php

declare(strict_types=1);

use App\Ai\Agents\AdminAssistantAgent;
use App\Enums\AgentDecisionType;
use App\Filament\Clusters\Settings\Pages\AiSettingsPage;
use App\Filament\Pages\AdminAiChat;
use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Models\AgentDecision;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\CircleWalletService;
use App\Services\InstallationInstitution;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\X402Gateway;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->artisan('migrate', ['--path' => 'database/settings', '--no-interaction' => true]);
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->regularUser = User::factory()->create()->assignRole('user');
    $this->actingAs($this->admin);
    filament()->setCurrentPanel('admin');
    config(['eduflow.institution_id' => null]);

    AdminAssistantAgent::fake();
    Http::preventStrayRequests();

    $resolver = Mockery::mock(new AiProviderResolver(app(AiSettings::class)));
    $resolver->shouldNotReceive('resolve');
    $resolver->shouldNotReceive('buildFromSettings');
    $this->app->instance(AiProviderResolver::class, $resolver);

    foreach ([WalletGateway::class, ArcNetworkGateway::class, X402Gateway::class] as $gateway) {
        $this->app->instance($gateway, Mockery::mock($gateway));
    }

    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    $payments->shouldNotReceive('executePaymentBaseUnits');
    $this->app->instance(CircleWalletService::class, $payments);
});

afterEach(function (): void {
    AdminAssistantAgent::assertNeverPrompted();
    Http::assertNothingSent();
    $this->assertDatabaseCount('transactions', 0);
});

function arcActivityDecision(Organization $institution, string $reasoning): AgentDecision
{
    // No AgentDecision factory exists; seed evidence without running a financial workflow.
    return AgentDecision::create([
        'organization_id' => $institution->id,
        'action_type' => 'student_assistance',
        'input_snapshot' => ['source' => 'arc_activity_regression'],
        'reasoning_summary' => $reasoning,
        'policy_checked' => 'ARC_ACTIVITY_REGRESSION_V1',
        'decision' => AgentDecisionType::ESCALATE,
        'requested_amount' => 15,
        'approved_amount' => 0,
        'requires_approval' => true,
        'status' => 'pending_approval',
    ]);
}

test('arc activity uses the ARC AI title and defaults manual chat off', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));
    $page = Livewire::test(AdminAiChat::class)
        ->assertSuccessful()
        ->assertSetStrict('manualChatEnabled', false)
        ->assertSetStrict('selectedDecisionId', null)
        ->assertSee('ARC AI activity')
        ->assertSee('Manual chat')
        ->assertSeeHtml('manualChatEnabled: false');

    expect($page->instance()->getTitle())->toBe('ARC AI')
        ->and(AdminAiChat::getNavigationLabel())->toBe('ARC AI')
        ->and(AiSettings::defaults()['manual_chat_enabled'])->toBeFalse()
        ->and((new AiSettings)->manual_chat_enabled)->toBeFalse();
    $this->assertDatabaseHas('settings', [
        'group' => 'ai', 'name' => 'manual_chat_enabled', 'payload' => 'false',
    ]);
    $this->assertDatabaseCount('agent_decisions', 0);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
})->with(['admin', 'super_admin']);

test('arc chat and history stay outside polling morphs while activity remains live', function (): void {
    $page = Livewire::test(AdminAiChat::class);

    foreach (range(1, 2) as $refresh) {
        $page->call('$refresh')
            ->assertSeeHtml('<main wire:ignore x-show="viewMode === \'chat\'"')
            ->assertSeeHtml('<div wire:ignore class="flex-1 space-y-3.5 overflow-y-auto pe-1 text-xs">')
            ->assertSeeHtml('<section wire:poll.15s')
            ->assertSeeHtml('aria-label="Recorded decisions"');
    }
});

test('arc streamed turns remain visible through polling morphs in installed browser', function (): void {
    $browser = collect([
        getenv('PROGRAMFILES').'/Google/Chrome/Application/chrome.exe',
        getenv('PROGRAMFILES(X86)').'/Microsoft/Edge/Application/msedge.exe',
    ])->first(fn (string $path): bool => is_file($path));

    if ($browser === null) {
        $this->markTestSkipped('Installed Edge or Chrome required for isolated DOM regression.');
    }

    $html = Livewire::test(AdminAiChat::class)->html();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $chat = (new DOMXPath($document))->query('//*[@x-data]')->item(0);
    expect($chat)->not->toBeNull();
    $chat->setAttribute('x-data', 'arcRegression');
    $chat->removeAttribute('wire:id');
    foreach (['script', 'style'] as $tag) {
        foreach (iterator_to_array($chat->getElementsByTagName($tag)) as $element) {
            $element->parentNode->removeChild($element);
        }
    }
    $chatHtml = $document->saveHTML($chat);
    $runtime = str_replace('window.Livewire = Livewire2;', 'window.arcMorphConfig = getMorphConfig; window.Livewire = Livewire2;', file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js')));
    $chatScript = file_get_contents(resource_path('views/filament/pages/ai-chat-script.blade.php'));
    $setup = <<<'JS'
window.livewireScriptConfig = {};
window.addEventListener('error', event => document.body.setAttribute('data-arc-result', event.message));
window.addEventListener('unhandledrejection', event => document.body.setAttribute('data-arc-result', String(event.reason)));
JS;
    $probe = <<<'JS'
let makeChat;
const originalData = Alpine.data;
Alpine.data = (name, factory) => { makeChat = factory; };
registerAdminAiChat();
Alpine.data = originalData;
Alpine.data('arcRegression', () => ({
    ...makeChat({manualChatEnabled: true, providerCallsAllowed: true, initialSessions: [], adminUser: {name: 'Admin'}, workspace: {name: 'Regression'}}),
    init() {},
    viewMode: 'chat',
    activeSessionId: 7,
    activeSessionUuid: 'regression-session',
    scrollToBottom() {},
    stopSpeaking() {},
    stopVoice() {},
    async loadAiActions() {},
}));
let completeStream;
window.fetch = async () => new Response(new ReadableStream({start(controller) {
    controller.enqueue(new TextEncoder().encode('data: {"type":"text_delta","delta":"Read-only capabilities"}\n\n'));
    completeStream = () => {
        controller.enqueue(new TextEncoder().encode('data: {"type":"text_delta","delta":" after poll"}\n\ndata: [DONE]\n\n'));
        controller.close();
    };
}}));
const root = document.querySelector('[x-data="arcRegression"]');
const serverHtml = root.outerHTML;
Livewire.start();
const flush = () => new Promise(resolve => setTimeout(resolve, 50));
const poll = async () => {
    Alpine.morph(root, serverHtml, window.arcMorphConfig({id: 'regression'}));
    await flush();
};
(async () => {
    const chat = Alpine.$data(root);
    chat.inputMessage = 'hello';
    let pending = chat.sendMessage();
    await flush();
    await poll();
    completeStream();
    await pending;
    await flush();
    await poll();
    chat.inputMessage = 'what can you do';
    pending = chat.sendMessage();
    await flush();
    if (!root.textContent.includes('what can you do')) throw new Error('Submitted message missing before response finishes');
    await poll();
    if (!root.textContent.includes('what can you do')) throw new Error('Submitted message lost during poll');
    completeStream();
    await pending;
    await flush();
    await poll();
    if (root.querySelectorAll('.arc-user-bubble').length !== 2) throw new Error('User messages lost after poll');
    if (!root.textContent.includes('what can you do')) throw new Error('Second turn missing');
    if (root.querySelectorAll('.prose').length !== 2) throw new Error('Assistant messages lost after poll');
    if (root.querySelectorAll('.prose').length !== 2 || [...root.querySelectorAll('.prose')].some(el => !el.textContent.includes('Read-only capabilities after poll'))) throw new Error('Streamed updates missing after poll');
    document.body.setAttribute('data-arc-result', 'passed');
})().catch(error => {document.body.setAttribute('data-arc-result', error.message);});
JS;
    $files = new Filesystem;
    $directory = storage_path('framework/testing/arc-dom-'.bin2hex(random_bytes(8)));
    $files->ensureDirectoryExists($directory);
    $files->put($directory.'/probe.html', '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$chatHtml.'<script>'.$setup.'</script><script>'.$runtime.'</script>'.$chatScript.'<script>'.$probe.'</script></body></html>');

    try {
        $process = new Process([
            $browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--virtual-time-budget=3000', '--dump-dom',
            '--user-data-dir='.$directory.'/profile', 'file:///'.str_replace('\\', '/', $directory.'/probe.html'),
        ]);
        $process->setTimeout(45);
        $process->run();
        expect($process->isSuccessful())->toBeTrue();
        preg_match('/<body[^>]*data-arc-result="([^"]*)"/', $process->getOutput(), $result);
        expect($result[1] ?? substr($process->getOutput().$process->getErrorOutput(), 0, 1500))->toBe('passed');
    } finally {
        $files->deleteDirectory($directory);
    }
});

test('arc composer visibility follows manual toggle independently of provider consent', function (bool $advisory, bool $disclosure): void {
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = $advisory;
    $settings->disclosure_accepted = $disclosure;
    $settings->save();

    $page = Livewire::test(AdminAiChat::class)
        ->call('setManualChatEnabled', true)
        ->assertSetStrict('manualChatEnabled', true)
        ->assertSetStrict('providerCallsAllowed', $advisory && $disclosure)
        ->assertSeeHtml('x-show="manualChatEnabled"')
        ->assertSeeHtml('x-show="messages.length > 0 && manualChatEnabled"')
        ->assertDontSeeHtml('x-show="messages.length > 0 && chatAvailable"')
        ->assertSeeHtml(':disabled="!chatAvailable || !inputMessage.trim() || isStreaming"')
        ->assertSee('Manual chat is on. Sending is disabled until advisory is enabled and the data disclosure is accepted.')
        ->assertSee('Open AI Settings')
        ->assertSeeHtml('href="'.AiSettingsPage::getUrl(panel: 'admin').'"');

    expect(substr_count($page->html(), ':disabled="!manualChatEnabled"'))->toBe(2)
        ->and(substr_count($page->html(), ':disabled="!chatAvailable || !inputMessage.trim() || isStreaming"'))->toBe(2);
    $page->call('setManualChatEnabled', false)->assertSetStrict('manualChatEnabled', false);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
})->with([
    'both off' => [false, false],
    'advisory off' => [false, true],
    'disclosure off' => [true, false],
    'both on' => [true, true],
]);

test('arc manual chat toggle persists across mounts and can be disabled with provider gates off', function (string $role, bool $advisory, bool $disclosure): void {
    $this->actingAs(User::factory()->create()->assignRole($role));
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();

    Livewire::test(AdminAiChat::class)
        ->call('setManualChatEnabled', true)
        ->assertSetStrict('manualChatEnabled', true);
    expect((new AiSettings)->manual_chat_enabled)->toBeTrue();
    $this->assertDatabaseHas('settings', [
        'group' => 'ai', 'name' => 'manual_chat_enabled', 'payload' => 'true',
    ]);

    $settings = app(AiSettings::class);
    $settings->refresh();
    $settings->advisory_enabled = $advisory;
    $settings->disclosure_accepted = $disclosure;
    $settings->save();

    Livewire::test(AdminAiChat::class)
        ->assertSetStrict('manualChatEnabled', true)
        ->call('setManualChatEnabled', false)
        ->assertSetStrict('manualChatEnabled', false);

    $persisted = new AiSettings;
    expect($persisted->manual_chat_enabled)->toBeFalse()
        ->and($persisted->advisory_enabled)->toBe($advisory)
        ->and($persisted->disclosure_accepted)->toBe($disclosure);
    $this->assertDatabaseHas('settings', [
        'group' => 'ai', 'name' => 'manual_chat_enabled', 'payload' => 'false',
    ]);
    Livewire::test(AdminAiChat::class)->assertSetStrict('manualChatEnabled', false);
    $this->assertDatabaseCount('agent_decisions', 0);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
})->with(['admin', 'super_admin'])->with([
    'advisory off' => [false, true],
    'disclosure off' => [true, false],
    'both off' => [false, false],
]);

test('arc manual chat setter denies non-admins after an authorized mount', function (bool $enabled): void {
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = ! $enabled;
    $settings->save();
    $page = Livewire::test(AdminAiChat::class);

    $this->actingAs($this->regularUser);
    $page->call('setManualChatEnabled', $enabled)->assertForbidden();

    expect((new AiSettings)->manual_chat_enabled)->toBe(! $enabled);
    $this->assertDatabaseCount('agent_decisions', 0);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
})->with([true, false]);

test('arc decision selection denies non-admins after an authorized mount', function (): void {
    $institution = Organization::factory()->create();
    $decision = arcActivityDecision($institution, 'Authorized institution evidence');
    $page = Livewire::test(AdminAiChat::class)->assertSetStrict('selectedDecisionId', null);

    $this->actingAs($this->regularUser);
    $page->call('showDecision', $decision->id)->assertForbidden();

    $this->assertDatabaseCount('agent_decisions', 1);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
});

test('arc sidebar and activity show only the latest thirty current institution decisions', function (): void {
    $institution = Organization::factory()->create();
    $decisions = collect(range(1, 31))->map(
        fn (int $number): AgentDecision => arcActivityDecision($institution, "Current institution evidence {$number}"),
    );
    $foreignInstitution = Organization::factory()->create();
    $foreignDecision = arcActivityDecision($foreignInstitution, 'Foreign institution confidential evidence');

    // Isolate record scoping from the resolver's multi-institution fail-closed rule.
    $context = Mockery::mock(InstallationInstitution::class);
    $context->shouldReceive('current')->andReturn($institution);
    $this->app->instance(InstallationInstitution::class, $context);

    $page = Livewire::test(AdminAiChat::class)
        ->assertSee('Recorded decisions')
        ->assertSee('ARC AI activity')
        ->assertSee('AI Decision Log')
        ->assertSeeHtml('href="'.AgentDecisionResource::getUrl('index', panel: 'admin').'"')
        ->assertDontSee('Foreign institution confidential evidence')
        ->assertDontSeeHtml('wire:key="arc-decision-'.$foreignDecision->id.'"')
        ->assertDontSeeHtml('wire:key="arc-activity-'.$foreignDecision->id.'"')
        ->assertDontSeeHtml('wire:key="arc-decision-'.$decisions->first()->id.'"')
        ->assertDontSeeHtml('wire:key="arc-activity-'.$decisions->first()->id.'"');

    foreach (['arc-decision-', 'arc-activity-'] as $prefix) {
        $keys = $decisions->slice(1)->reverse()->map(
            fn (AgentDecision $decision): string => 'wire:key="'.$prefix.$decision->id.'"',
        )->values()->all();
        $page->assertSeeHtmlInOrder($keys);
        expect(substr_count($page->html(), 'wire:key="'.$prefix))->toBe(30);
    }

    expect(fn () => $page->call('showDecision', $foreignDecision->id))
        ->toThrow(ModelNotFoundException::class);
    $this->assertDatabaseCount('agent_decisions', 32);
});

test('arc selected details and AI Decision Log link identify the same persisted decision', function (): void {
    $institution = Organization::factory()->create();
    $other = arcActivityDecision($institution, 'Unselected decision evidence');
    $decision = arcActivityDecision($institution, 'Selected decision exact reasoning');
    $decision->update(['policy_checked' => 'SELECTED_POLICY_V2']);
    $url = AgentDecisionResource::getUrl('view', ['record' => $decision], panel: 'admin');
    $otherUrl = AgentDecisionResource::getUrl('view', ['record' => $other], panel: 'admin');

    $page = Livewire::test(AdminAiChat::class)
        ->call('showDecision', $decision->id)
        ->assertSetStrict('selectedDecisionId', $decision->id)
        ->assertSeeHtml('wire:key="arc-selected-'.$decision->id.'"')
        ->assertSee('Decision #'.$decision->id)
        ->assertSee('Selected decision exact reasoning')
        ->assertSee('Escalated to Human')
        ->assertSee('Pending Approval')
        ->assertSee('Policy: SELECTED_POLICY_V2')
        ->assertSee('View full decision evidence')
        ->assertSeeHtml('href="'.$url.'"')
        ->assertDontSeeHtml('href="'.$otherUrl.'"');

    $page->call('showDecision', $other->id)
        ->assertSetStrict('selectedDecisionId', $other->id)
        ->assertSeeHtml('wire:key="arc-selected-'.$other->id.'"')
        ->assertSee('Unselected decision evidence')
        ->assertSeeHtml('href="'.$otherUrl.'"')
        ->assertDontSeeHtml('href="'.$url.'"')
        ->assertDontSee('Selected decision exact reasoning');
    $this->assertDatabaseCount('agent_decisions', 2);
});

test('arc mount select polling refresh and remount remain read only with provider gates enabled', function (): void {
    $institution = Organization::factory()->create();
    $decision = arcActivityDecision($institution, 'Read only persisted decision evidence');
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->allow_settlement_proposals = true;
    $settings->save();

    $session = ChatSession::create([
        'user_id' => $this->admin->id,
        'title' => 'Existing ARC conversation',
        'source' => 'arc',
    ]);
    $session->messages()->create(['role' => 'assistant', 'content' => 'Existing saved answer']);
    $models = [AgentDecision::class, ChatSession::class, ChatMessage::class, Transaction::class];
    $before = collect($models)->mapWithKeys(
        fn (string $model): array => [$model => $model::query()->orderBy('id')->get()->toArray()],
    )->all();

    $page = Livewire::test(AdminAiChat::class)
        ->assertSetStrict('selectedDecisionId', null)
        ->call('showDecision', $decision->id)
        ->assertSee('Read only persisted decision evidence');

    foreach (range(1, 2) as $refresh) {
        $page->call('$refresh')
            ->assertSetStrict('selectedDecisionId', $decision->id)
            ->assertSee('Read only persisted decision evidence');
    }

    Livewire::test(AdminAiChat::class)
        ->assertSetStrict('selectedDecisionId', null)
        ->assertSee('Read only persisted decision evidence');
    $after = collect($models)->mapWithKeys(
        fn (string $model): array => [$model => $model::query()->orderBy('id')->get()->toArray()],
    )->all();
    expect($after)->toBe($before);
});

test('arc blocks decision selection and hides history without a valid institution', function (string $context): void {
    $institution = Organization::factory()->create();
    $decision = arcActivityDecision($institution, 'Evidence hidden without institution context');

    if ($context === 'missing configured institution') {
        config(['eduflow.institution_id' => $institution->id + 1]);
    } else {
        Organization::factory()->create();
    }

    expect(app(InstallationInstitution::class)->current())->toBeNull();
    $page = Livewire::test(AdminAiChat::class)
        ->assertSetStrict('selectedDecisionId', null)
        ->assertSee('No recorded decisions yet')
        ->assertDontSee('Evidence hidden without institution context')
        ->assertDontSeeHtml('wire:key="arc-decision-'.$decision->id.'"')
        ->assertDontSeeHtml('wire:key="arc-activity-'.$decision->id.'"');

    $page->call('showDecision', $decision->id)->assertNotFound();
    $this->assertDatabaseCount('agent_decisions', 1);
})->with(['missing configured institution', 'ambiguous institutions']);

test('arc rejects missing decision ids without changing the selected evidence', function (): void {
    $institution = Organization::factory()->create();
    $decision = arcActivityDecision($institution, 'Existing selected evidence');
    $page = Livewire::test(AdminAiChat::class)
        ->call('showDecision', $decision->id)
        ->assertSetStrict('selectedDecisionId', $decision->id);
    $component = $page->instance();

    expect(fn () => $component->showDecision($decision->id + 1))
        ->toThrow(ModelNotFoundException::class);
    expect($component->selectedDecisionId)->toBe($decision->id);
    $this->assertDatabaseCount('agent_decisions', 1);
});

test('arc refresh synchronizes manual input and provider privacy switches', function (): void {
    $page = Livewire::test(AdminAiChat::class)
        ->assertSetStrict('manualChatEnabled', false)
        ->assertSetStrict('providerCallsAllowed', false);
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();

    $page->call('$refresh')
        ->assertSetStrict('manualChatEnabled', true)
        ->assertSetStrict('providerCallsAllowed', true);

    $settings->disclosure_accepted = false;
    $settings->save();
    $page->call('$refresh')
        ->assertSetStrict('manualChatEnabled', true)
        ->assertSetStrict('providerCallsAllowed', false)
        ->assertSee('External AI calls disabled.');

    $settings->manual_chat_enabled = false;
    $settings->save();
    $page->call('$refresh')->assertSetStrict('manualChatEnabled', false);
    $this->assertDatabaseCount('agent_decisions', 0);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
});

test('arc system instructions separate manual explanations from financial authority', function (): void {
    expect((string) (new AdminAssistantAgent)->instructions())
        ->toContain('"ARC AI"', 'read-only', 'not the autonomous financial execution loop',
            'Never authorize payments, change policy verdicts, approve requests',
            'stored transaction hashes as unverified claims')
        ->not->toContain('Echo');
});

test('arc refresh stops displaying selected evidence when institution context disappears', function (): void {
    $institution = Organization::factory()->create();
    $decision = arcActivityDecision($institution, 'Previously selected private evidence');
    $page = Livewire::test(AdminAiChat::class)
        ->call('showDecision', $decision->id)
        ->assertSee('Previously selected private evidence');

    config(['eduflow.institution_id' => $institution->id + 1]);
    expect(app(InstallationInstitution::class)->current())->toBeNull();
    $page->call('$refresh')
        ->assertSee('No recorded decisions yet')
        ->assertDontSee('Previously selected private evidence')
        ->assertDontSeeHtml('wire:key="arc-selected-'.$decision->id.'"')
        ->assertDontSeeHtml('href="'.AgentDecisionResource::getUrl('view', ['record' => $decision], panel: 'admin').'"');
    $this->assertDatabaseCount('agent_decisions', 1);
});
