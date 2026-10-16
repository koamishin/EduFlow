<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ObserveCircleNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverCircleNotifications extends Command
{
    protected $signature = 'app:recover-circle-notifications {--limit=50}';

    protected $description = 'Recover verified Circle observation hints; never submit a payment';

    public function handle(): int
    {
        if (! config('lepton.webhooks.enabled', false)
            || ! in_array(config('lepton.default'), ['developer', 'circle-rest'], true)) {
            return self::SUCCESS;
        }
        $connection = (string) config('eduflow.submission.queue_connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)) {
            $this->error('Durable notification queue required.');

            return self::FAILURE;
        }
        $events = DB::table('circle_webhook_notifications')->where('requires_observation', true)
            ->whereNull('observed_at')->where('chain', config('lepton.arc.chain'))
            ->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        foreach ($events as $event) {
            ObserveCircleNotification::dispatch($event->notification_id)->onConnection($connection)->onQueue('finance-submission');
        }

        return self::SUCCESS;
    }
}
