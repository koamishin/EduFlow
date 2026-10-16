<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EvaluateInstitutionPolicy;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Organization;
use App\Services\InstallationInstitution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class DispatchPolicyEvaluations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dispatch-policy-evaluations
        {--sync : Execute the evaluation synchronously in the current process}
        {--limit=20 : Maximum number of queued evaluations to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch pending JEV policy evaluations for background worker processing';

    public function handle(InstallationInstitution $institutions): int
    {
        $institution = $institutions->current();
        if (! $institution instanceof Organization) {
            $this->warn('No active institution context found.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sync = (bool) $this->option('sync');

        $pending = InstitutionPolicyEvaluation::query()
            ->where('organization_id', $institution->id)
            ->where('state', 'queued')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No queued policy evaluations waiting for dispatch.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($pending as $evaluation) {
            try {
                if ($sync) {
                    $this->line("Evaluating policy evaluation #{$evaluation->id} synchronously...");
                    app()->call([new EvaluateInstitutionPolicy($evaluation->id), 'handle']);
                } else {
                    $this->line("Dispatching policy evaluation #{$evaluation->id} to queue...");
                    Bus::dispatch(new EvaluateInstitutionPolicy($evaluation->id));
                }
                $dispatched++;
            } catch (Throwable $e) {
                $this->error("Failed to process evaluation #{$evaluation->id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Processed {$dispatched} JEV policy evaluations.");

        return self::SUCCESS;
    }
}
