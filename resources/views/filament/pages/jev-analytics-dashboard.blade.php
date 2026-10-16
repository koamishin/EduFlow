<x-filament-panels::page>
    {{-- Governance & Telemetry Overview Hero Banner --}}
    <x-filament::section class="overflow-hidden">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200/80 pb-4 dark:border-gray-800">
                <div class="flex items-center gap-3.5">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 text-white shadow-sm ring-4 ring-amber-50 dark:from-amber-600 dark:to-amber-950 dark:ring-amber-950/40">
                        <x-filament::icon icon="heroicon-m-sparkles" class="h-6 w-6 text-amber-100" />
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h2 class="text-lg font-bold tracking-tight text-gray-950 dark:text-white">
                                JEV Advisory Governance & Decision Analytics
                            </h2>
                            <x-filament::badge color="info" size="sm">
                                Autonomous Lane Telemetry
                            </x-filament::badge>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Auditable metrics on how often JEV classifies, holds, or escalates policy evidence, and how frequently human finance officers concur vs override.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="success" size="md">
                        <x-filament::icon icon="heroicon-m-check-circle" class="mr-1 h-3.5 w-3.5" />
                        80% Confidence Safety Gate
                    </x-filament::badge>
                    <x-filament::badge color="warning" size="md">
                        <x-filament::icon icon="heroicon-m-shield-check" class="mr-1 h-3.5 w-3.5" />
                        Fail-Closed Review Protocol
                    </x-filament::badge>
                </div>
            </div>

            {{-- Explanatory Architecture Pillars --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-gray-200/70 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-900 dark:text-gray-100">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">1</span>
                        <span>Verbatim Policy Matching</span>
                    </div>
                    <p class="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                        JEV evaluates untrusted evidence against published institution clauses only. Citations must match verbatim text; hallucinations are rejected by validator.
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200/70 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-900 dark:text-gray-100">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-[10px] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">2</span>
                        <span>Fail-Closed Safety Holds</span>
                    </div>
                    <p class="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                        When confidence is below 80% or multiple categories conflict, JEV halts and logs a <span class="font-semibold text-amber-600 dark:text-amber-400">needs_review</span> or <span class="font-semibold text-rose-600 dark:text-rose-400">hold</span>. No money is moved.
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200/70 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-900 dark:text-gray-100">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">3</span>
                        <span>Dual-Control Concurrence</span>
                    </div>
                    <p class="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                        Human staff independent of the evidence maker must review and sign off. Concurrence tracks alignment between JEV advice and human authorization.
                    </p>
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- Interactive Table of JEV Evaluations & Staff Audit Trail --}}
    <div class="mt-2">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
