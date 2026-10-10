<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessFinanceWorkflow;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final readonly class FinanceWorkflowDispatcher
{
    public function __construct(private InstallationInstitution $institutions, private FinanceWorkflows $workflows) {}

    public function dispatch(): int
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return 0;
        }
        $connection = (string) config('eduflow.background_finance.queue_connection', 'database');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['database', 'redis'], true) || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 30) {
            throw ValidationException::withMessages(['queue' => 'Background finance requires a durable database/Redis queue with retry_after greater than 30 seconds.']);
        }
        if (! Schema::hasTable('finance_workflow_runs')) {
            throw ValidationException::withMessages(['database' => 'Finance workflow migrations must be applied by the operator before enablement.']);
        }
        $institution = $this->institutions->require();
        /** @var Collection<int, BudgetSnapshot> $budgets */
        $budgets = BudgetSnapshot::query()->where('organization_id', $institution->id)->whereDoesntHave('workflowRuns')->orderBy('id')->limit(100)->get();
        $budgets->each(function (BudgetSnapshot $snapshot): void {
            if (($snapshot->snapshot['schema_version'] ?? null) === 2) {
                $this->workflows->budgetCaptured($snapshot);
            }
        });
        /** @var Collection<int, CollectionBatch> $batches */
        $batches = CollectionBatch::query()->where('organization_id', $institution->id)->whereDoesntHave('workflowRuns')->orderBy('id')->limit(100)->get();
        $batches->each(fn (CollectionBatch $batch) => $this->workflows->collectionCaptured($batch));
        $count = 0;
        /** @var Collection<int, FinanceWorkflowRun> $pending */
        $pending = FinanceWorkflowRun::query()->where('organization_id', $institution->id)->where('state', 'queued')
            ->where(function (Builder $query): void {
                $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(2));
            })->orderBy('id')->limit(100)->get();
        foreach ($pending as $run) {
            $claimed = FinanceWorkflowRun::query()->whereKey($run->id)->where('state', 'queued')
                ->where(fn (Builder $query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(2)))
                ->update(['dispatched_at' => now(), 'next_attempt_at' => now()->addMinutes(2)]);
            if ($claimed === 1) {
                DB::afterCommit(fn () => Bus::dispatch(new ProcessFinanceWorkflow($run->id)));
                $count++;
            }
        }
        foreach (FinanceWorkflowRun::query()->where('organization_id', $institution->id)->whereIn('state', ['waiting_for_review', 'blocked', 'failed'])
            ->orderBy('id')->limit(100)->get() as $run) {
            if ($run->next_attempt_at === null || $run->next_attempt_at->lte(now())) {
                $claimed = FinanceWorkflowRun::query()->whereKey($run->id)
                    ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->update(['next_attempt_at' => now()->addMinutes(2)]);
                if ($claimed === 1) {
                    DB::afterCommit(fn () => Bus::dispatch(new ProcessFinanceWorkflow($run->id)));
                }
            }
        }

        return $count;
    }
}
