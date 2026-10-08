<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Render Laravel AI stream events as SSE without returning a generator from
 * the response callback.
 *
 * Laravel's response factory can consume generator callbacks in a traditional
 * PHP request, but Octane passes them through to Symfony unchanged. Symfony
 * invokes the callback without iterating its returned generator, producing an
 * empty SSE body. Writing frames directly works in both runtimes.
 */
class AiSseResponse
{
    public const STREAMING_EXECUTION_TIME_SECONDS = 180;

    public const ERROR_MESSAGE = 'ARC could not complete this request. Please try again.';

    /**
     * @param  iterable<int, mixed>  $events
     */
    public static function from(iterable $events): StreamedResponse
    {
        return response()->stream(function () use ($events): void {
            $originalLimit = (int) ini_get('max_execution_time');
            $adjusted = false;

            if ($originalLimit > 0 && $originalLimit < self::STREAMING_EXECUTION_TIME_SECONDS) {
                set_time_limit(self::STREAMING_EXECUTION_TIME_SECONDS);
                $adjusted = true;
            }

            try {
                foreach ($events as $event) {
                    if ($event instanceof StreamEvent) {
                        echo 'data: '.($event)."\n\n";
                    } elseif (is_string($event)) {
                        echo 'data: '.json_encode(['type' => 'text_delta', 'delta' => $event], JSON_THROW_ON_ERROR)."\n\n";
                    } else {
                        echo 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
                    }

                    self::flush();
                }

                echo "data: [DONE]\n\n";
                self::flush();
            } catch (\Throwable $e) {
                report($e);
                $err = self::ERROR_MESSAGE;
                echo 'data: '.json_encode(['type' => 'text_delta', 'delta' => "\n\n*({$err})*"], JSON_THROW_ON_ERROR)."\n\n";
                echo "data: [DONE]\n\n";
                self::flush();
            } finally {
                if ($adjusted) {
                    set_time_limit($originalLimit);
                }
            }
        }, 200, [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
