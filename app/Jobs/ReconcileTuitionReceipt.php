<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentTuitionPayment;
use App\Services\AutonomousPipelineBridge;
use App\Services\TuitionReceiptIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reconciles one settled portal payment into Collections as a receipt.
 *
 * The job owns no decision of its own: it re-reads the committed payment and
 * asks `TuitionReceiptIntake` whether that exact movement may enter the
 * reviewed ledger. A refusal is reported and left alone rather than retried
 * into a different outcome — the movement is already proven, so the only
 * thing missing is a configured owner or a flag, and neither arrives by
 * trying again.
 */
class ReconcileTuitionReceipt implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $paymentId)
    {
        $this->onConnection((string) config('eduflow.student_tuition.queue_connection', 'database'));
        $this->onQueue((string) config('eduflow.student_tuition.queue', 'student-tuition'));
        $this->afterCommit();
    }

    public function handle(TuitionReceiptIntake $intake): void
    {
        $payment = StudentTuitionPayment::query()->find($this->paymentId);
        if ($payment === null) {
            return;
        }

        if (! $payment->collections()->exists()) {
            $intake->reconcile($payment);
        }

        if (config('eduflow.autonomous_pipeline.enabled', true)) {
            try {
                app(AutonomousPipelineBridge::class)->bridgePayment($payment);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Tuition receipt intake exhausted retries; reconcile this movement from Collections.'));
    }
}
