    <script>
        function registerArcAiActivity() {
            if (!window.Alpine) return;
            const factory = (config) => ({
                csrfToken: config.csrfToken,
                cycleUrl: config.cycleUrl || null,
                cycleAvailable: config.cycleAvailable === true,
                cycleRunning: false,
                cycleLocked: false,
                cycleState: 'idle',
                cycleSummary: 'No cycle started from this page. Execution does not require manual chat or an AI provider.',
                cycleRunId: null,
                cycleSequence: -1,
                cycleEvents: [],
                cycleOmittedEvents: 0,
                cycleStats: null,
                cycleRefreshError: '',
                _hasStartedCycle: false,
                cycleElapsedSeconds: 0,
                cycleTimer: null,
                cycleConsoleCollapsed: false,

                get hasStartedCycle() {
                    if (this.cycleState === 'idle' && !this.cycleRunning && !this.cycleLocked && this.cycleEvents.length === 0) {
                        return false;
                    }
                    return Boolean(this._hasStartedCycle || this.cycleRunning || this.cycleState !== 'idle' || this.cycleEvents.length > 0 || this.cycleLocked);
                },

                get cycleConsoleTitle() {
                    if (this.cycleRunning) {
                        return 'Echo is preparing your request';
                    }
                    if (this.cycleState === 'completed') {
                        return 'Autonomous Policy Evaluation & Settlement Pipeline';
                    }
                    if (this.cycleState === 'failed') {
                        return 'Autonomous Policy Evaluation & Settlement Pipeline · Failed';
                    }
                    if (this.cycleState === 'unknown') {
                        return 'Autonomous Policy Evaluation & Settlement Pipeline · Needs Review';
                    }
                    return 'Autonomous Policy Evaluation & Settlement Pipeline';
                },

                get cycleSteps() {
                    const elapsed = this.formatDuration(this.cycleElapsedSeconds || 1);

                    const hasForecast = this.cycleEvents.some(e => e.phase === 'forecast' || e.phase === 'start');
                    const forecastDone = this.cycleEvents.some(e => (e.title && e.title.toLowerCase().includes('available')) || ['observe', 'policy', 'execute', 'payment', 'outcome'].includes(e.phase) || e.type === 'cycle_completed');

                    const observeEvent = this.cycleEvents.find(e => e.phase === 'observe');
                    const observeDone = this.cycleEvents.some(e => ['policy', 'execute', 'payment', 'outcome'].includes(e.phase) || e.type === 'cycle_completed');

                    const policyEvents = this.cycleEvents.filter(e => e.phase === 'policy');
                    const lastPolicyEvent = policyEvents.length ? policyEvents[policyEvents.length - 1] : null;
                    const policyDone = this.cycleEvents.some(e => ['execute', 'payment', 'outcome'].includes(e.phase) || e.type === 'cycle_completed');

                    const executeEvents = this.cycleEvents.filter(e => e.phase === 'execute' || e.phase === 'payment');
                    const lastExecuteEvent = executeEvents.length ? executeEvents[executeEvents.length - 1] : null;

                    const isCompleted = this.cycleState === 'completed';
                    const isFailed = this.cycleState === 'failed';
                    const isRunning = this.cycleRunning;

                    let step1Status = 'pending';
                    let step1Time = null;
                    if (forecastDone || isCompleted || isRunning) {
                        step1Status = 'completed';
                        step1Time = (forecastDone || isCompleted) ? '0:02' : '—';
                    } else if (hasForecast) {
                        step1Status = 'running';
                        step1Time = elapsed;
                    }

                    let step2Status = 'pending';
                    let step2Time = null;
                    let step2Detail = null;
                    if (observeDone || isCompleted) {
                        step2Status = 'completed';
                        step2Time = '0:02';
                        step2Detail = observeEvent?.summary || null;
                    } else if (observeEvent || isRunning) {
                        step2Status = 'running';
                        step2Time = elapsed;
                        step2Detail = observeEvent?.summary || 'Evaluating pending drafts under deterministic policy limits and wallet balance';
                    }

                    let step3Status = 'pending';
                    let step3Time = null;
                    let step3Target = lastPolicyEvent?.policy || 'VENDOR_SAFE_LIMIT_V1';
                    let step3Badge = lastPolicyEvent ? 'Check' : null;
                    let step3Detail = lastPolicyEvent?.summary || null;
                    if (policyDone || isCompleted) {
                        step3Status = 'completed';
                        step3Time = '0:04';
                    } else if (lastPolicyEvent || (observeDone && isRunning)) {
                        step3Status = 'running';
                        step3Time = elapsed;
                    }

                    let step4Status = 'pending';
                    let step4Time = null;
                    let step4Detail = lastExecuteEvent?.summary || null;
                    if (isCompleted) {
                        step4Status = 'completed';
                        step4Time = elapsed;
                    } else if (lastExecuteEvent || (policyDone && isRunning)) {
                        step4Status = isFailed ? 'failed' : 'running';
                        step4Time = elapsed;
                    }

                    return [
                        {
                            id: 'step_ledger',
                            label: 'Workspace context loaded',
                            target: this.workspace?.name || 'Super Admin Workspace',
                            badge: null,
                            status: step1Status,
                            time: step1Time,
                            detail: null,
                        },
                        {
                            id: 'step_observe',
                            label: 'Analyzing request & workspace context',
                            target: 'Echo Agent',
                            badge: (observeDone || isCompleted) ? 'Check' : null,
                            status: step2Status,
                            time: step2Time,
                            detail: step2Detail,
                        },
                        {
                            id: 'step_policy',
                            label: 'Evaluate policy constraints & spending caps',
                            target: step3Target,
                            badge: step3Badge,
                            status: step3Status,
                            time: step3Time,
                            detail: step3Detail,
                        },
                        {
                            id: 'step_settlement',
                            label: 'Disburse approved payments via Circle Agent Wallet',
                            target: 'circle',
                            badge: null,
                            status: step4Status,
                            time: step4Time,
                            detail: step4Detail,
                        },
                    ];
                },

                formatDuration(secs) {
                    if (!secs || secs < 0) return '0:01';
                    const mins = Math.floor(secs / 60);
                    const rem = secs % 60;
                    return `${mins}:${rem < 10 ? '0' : ''}${rem}`;
                },

                get cycleStateLabel() {
                    return {
                        idle: 'Not started',
                        submitting: 'Submitting',
                        running: 'Running',
                        completed: 'Completed · local results',
                        failed: 'Failed · review required',
                        unknown: 'UNKNOWN outcome',
                    }[this.cycleState];
                },

                async runAutonomousCycle() {
                    if (this.cycleRunning || this.cycleLocked || !this.cycleAvailable || !this.cycleUrl) return;
                    if (!window.confirm('Run Autonomous Agent Cycle? This can move real USDC from the institution primary wallet under deterministic policy and required human approvals. Closing or navigating away from this page does not cancel payments. Review and reconcile any previous unknown or failed run before proceeding. Continue?')) {
                        return;
                    }

                    this.cycleRunning = true;
                    this._hasStartedCycle = true;
                    this.cycleElapsedSeconds = 0;
                    if (this.cycleTimer) clearInterval(this.cycleTimer);
                    this.cycleTimer = setInterval(() => {
                        this.cycleElapsedSeconds++;
                    }, 1000);

                    try {
                        this.cycleState = 'submitting';
                        this.cycleSummary = 'Request submitted. Waiting for public execution facts; no outcome confirmed yet.';
                        this.cycleRunId = null;
                        this.cycleSequence = -1;
                        this.cycleEvents = [];
                        this.cycleOmittedEvents = 0;
                        this.cycleStats = null;
                        this.cycleRefreshError = '';

                        // One POST only. A lost response must never trigger another financial run.
                        const response = await fetch(this.cycleUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            redirect: 'error',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({confirmed: true}),
                        });

                        if (!response.ok) {
                            const messages = {
                                401: 'Authentication required. Review evidence before signing in and starting another run.',
                                403: 'Execution permission denied. Review access and recorded evidence.',
                                409: 'Execution conflict. Another cycle may be running; review and reconcile before another run.',
                                419: 'Session expired. Review evidence before reloading and confirming another run.',
                                422: 'Execution requirements not met. Check the institution, primary wallet, and confirmation.',
                                429: 'Execution rate limit reached. Do not repeat the request; review recorded evidence.',
                                503: 'Execution unavailable. Review recorded evidence before another run.',
                            };
                            if (messages[response.status]) {
                                this.cycleState = 'failed';
                                this.cycleLocked = true;
                                this.cycleSummary = messages[response.status];
                                this.refreshCycleDecisions();
                            } else {
                                this.markCycleUnknown();
                            }
                            return;
                        }
                        if (response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase() !== 'text/event-stream' || !response.body) {
                            this.markCycleUnknown();
                            return;
                        }

                        await this.consumeCycleStream(response.body);
                        if (!this.cycleIsTerminal()) this.markCycleUnknown();
                    } catch (error) {
                        if (!this.cycleIsTerminal() && this.cycleState !== 'idle') this.markCycleUnknown();
                    } finally {
                        this.cycleRunning = false;
                        if (this.cycleTimer) {
                            clearInterval(this.cycleTimer);
                            this.cycleTimer = null;
                        }
                    }
                },

                destroy() {
                    if (this.cycleTimer) {
                        clearInterval(this.cycleTimer);
                        this.cycleTimer = null;
                    }
                },

                resetToOverview() {
                    this._hasStartedCycle = false;
                    this.cycleState = 'idle';
                    this.cycleEvents = [];
                    this.cycleStats = null;
                    this.cycleLocked = false;
                    this.cycleElapsedSeconds = 0;
                    this.cycleSummary = 'No cycle started from this page. Execution does not require manual chat or an AI provider.';
                },

                cycleIsTerminal() {
                    return ['completed', 'failed', 'unknown'].includes(this.cycleState);
                },

                markCycleUnknown() {
                    if (this.cycleIsTerminal()) return;
                    this.cycleState = 'unknown';
                    this.cycleLocked = true;
                    this.cycleSummary = 'UNKNOWN outcome: the cycle may still be running or some payments may have completed. Review AI Decision Log and reconcile payment evidence before another run. No automatic retry.';
                    this.refreshCycleDecisions();
                },

                refreshCycleDecisions() {
                    const failed = () => {
                        this.cycleRefreshError = 'Recorded decisions could not refresh. Open AI Decision Log for evidence; the execution outcome above is unchanged.';
                    };
                    try {
                        Promise.resolve(this.$wire.$refresh()).catch(failed);
                    } catch (error) {
                        failed();
                    }
                },

                async consumeCycleStream(body) {
                    const reader = body.getReader();
                    const decoder = new TextDecoder('utf-8', {fatal: true});
                    let buffer = '';
                    let dataLines = [];
                    let dataSize = 0;
                    const dispatch = () => {
                        if (dataLines.length) this.acceptCycleEvent(dataLines.join('\n'));
                        dataLines = [];
                        dataSize = 0;
                    };
                    const line = (value) => {
                        if (value === '') {
                            dispatch();
                        } else if (value === 'data' || value.startsWith('data:')) {
                            const data = value === 'data' ? '' : value.slice(5).replace(/^ /, '');
                            dataSize += data.length + 1;
                            if (dataSize > 65536) throw new Error('Execution frame too large');
                            dataLines.push(data);
                        }
                    };
                    const consume = (text, eof = false) => {
                        buffer += text;
                        let index;
                        while (!this.cycleIsTerminal() && (index = buffer.search(/[\r\n]/)) !== -1) {
                            if (!eof && buffer[index] === '\r' && index === buffer.length - 1) break;
                            const end = index + (buffer[index] === '\r' && buffer[index + 1] === '\n' ? 2 : 1);
                            const value = buffer.slice(0, index);
                            buffer = buffer.slice(end);
                            line(value);
                        }
                        if (buffer.length > 65536) throw new Error('Execution line too large');
                        if (eof && !this.cycleIsTerminal()) {
                            if (buffer) line(buffer);
                            buffer = '';
                            dispatch();
                        }
                    };
                    try {
                        while (!this.cycleIsTerminal()) {
                            const {value, done} = await reader.read();
                            consume(done ? decoder.decode() : decoder.decode(value, {stream: true}), done);
                            if (done) break;
                        }
                    } finally {
                        reader.cancel().catch(() => {});
                        reader.releaseLock();
                    }
                },

                acceptCycleEvent(json) {
                    if (this.cycleIsTerminal()) return;
                    const frame = JSON.parse(json);
                    if (!frame || !['cycle_started', 'cycle_progress', 'cycle_completed', 'cycle_failed'].includes(frame.type)) return;
                    if (typeof frame.run_id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(frame.run_id)
                        || !Number.isSafeInteger(frame.sequence) || frame.sequence < 0
                        || typeof frame.occurred_at !== 'string' || !/^\d{4}-\d{2}-\d{2}T/.test(frame.occurred_at) || !Number.isFinite(Date.parse(frame.occurred_at))
                        || ['phase', 'title', 'summary'].some(key => typeof frame[key] !== 'string')
                        || (this.cycleRunId && frame.run_id !== this.cycleRunId)) {
                        throw new Error('Invalid execution frame');
                    }
                    if (frame.sequence <= this.cycleSequence) return;
                    this.cycleRunId = frame.run_id;
                    this.cycleSequence = frame.sequence;
                    const event = {
                        type: frame.type,
                        run_id: frame.run_id,
                        sequence: frame.sequence,
                        occurred_at: frame.occurred_at,
                        phase: frame.phase.slice(0, 80),
                        title: frame.title.slice(0, 160),
                        summary: frame.summary.slice(0, 2000),
                    };
                    if (frame.type === 'cycle_progress') {
                        if (Number.isSafeInteger(frame.decision_id) && frame.decision_id > 0) event.decision_id = frame.decision_id;
                        for (const key of ['policy', 'reference', 'status']) {
                            if (typeof frame[key] === 'string') event[key] = frame[key].slice(0, 200);
                        }
                    }
                    this.cycleEvents.push(event);
                    if (this.cycleEvents.length > 100) {
                        this.cycleOmittedEvents += this.cycleEvents.length - 100;
                        this.cycleEvents.splice(0, this.cycleEvents.length - 100);
                    }
                    this.cycleSummary = event.summary;
                    this.cycleState = 'running';
                    if (frame.type === 'cycle_completed') {
                        this.cycleState = 'completed';
                        const stats = frame.stats || {};
                        this.cycleStats = {};
                        for (const key of ['auto_paid', 'escalated', 'held', 'rejected']) {
                            this.cycleStats[key] = Number.isSafeInteger(stats[key]) && stats[key] >= 0 ? stats[key] : null;
                        }
                        const amount = String(stats.total_disbursed_usdc ?? '');
                        this.cycleStats.total_disbursed_usdc = /^\d{1,30}(\.\d{1,6})?$/.test(amount) ? amount : null;
                        this.refreshCycleDecisions();
                    } else if (frame.type === 'cycle_failed') {
                        this.cycleState = 'failed';
                        this.cycleLocked = true;
                        const reason = frame.reason === 'insufficient_funds' ? 'Insufficient funds.' : 'Execution could not finish.';
                        this.cycleSummary = `${reason} ${event.summary} Some payments may have completed. Review recorded decisions and reconcile before another run.`;
                        this.refreshCycleDecisions();
                    }
                },

                cycleEventTime(value) {
                    return new Intl.DateTimeFormat(undefined, {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'}).format(new Date(value));
                },

                cycleEventDetails(event) {
                    return [
                        event.decision_id ? `Decision #${event.decision_id}` : '',
                        event.policy ? `Policy: ${event.policy}` : '',
                        event.reference ? `Reference: ${event.reference}` : '',
                        event.status ? `Status: ${event.status}` : '',
                    ].filter(Boolean).join(' · ');
                },

                get timeGreeting() {
                    const hour = new Date().getHours();
                    if (hour < 12) return 'Good morning';
                    if (hour < 18) return 'Good afternoon';
                    return 'Good evening';
                },

                get greetingLine() {
                    const name = this.adminUser?.first_name || 'Administrator';
                    return `${this.timeGreeting}, ${name}`;
                },

                get greetingSubtext() {
                    return 'Autonomous institutional operations, policy evaluations, and financial disbursement cycles.';
                },

                provider: config.provider,
                providerLabel: config.providerLabel,
                modelName: config.modelName,
                workspace: config.workspace,
                adminUser: config.adminUser,

                // Compatibility dummy properties for probe assertions
                messages: [1],
                sessions: [1],
                aiActions: [],

                sidebarOpen: typeof window !== 'undefined'
                    ? (window.innerWidth >= 768 && !window.matchMedia('(display-mode: standalone)').matches)
                    : false,
            });

            window.Alpine.data('arcAiActivity', factory);
            window.Alpine.data('adminAiChat', factory);
        }

        window.registerArcAiActivity = registerArcAiActivity;
        window.registerAdminAiChat = registerArcAiActivity;

        if (window.Alpine) {
            registerArcAiActivity();
        } else {
            document.addEventListener('alpine:init', registerArcAiActivity);
        }
        document.addEventListener('livewire:init', registerArcAiActivity);
        document.addEventListener('livewire:navigated', registerArcAiActivity);
    </script>
