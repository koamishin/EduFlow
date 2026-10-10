<?php

declare(strict_types=1);

use App\Ai\Agents\AdminAssistantAgent;
use App\Enums\AgentDecisionType;
use App\Filament\Clusters\Settings\Pages\AiSettingsPage;
use App\Filament\Pages\AdminAiChat;
use App\Filament\Pages\ArcAiActivity;
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
use Tests\TestCase;
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

function arcActivityInstalledBrowser(): ?string
{
    return collect([
        getenv('PROGRAMFILES').'/Google/Chrome/Application/chrome.exe',
        getenv('PROGRAMFILES(X86)').'/Microsoft/Edge/Application/msedge.exe',
    ])->first(fn (string $path): bool => is_file($path));
}

function arcActivityBrowserProbe(string $browser, string $html, string $probe, ?string $scriptPath = null): void
{
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
    $chatScript = file_get_contents($scriptPath ?? resource_path('views/filament/pages/ai-chat-script.blade.php'));
    $setup = <<<'JS'
window.livewireScriptConfig = {};
window.addEventListener('error', event => document.body.setAttribute('data-arc-error', event.message));
window.addEventListener('unhandledrejection', event => document.body.setAttribute('data-arc-error', String(event.reason)));
JS;
    $files = new Filesystem;
    $directory = storage_path('framework/testing/arc-dom-'.bin2hex(random_bytes(8)));
    $files->ensureDirectoryExists($directory);
    $files->put($directory.'/probe.html', '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$chatHtml.'<script>'.$setup.'</script><script>'.$runtime.'</script>'.$chatScript.'<script>'.$probe.'</script></body></html>');

    try {
        $process = new Process([
            $browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--virtual-time-budget=15000', '--dump-dom',
            '--user-data-dir='.$directory.'/profile', 'file:///'.str_replace('\\', '/', $directory.'/probe.html'),
        ]);
        $process->setTimeout(120);
        $process->run();
        expect($process->isSuccessful())->toBeTrue();
        preg_match('/<body[^>]*data-arc-error="([^"]*)"/', $process->getOutput(), $errors);
        expect($errors[1] ?? null)->toBeNull();
        preg_match('/<body[^>]*data-arc-result="([^"]*)"/', $process->getOutput(), $result);
        expect($result[1] ?? substr($process->getOutput().$process->getErrorOutput(), 0, 1500))->toBe('passed');
    } finally {
        $files->deleteDirectory($directory);
    }
}

test('arc activity uses the ARC AI Activity title and defaults manual chat off', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));
    $activityPage = Livewire::test(ArcAiActivity::class);
    $activityPage->assertSuccessful()
        ->assertSee('ARC AI activity')
        ->assertSee('Run Autonomous Agent Cycle')
        ->assertDontSee('Manual conversations');

    expect(app(ArcAiActivity::class)->getTitle())->toBe('ARC AI Activity')
        ->and(ArcAiActivity::getNavigationLabel())->toBe('ARC AI Activity');

    $chatPage = Livewire::test(AdminAiChat::class)
        ->assertSuccessful()
        ->assertSetStrict('manualChatEnabled', false)
        ->assertSee('Manual chat')
        ->assertSeeHtml('manualChatEnabled: false')
        ->assertDontSee('Run Autonomous Agent Cycle');

    expect($chatPage->instance()->getTitle())->toBe('ARC AI Chat')
        ->and(AdminAiChat::getNavigationLabel())->toBe('Manual Chat')
        ->and(AiSettings::defaults()['manual_chat_enabled'])->toBeFalse()
        ->and((new AiSettings)->manual_chat_enabled)->toBeFalse();
    $this->assertDatabaseHas('settings', [
        'group' => 'ai', 'name' => 'manual_chat_enabled', 'payload' => 'false',
    ]);
    $this->assertDatabaseCount('agent_decisions', 0);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
})->with(['admin', 'super_admin']);

