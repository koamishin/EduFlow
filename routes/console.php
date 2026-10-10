<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('eduflow:dispatch-finance-workflows')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.background_finance.enabled', false));

// Durably owed submissions are handed to a worker only when the submission
// runtime is explicitly enabled. Overlap control matters here: two ticks
// racing on the same row would be the difference between one owed payment and
// two attempts at it.
Schedule::command('eduflow:dispatch-payment-submissions')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.submission.enabled', false));

Schedule::command('eduflow:dispatch-payment-submissions --recover-stale')->everyFiveMinutes()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.submission.enabled', false));

// Reconciliation is what turns a provider reference into a proven settlement,
// so it runs on its own schedule and without `--mirror`: reading the chain to
// decide whether money moved must not be coupled to writing local ledger rows.
// Mirroring stays an explicit operator action, because a ledger row is a
// record the institution is choosing to keep.
Schedule::command('eduflow:reconcile-payment-submissions')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.submission.enabled', false));
