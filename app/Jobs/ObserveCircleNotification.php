<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use App\Models\PaymentSubmissionOutbox;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Services\InstallationInstitution;
use App\Services\PaymentSettlementReconciler;
use App\Services\StudentBillPaymentProcessor;
use App\Services\StudentTuitionPaymentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ObserveCircleNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Circle notification observation deferred to recovery.', ['notification_id' => $this->notificationId]);
    }

    public function __construct(public string $notificationId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('circle-notification:'.$this->notificationId))->expireAfter(180)->releaseAfter(30)];
    }

    /**
     * Execute the job.
     */
    public function handle(InstallationInstitution $institutions): void
    {
        if (! config('lepton.webhooks.enabled', false)
            || ! in_array(config('lepton.default'), ['developer', 'circle-rest'], true)) {
            return;
        }
        $event = DB::table('circle_webhook_notifications')->where('notification_id', $this->notificationId)->first();
        if ($event === null || ! $event->requires_observation || $event->observed_at !== null
            || $event->chain !== config('lepton.arc.chain') || $event->provider_transaction_id === null) {
            return;
        }
        // No institution context is a configuration state, not a failure worth
        // three retries: every hint is scoped to the installed institution, and
        // an uninstalled deployment has nothing to observe.
        $institution = $institutions->current();

        if (! $institution instanceof Organization) {
            return;
        }

        $matched = false;
        if (config('eduflow.submission.enabled', false)) {
            foreach (PaymentSubmissionOutbox::query()->where('organization_id', $institution->id)
                ->where('provider_handle', $event->provider_transaction_id)->whereIn('state', ['accepted', 'submitted', 'unknown'])->get() as $entry) {
                if (($entry->snapshot['chain'] ?? null) === $event->chain) {
                    app(PaymentSettlementReconciler::class)->reconcile($entry);
                    $matched = true;
                }
            }
        }
        if (config('eduflow.student_tuition.observe_enabled', false)) {
            foreach (StudentTuitionPaymentAttempt::query()->where('organization_id', $institution->id)
                ->where('provider_handle', $event->provider_transaction_id)->whereIn('state', ['accepted', 'awaiting_verification', 'unknown'])->get() as $attempt) {
                if ($attempt->chain === $event->chain) {
                    app(StudentTuitionPaymentProcessor::class)->process($attempt->id);
                    $matched = true;
                }
            }
        }
        if (config('eduflow.student_bills.observe_enabled', false)) {
            foreach (StudentBillPayment::query()->where('organization_id', $institution->id)
                ->where('provider_handle', $event->provider_transaction_id)
                ->whereIn('submission_state', [StudentBillPayment::SUBMISSION_ACCEPTED, StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION])->get() as $payment) {
                if ($payment->chain === $event->chain) {
                    app(StudentBillPaymentProcessor::class)->process($payment->id);
                    $matched = true;
                }
            }
        }
        // A webhook can precede persistence of the provider handle. Keep unmatched
        // hints owed so scheduled recovery can observe them later. Neither this
        // job nor its recovery may create a submission or trust a webhook hash.
        if ($matched) {
            DB::table('circle_webhook_notifications')->where('id', $event->id)->update(['observed_at' => now()]);
        }
    }
}
