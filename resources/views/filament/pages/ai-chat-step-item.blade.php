<div class="flex items-start justify-between gap-3 text-xs">
    <div class="flex min-w-0 items-start gap-2.5">
        <template x-if="step.status === 'completed'">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="9" />
                <path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </template>
        <template x-if="step.status === 'running'">
            <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-blue-400" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
            </svg>
        </template>
        <template x-if="step.status === 'pending'">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2 2">
                <circle cx="12" cy="12" r="9" />
            </svg>
        </template>
        <template x-if="step.status === 'failed'">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="9" />
                <path d="m15 9-6 6m0-6 6 6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </template>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <template x-if="step.target === 'circle' || step.target === 'agent' || step.target === 'arc'">
                    <span class="rounded border border-zinc-800 bg-zinc-900 px-1 py-0.5 font-mono text-[10px] text-zinc-400" x-text="step.target"></span>
                </template>
                <span
                    class="font-medium"
                    :class="step.status === 'running' ? 'text-blue-300 font-semibold' : (step.status === 'completed' ? 'text-zinc-200' : (step.status === 'failed' ? 'text-red-400' : 'text-zinc-500'))"
                    x-text="step.label"
                ></span>
                <template x-if="step.badge">
                    <span class="rounded border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 font-mono text-[10px] text-amber-300" x-text="step.badge"></span>
                </template>
                <template x-if="step.target && step.target !== 'circle' && step.target !== 'agent' && step.target !== 'arc'">
                    <span class="font-mono text-[11px] text-zinc-400" x-text="step.target"></span>
                </template>
            </div>
            <template x-if="step.detail">
                <p class="mt-1 text-[11px] leading-relaxed text-zinc-400" x-text="step.detail"></p>
            </template>
        </div>
    </div>
    <span class="shrink-0 font-mono text-[11px] text-zinc-500" x-text="step.time || '—'"></span>
</div>