test('arc chat and history stay on chat page outside polling morphs while activity remains live on activity page', function (): void {
    $chatPage = Livewire::test(AdminAiChat::class);
    foreach (range(1, 2) as $refresh) {
        $chatPage->call('$refresh')
            ->assertSeeHtml('<main wire:ignore')
            ->assertSeeHtml('<div wire:ignore class="flex-1 space-y-3.5 overflow-y-auto pe-1 text-xs">')
            ->assertDontSee('Run Autonomous Agent Cycle');
    }

    $activityPage = Livewire::test(ArcAiActivity::class);
    foreach (range(1, 2) as $refresh) {
        $activityPage->call('$refresh')
            ->assertSeeHtml('<section wire:poll.15s')
            ->assertSeeHtml('aria-label="Recorded decisions"')
            ->assertSeeHtml('<div wire:ignore wire:key="arc-cycle-execution" data-arc-cycle-execution>')
            ->assertDontSee('Manual conversations');
    }
});

test('arc streamed turns remain visible through polling morphs in installed browser', function (): void {
    $browser = arcActivityInstalledBrowser();
    if ($browser === null) {
        $this->markTestSkipped('Installed Edge or Chrome required for isolated DOM regression.');
    }

    $html = Livewire::test(AdminAiChat::class)->html();
    $probe = <<<'JS'
let makeChat;
const originalData = Alpine.data;
Alpine.data = (name, factory) => { makeChat = factory; };
registerAdminAiChat();
Alpine.data = originalData;
Alpine.data('arcRegression', () => Object.assign(makeChat({manualChatEnabled: true, providerCallsAllowed: true, initialSessions: [], adminUser: {name: 'Admin'}, workspace: {name: 'Regression'}}), {
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
    arcActivityBrowserProbe($browser, $html, $probe);
});

test('arc execution control and public log stay in activity outside model chat', function (): void {
    $page = Livewire::test(ArcAiActivity::class)
        ->assertSee('Run Autonomous Agent Cycle')
        ->assertSee('Execution log')
        ->assertSee('Public operational facts and recorded policy reasons only.')
        ->assertSee('Closing or navigating away from this page does not cancel payments.')
        ->assertSee('Local results · not verified settlement')
        ->assertSeeHtml('cycleAvailable: false');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    $xpath = new DOMXPath($document);
    $execution = $xpath->query('//*[@data-arc-cycle-execution]')->item(0);

    expect($execution)->not->toBeNull();
    assert($execution instanceof DOMElement);
    expect($execution->hasAttribute('wire:ignore'))->toBeTrue()
        ->and($xpath->query('ancestor::section[@aria-label="ARC activity"]', $execution)->length)->toBe(1)
        ->and($xpath->query('//main//*[@data-arc-cycle-execution]')->length)->toBe(0)
        ->and($xpath->query('.//*[@x-html]', $execution)->length)->toBe(0)
        ->and($xpath->query('.//*[@role="log"]', $execution)->length)->toBe(1);
    $button = $xpath->query('.//button', $execution)->item(0);
    assert($button instanceof DOMElement);
    expect(trim($button->textContent))->toBe('Run Autonomous Agent Cycle')
        ->and($button->getAttribute(':disabled'))->toBe('!cycleAvailable || !cycleUrl || cycleRunning || cycleLocked')
        ->and($button->getAttribute('aria-describedby'))->toBe('arc-cycle-safety arc-cycle-availability')
        ->and($xpath->query('//*[@aria-label="Open ARC activity and execution log"]')->length)->toBe(1);
    $source = file_get_contents(resource_path('views/filament/pages/ai-activity.blade.php'));
    expect($source)->toContain('cycleUrl: {{ Js::from($cycleUrl ?? null) }}', 'cycleAvailable: {{ Js::from($cycleAvailable ?? false) }}');
});

test('arc execution streams public facts through morphs and keeps chat independent in installed browser', function (): void {
    $browser = arcActivityInstalledBrowser();
    if ($browser === null) {
        /** @var TestCase $testCase */
        $testCase = $this;
        $testCase->markTestSkipped('Installed Edge or Chrome required for isolated DOM regression.');
    }

    $probe = <<<'JS_WRAP'
    let makeChat;
    const originalData = Alpine.data;
    Alpine.data = (name, factory) => { makeChat = factory; };
    registerAdminAiChat();
    Alpine.data = originalData;
    const config = {
        csrfToken: 'regression-csrf', cycleUrl: '/isolated-cycle', cycleAvailable: true,
        manualChatEnabled: false, providerCallsAllowed: false, initialSessions: [],
        adminUser: {name: 'Admin'}, workspace: {name: 'Regression'},
    };
    Alpine.data('arcRegression', () => Object.assign(makeChat(config), {init() {}, stopVoice() {}, stopSpeaking() {}}));
    const root = document.querySelector('[x-data="arcRegression"]');
    const serverHtml = root.outerHTML;
    let refreshCount = 0;
    const wire = {$refresh() { refreshCount++; throw new Error('refresh failure must not replace terminal'); }, async setManualChatEnabled(enabled) { return enabled; }};
    Alpine.magic('wire', () => wire);
    Livewire.start();
    const chat = Alpine.$data(root);
    const execution = root.querySelector('[data-arc-cycle-execution]');
    const runButton = execution.querySelector('button');
    const flush = () => new Promise(resolve => setTimeout(resolve, 30));
    const check = (condition, message) => { if (!condition) throw new Error(message); };
    const poll = async () => {
        Alpine.morph(root, serverHtml, window.arcMorphConfig({id: 'regression'}));
        await flush();
        check(root.querySelector('[data-arc-cycle-execution]') === execution, 'Execution boundary replaced');
    };
    const runId = 'ab9c3d4e-1234-4567-89ab-123456789abc';
    const frame = (type, sequence, extra = {}) => ({
        type, run_id: runId, sequence, occurred_at: '2026-10-09T10:11:12+00:00',
        phase: 'policy', title: 'Policy evaluated', summary: 'Recorded policy result.', ...extra,
    });
    let requests = [];
    let controller;
    let confirmCount = 0;
    window.confirm = message => {
        confirmCount++;
        check(message.includes('real USDC') && message.includes('does not cancel payments'), 'Financial confirmation missing');
        return false;
    };
    window.fetch = async (url, options) => {
        requests.push({url, options});
        return new Response(new ReadableStream({start(value) { controller = value; }}), {headers: {'Content-Type': 'text/event-stream; charset=UTF-8'}});
    };
    (async () => {
        await flush();
        check(!runButton.disabled, 'Execution gated on chat/provider');
        await chat.runAutonomousCycle();
        check(requests.length === 0 && refreshCount === 0 && chat.cycleEvents.length === 0 && !chat.cycleRunning, 'Cancelled confirmation submitted');
        window.confirm = () => { confirmCount++; return true; };
        const pending = chat.runAutonomousCycle();
        await chat.runAutonomousCycle();
        await flush();
        check(requests.length === 1 && confirmCount === 2 && runButton.disabled, 'Duplicate run submitted or confirmed');
        const request = requests[0];
        check(request.url === config.cycleUrl && request.options.method === 'POST' && request.options.body === '{"confirmed":true}', 'Wrong financial request');
        check(request.options.headers['X-CSRF-TOKEN'] === config.csrfToken && request.options.headers['X-Requested-With'] === 'XMLHttpRequest' && request.options.headers.Accept === 'application/json' && request.options.headers['Content-Type'] === 'application/json', 'Missing request headers');
        check(!request.options.signal && request.options.redirect === 'error', 'Execution coupled to chat cancellation or redirect');
        chat.messages = [{id: 'saved', role: 'user', content: 'Keep manual chat separate'}];
        chat.sessions = [{id: 7, uuid: 'saved', title: 'Saved conversation', updated_at: '2026-10-09T00:00:00Z'}];
        const started = frame('cycle_started', 1, {phase: 'start', title: 'Cycle started', summary: 'Institution context validated.'});
        const progress = frame('cycle_progress', 2, {
            title: 'Café policy result', summary: '<b>Held</b> — policy requires review.',
            decision_id: 27, policy: 'ACTIVE_POLICY_V3', reference: 'INV-27', status: 'held',
            thinking: 'PRIVATE_CHAIN_SECRET', error: 'RAW_ERROR_SECRET',
        });
        const encoder = new TextEncoder();
        const fragmented = encoder.encode(': keepalive\r\nretry: 1\r\n\r\nevent: public\r\ndata: ' + JSON.stringify(started) + '\r\n\r\ndata: ' + JSON.stringify(progress) + '\r\n\r\n');
        for (const byte of fragmented) controller.enqueue(Uint8Array.of(byte));
        await flush();
        check(chat.cycleEvents.length === 2 && execution.textContent.includes('Café policy result'), 'Fragmented UTF-8 or CRLF frame lost');
        check(execution.textContent.includes('<b>Held</b>') && !execution.querySelector('b'), 'Public facts rendered as HTML');
        check(execution.textContent.includes('Decision #27') && execution.textContent.includes('ACTIVE_POLICY_V3') && execution.querySelector('time').getAttribute('datetime') === started.occurred_at, 'Evidence metadata missing');
        await poll();
        check(execution.textContent.includes('Café policy result'), 'Polling lost public execution fact');
        if (typeof chat.toggleManualChat === 'function') {
            await chat.toggleManualChat();
            await chat.toggleManualChat();
            check(chat.cycleRunning && chat.cycleEvents.length === 2 && requests.length === 1, 'Manual switch affected execution');
        }
        controller.enqueue(encoder.encode('data: ' + JSON.stringify(progress) + '\n\ndata: ' + JSON.stringify({type: 'reasoning_delta', delta: 'PRIVATE_CHAIN_SECRET'}) + '\n\n'));
        for (let sequence = 3; sequence <= 105; sequence++) {
            controller.enqueue(encoder.encode('data: ' + JSON.stringify(frame('cycle_progress', sequence)) + '\n\n'));
        }
        await flush();
        check(chat.cycleEvents.length === 100 && chat.cycleOmittedEvents === 5, 'Execution log unbounded or duplicate retained');
        await poll();
        const completed = frame('cycle_completed', 106, {
            phase: 'complete', title: 'Cycle completed', summary: 'Local results only; settlement is not verified.',
            stats: {auto_paid: 1, escalated: 2, held: 3, rejected: 4, total_disbursed_usdc: '12.340001'},
        });
        const json = JSON.stringify(completed).replace(',"run_id"', ',\ndata: "run_id"');
        controller.enqueue(encoder.encode('data: ' + json));
        controller.close();
        await pending;
        await flush();
        await poll();
        check(chat.cycleState === 'completed' && !chat.cycleRunning && !chat.cycleLocked, 'Terminal EOF not completed');
        check(chat.cycleStats.total_disbursed_usdc === '12.340001' && execution.textContent.includes('12.340001') && execution.textContent.includes('not verified settlement'), 'Local result precision or settlement warning lost');
        check(refreshCount === 1 && chat.cycleRefreshError && execution.textContent.includes('Cycle completed'), 'Refresh error replaced known terminal');
        check(chat.cycleEvents.length === 100 && chat.cycleOmittedEvents === 6 && execution.querySelectorAll('li').length === 100, 'Visible log cap lost after morph');
        check(!execution.textContent.includes('PRIVATE_CHAIN_SECRET') && !JSON.stringify(chat.cycleEvents).includes('RAW_ERROR_SECRET'), 'Non-public fields leaked');
        check(chat.messages.length === 1 && chat.sessions.length === 1 && chat.aiActions.length === 0 && requests.length === 1 && !runButton.disabled, 'Execution changed chat or retried');
        await flush();
        check(requests.length === 1, 'Automatic reconnect attempted');
        document.body.setAttribute('data-arc-result', 'passed');
    })().catch(error => document.body.setAttribute('data-arc-result', error.message));
    JS_WRAP;
    arcActivityBrowserProbe($browser, Livewire::test(ArcAiActivity::class)->html(), $probe, resource_path('views/filament/pages/ai-activity-script.blade.php'));
});

test('arc execution locks uncertain or failed outcomes without leaking raw errors or retrying in installed browser', function (): void {
    $browser = arcActivityInstalledBrowser();
    if ($browser === null) {
        /** @var TestCase $testCase */
        $testCase = $this;
        $testCase->markTestSkipped('Installed Edge or Chrome required for isolated DOM regression.');
    }

    $probe = <<<'JS_WRAP'
    let makeChat;
    const originalData = Alpine.data;
    Alpine.data = (name, factory) => { makeChat = factory; };
    registerAdminAiChat();
    Alpine.data = originalData;
    const config = {csrfToken: 'test', cycleUrl: '/isolated-cycle', cycleAvailable: true, manualChatEnabled: false, providerCallsAllowed: false, initialSessions: [], adminUser: {}, workspace: {name: 'Regression'}};
    Alpine.data('arcRegression', () => Object.assign(makeChat(config), {init() {}}));
    const root = document.querySelector('[x-data="arcRegression"]');
    const serverHtml = root.outerHTML;
    let refreshCount = 0;
    Alpine.magic('wire', () => ({$refresh() {refreshCount++; return Promise.reject(new Error('RAW_REFRESH_SECRET'));}}));
    Livewire.start();
    const chat = Alpine.$data(root);
    const execution = root.querySelector('[data-arc-cycle-execution]');
    const check = (condition, message) => {if (!condition) throw new Error(message);};
    const flush = () => new Promise(resolve => setTimeout(resolve, 20));
    let requests = 0;
    let confirmations = 0;
    window.confirm = () => {confirmations++; return true;};
    const frame = (type, sequence, extra = {}) => ({type, sequence, run_id: 'ab9c3d4e-1234-4567-89ab-123456789abc', occurred_at: '2026-10-09T10:11:12Z', phase: 'payment', title: 'Public event', summary: 'Safe public summary.', ...extra});
    const response = (text, contentType = 'text/event-stream') => new Response(text, {headers: {'Content-Type': contentType}});
    const scenarios = [
        ['network', async () => {throw new Error('RAW_NETWORK_SECRET');}, 'unknown'],
        ['empty EOF', async () => response(''), 'unknown'],
        ['no terminal', async () => response('data: ' + JSON.stringify(frame('cycle_started', 1)) + '\n\n'), 'unknown'],
        ['invalid JSON', async () => response('data: {RAW_JSON_SECRET\n\n'), 'unknown'],
        ['bad UTF-8', async () => new Response(Uint8Array.of(0xc3, 0x28), {headers: {'Content-Type': 'text/event-stream'}}), 'unknown'],
        ['unexpected content', async () => response('RAW_HTML_SECRET', 'text/html'), 'unknown'],
        ['unexpected HTTP', async () => new Response('RAW_HTTP_SECRET', {status: 500}), 'unknown'],
        ['run mismatch', async () => response('data: ' + JSON.stringify(frame('cycle_started', 1)) + '\n\ndata: ' + JSON.stringify(frame('cycle_completed', 2, {run_id: 'bbbbbbbb-1234-4567-89ab-123456789abc'})) + '\n\n'), 'unknown'],
        ...[401, 403, 409, 419, 422, 429, 503].map(status => ['HTTP ' + status, async () => new Response('RAW_HTTP_SECRET', {status}), 'failed']),
        ...['insufficient_funds', 'unexpected_error'].map(reason => [reason, async () => response('data: ' + JSON.stringify(frame('cycle_failed', 1, {title: 'Cycle failed', reason, error: 'RAW_PAYMENT_SECRET', thinking: 'PRIVATE_CHAIN_SECRET'})) + '\r\n\r\n'), 'failed']),
    ];
    (async () => {
        for (const [name, fetchResponse, expected] of scenarios) {
            chat.cycleState = 'idle';
            chat.cycleLocked = false;
            chat.cycleRunning = false;
            const beforeRequests = requests;
            const beforeConfirmations = confirmations;
            const beforeRefresh = refreshCount;
            window.fetch = async () => {requests++; return fetchResponse();};
            await chat.runAutonomousCycle();
            await flush();
            check(chat.cycleState === expected && chat.cycleLocked && !chat.cycleRunning, name + ': unsafe outcome');
            if (expected === 'unknown') {
                check(execution.textContent.includes('UNKNOWN outcome') && execution.textContent.includes('may still be running or some payments may have completed'), name + ': missing uncertainty warning');
            }
            if (name === 'insufficient_funds') check(chat.cycleSummary.includes('Insufficient funds.'), 'Safe failure reason missing');
            Alpine.morph(root, serverHtml, window.arcMorphConfig({id: 'regression'}));
            await flush();
            check(execution.querySelector('button').disabled && execution.textContent.includes('Rerun disabled for this page session') && execution.textContent.includes('Review AI Decision Log evidence'), name + ': lock or evidence lost during poll');
            check(!/RAW_\w+_SECRET|PRIVATE_CHAIN_SECRET/.test(execution.textContent + JSON.stringify(chat.cycleEvents)), name + ': raw error leaked');
            await chat.runAutonomousCycle();
            await flush();
            check(requests === beforeRequests + 1 && confirmations === beforeConfirmations + 1, name + ': duplicate or automatic retry');
            check(refreshCount === beforeRefresh + 1 && chat.cycleState === expected, name + ': refresh changed outcome');
        }
        document.body.setAttribute('data-arc-result', 'passed');
    })().catch(error => document.body.setAttribute('data-arc-result', error.message));
    JS_WRAP;
    arcActivityBrowserProbe($browser, Livewire::test(ArcAiActivity::class)->html(), $probe, resource_path('views/filament/pages/ai-activity-script.blade.php'));
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
    $page = Livewire::test(ArcAiActivity::class)->assertSetStrict('selectedDecisionId', null);

    $this->actingAs($this->regularUser);
    $page->call('showDecision', $decision->id)->assertForbidden();

    $this->assertDatabaseCount('agent_decisions', 1);
    $this->assertDatabaseCount('chat_sessions', 0);
    $this->assertDatabaseCount('chat_messages', 0);
});

test('arc sidebar shows only the latest thirty current institution decisions', function (): void {
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

    $page = Livewire::test(ArcAiActivity::class)
        ->assertSee('Recorded decisions')
        ->assertSee('ARC AI activity')
        ->assertSee('AI Decision Log')
        ->assertSeeHtml('href="'.AgentDecisionResource::getUrl('index', panel: 'admin').'"')
        ->assertDontSee('Foreign institution confidential evidence')
        ->assertDontSeeHtml('wire:key="arc-decision-'.$foreignDecision->id.'"')
        ->assertDontSeeHtml('wire:key="arc-decision-'.$decisions->first()->id.'"');

    $keys = $decisions->slice(1)->reverse()->map(
        fn (AgentDecision $decision): string => 'wire:key="arc-decision-'.$decision->id.'"',
    )->values()->all();
    $page->assertSeeHtmlInOrder($keys);
    expect(substr_count($page->html(), 'wire:key="arc-decision-'))->toBe(30);

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

    $page = Livewire::test(ArcAiActivity::class)
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

    $page = Livewire::test(ArcAiActivity::class)
        ->assertSetStrict('selectedDecisionId', null)
        ->call('showDecision', $decision->id)
        ->assertSee('Read only persisted decision evidence');

    foreach (range(1, 2) as $refresh) {
        $page->call('$refresh')
            ->assertSetStrict('selectedDecisionId', $decision->id)
            ->assertSee('Read only persisted decision evidence');
    }

    Livewire::test(ArcAiActivity::class)
        ->assertSetStrict('selectedDecisionId', null);
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
    $page = Livewire::test(ArcAiActivity::class)
        ->assertSetStrict('selectedDecisionId', null)
        ->assertSee('No recorded decisions yet')
        ->assertDontSee('Evidence hidden without institution context')
        ->assertDontSeeHtml('wire:key="arc-decision-'.$decision->id.'"');

    $page->call('showDecision', $decision->id)->assertNotFound();
    $this->assertDatabaseCount('agent_decisions', 1);
})->with(['missing configured institution', 'ambiguous institutions']);

test('arc rejects missing decision ids without changing the selected evidence', function (): void {
    $institution = Organization::factory()->create();
    $decision = arcActivityDecision($institution, 'Existing selected evidence');
    $page = Livewire::test(ArcAiActivity::class)
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
    $page = Livewire::test(ArcAiActivity::class)
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
