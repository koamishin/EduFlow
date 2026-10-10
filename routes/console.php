<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('eduflow:dispatch-finance-workflows')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.background_finance.enabled', false));
