<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ObserveCircleNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Yukazakiri\Lepton\Contracts\WebhookVerifier;

final class CircleWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! config('lepton.webhooks.enabled', false)
            || ! in_array(config('lepton.default'), ['developer', 'circle-rest'], true)) {
            abort(404);
        }
        $signature = $request->header('X-Circle-Signature', '');
        $keyId = $request->header('X-Circle-Key-Id', '');
        if ($signature === '' || $keyId === '') {
            return response()->json(['error' => 'Signature headers required.'], 401);
        }
        if (strlen($request->getContent()) > 1048576) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }
        try {
            $event = app(WebhookVerifier::class)->verify($request->getContent(), $signature, $keyId,
                config('lepton.webhooks.subscriptions', []));
        } catch (Throwable) {
            return response()->json(['error' => 'Notification verification failed.'], 401);
        }
        if (! in_array($event->notificationType, ['transactions.inbound', 'transactions.outbound', 'webhooks.test'], true)) {
            return response()->json(['error' => 'Unsupported notification type.'], 422);
        }
        $chain = $event->notification['blockchain'] ?? null;
        $transactionId = $event->notification['id'] ?? null;
        if ($event->notificationType !== 'webhooks.test'
            && ($chain !== config('lepton.arc.chain') || ! is_string($transactionId)
                || ! Str::isUuid($transactionId))) {
            return response()->json(['error' => 'Notification network or identity mismatch.'], 422);
        }
        $connection = (string) config('eduflow.submission.queue_connection', 'database');
        if ($event->notificationType !== 'webhooks.test' && (! in_array($connection, ['database', 'redis'], true)
            || ! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true))) {
            return response()->json(['error' => 'Durable notification queue required.'], 503);
        }
        $row = [
            'notification_id' => $event->notificationId, 'subscription_id' => $event->subscriptionId,
            'notification_type' => $event->notificationType, 'payload_digest' => $event->payloadDigest,
            'provider_timestamp' => $event->timestamp->format('Y-m-d H:i:s'), 'received_at' => now(),
            'provider_transaction_id' => $transactionId, 'chain' => $chain,
            'requires_observation' => $event->notificationType !== 'webhooks.test',
        ];
        try {
            DB::table('circle_webhook_notifications')->insert($row);
            $observed = false;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('circle_webhook_notifications')->where('notification_id', $event->notificationId)->first();
            if ($existing === null || ! hash_equals($existing->payload_digest, $event->payloadDigest)) {
                return response()->json(['error' => 'Conflicting notification identity.'], 409);
            }
            // A redelivery of an event already stored. Re-queue only while it is
            // still owed, so a lost dispatch is retried without turning every
            // repeat delivery into another observation run.
            $observed = $existing->observed_at !== null;
        }
        if ($row['requires_observation'] && ! $observed) {
            try {
                ObserveCircleNotification::dispatch($event->notificationId)->onConnection($connection)->onQueue('finance-submission');
            } catch (Throwable) {
                // Durable inbox remains owed; scheduled recovery re-delivers.
            }
        }

        return response()->json(['received' => true, 'settlement_verified' => false], 202);
    }
}
