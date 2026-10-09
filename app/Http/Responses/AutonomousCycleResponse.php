<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Agents\EduFlowAgent;
use App\Models\Organization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Yukazakiri\Lepton\Support\LeptonCliException;

class AutonomousCycleResponse
{
    public static function from(EduFlowAgent $agent, Organization $institution, Lock $lock, string $runId): StreamedResponse
    {
        // Direct frames, not a generator: Octane invokes Symfony stream callbacks directly.
        return response()->stream(function () use ($agent, $institution, $lock, $runId): void {
            $originalLimit = (int) ini_get('max_execution_time');
            $originalIgnoreAbort = ignore_user_abort(true);
            $sequence = 0;
            $emit = static function (array $event) use ($runId, &$sequence): void {
                self::emit([
                    ...$event,
                    'run_id' => $runId,
                    'sequence' => $sequence++,
                    'occurred_at' => now()->toISOString(),
                ]);
            };

            try {
                set_time_limit(180);
                $emit([
                    'type' => 'cycle_started',
                    'phase' => 'start',
                    'title' => 'Autonomous cycle started',
                    'summary' => 'Evaluating institution obligations using deterministic policy. Closing this page does not cancel payments.',
                ]);

                $result = $agent->runAutonomousCycle($institution, static function (array $progress) use ($emit): void {
                    $emit([
                        ...array_intersect_key($progress, array_flip(['phase', 'title', 'summary', 'decision_id', 'policy', 'status', 'reference'])),
                        'type' => 'cycle_progress',
                    ]);
                });
                /** @var array{auto_paid: int, escalated: int, held: int, rejected: int, total_disbursed_usdc: float} $stats */
                $stats = $result['stats'];
                $emit([
                    'type' => 'cycle_completed',
                    'phase' => 'complete',
                    'title' => 'Cycle completed',
                    'summary' => 'Local workflow results recorded. Simulated payments and provider responses are not verified on-chain settlement. Review transactions and reconcile payment evidence.',
                    'stats' => [
                        'auto_paid' => $stats['auto_paid'],
                        'escalated' => $stats['escalated'],
                        'held' => $stats['held'],
                        'rejected' => $stats['rejected'],
                        'total_disbursed_usdc' => number_format($stats['total_disbursed_usdc'], 6, '.', ''),
                    ],
                ]);
            } catch (Throwable $exception) {
                $insufficientFunds = $exception instanceof LeptonCliException
                    && str_starts_with(strtolower($exception->stderr), 'error: service returned error 400: the asset amount owned by the wallet is insufficient for the transaction');

                // CLI exceptions contain credential-bearing arguments; never report the exception.
                Log::log($insufficientFunds ? 'warning' : 'error', 'Autonomous financial cycle stopped before completion.', [
                    'reference' => $runId,
                    'organization_id' => $institution->id,
                    'reason' => $insufficientFunds ? 'insufficient_funds' : 'unexpected_error',
                    'exception_type' => $exception::class,
                ]);

                $message = $insufficientFunds
                    ? 'The treasury wallet does not have enough USDC for the next payment. Check its live Arc balance and allow for network fees.'
                    : 'An unexpected error interrupted the cycle. Payment status could not be confirmed.';
                $emit([
                    'type' => 'cycle_failed',
                    'phase' => 'error',
                    'title' => $insufficientFunds ? 'Cycle stopped: insufficient funds' : 'Cycle stopped before completion',
                    'summary' => $message.' Earlier payments may have completed. Review transactions and reconcile on-chain evidence before running another cycle. Reference: '.$runId.'.',
                    'reason' => $insufficientFunds ? 'insufficient_funds' : 'unexpected_error',
                ]);
            } finally {
                try {
                    $lock->release();
                } catch (Throwable $exception) {
                    Log::error('Autonomous financial cycle lock could not be released.', [
                        'reference' => $runId,
                        'organization_id' => $institution->id,
                        'exception_type' => $exception::class,
                    ]);
                } finally {
                    set_time_limit($originalLimit);
                    ignore_user_abort((bool) $originalIgnoreAbort);
                }
            }
        }, 200, [
            'Cache-Control' => 'no-store, no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function emit(array $event): void
    {
        if (connection_aborted()) {
            return;
        }

        // Presentation failures must not interrupt or change a financial operation.
        try {
            echo 'data: '.json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        } catch (Throwable) {
            // No retry: the browser will treat an incomplete stream as an unknown outcome.
        }
    }
}
