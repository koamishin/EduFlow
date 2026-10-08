<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Agents\AdminAssistantAgent;
use App\Http\Responses\AiSseResponse;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChatHistoryController extends Controller
{
    public function sessions(Request $request): JsonResponse
    {
        $user = $this->authorizeAdmin($request);

        $sessions = ChatSession::query()
            ->where('user_id', $user?->id)
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (ChatSession $session): array => $this->sessionPayload($session))
            ->values();

        return response()->json(['data' => $sessions]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorizeAdmin($request);
        $this->authorizeManualChat();

        $session = ChatSession::create([
            'user_id' => $user?->id,
            'title' => $request->input('title') ?: 'New chat',
            'source' => 'arc',
        ]);

        $payload = $this->sessionPayload($session);

        return response()->json([
            'data' => $payload,
            'session' => $payload,
        ]);
    }

    public function show(Request $request, ChatSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $payload = $this->sessionPayload($session);

        return response()->json([
            'data' => $payload,
            'session' => $payload,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $user = $this->authorizeAdmin($request);
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json(['data' => []]);
        }

        $sessions = ChatSession::query()
            ->where('user_id', $user?->id)
            ->where(function ($q) use ($query): void {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhereHas('messages', function ($mq) use ($query): void {
                        $mq->where('content', 'like', "%{$query}%");
                    });
            })
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (ChatSession $session): array => $this->sessionPayload($session))
            ->values();

        return response()->json(['data' => $sessions]);
    }

    public function messages(Request $request, ChatSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $messages = $session->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content ?? '',
                'thinking' => $message->thinking,
                'attachments' => $message->attachments ?? [],
                'createdAt' => $message->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'data' => $messages,
            'session' => $this->sessionPayload($session),
        ]);
    }

    public function message(Request $request, ChatSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);
        $settings = $this->authorizeManualChat();

        $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $messageText = (string) $request->input('message');

        if (! $session->title || $session->title === 'New chat') {
            $session->update(['title' => Str::limit($messageText, 50)]);
        }

        $userMessage = $session->messages()->create([
            'role' => 'user',
            'content' => $messageText,
        ]);
        $session->touch();

        try {
            $history = $this->buildHistoryMessages($session, $userMessage->id);
            $provider = app(AiProviderResolver::class)->resolve();

            $agent = (new AdminAssistantAgent)->setHistory($history);
            $response = $agent->prompt($messageText, provider: $provider, timeout: $settings->timeout_seconds);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => AiSseResponse::ERROR_MESSAGE], 502);
        }

        $text = $response->text;
        $assistantMessage = $session->messages()->create([
            'role' => 'assistant',
            'content' => $text,
            'thinking' => $response->reasoning ?? null,
        ]);
        $session->touch();

        return response()->json([
            'data' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $text,
                'thinking' => $assistantMessage->thinking,
                'createdAt' => $assistantMessage->created_at?->toISOString(),
            ],
            'session' => $this->sessionPayload($session),
        ]);
    }

    public function stream(Request $request, ChatSession $session): Response
    {
        $this->authorizeSession($request, $session);
        $settings = $this->authorizeManualChat();

        $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $messageText = (string) $request->input('message');

        if (! $session->title || $session->title === 'New chat') {
            $session->update(['title' => Str::limit($messageText, 50)]);
        }

        $userMessage = $session->messages()->create([
            'role' => 'user',
            'content' => $messageText,
        ]);
        $session->touch();

        try {
            $history = $this->buildHistoryMessages($session, $userMessage->id);
            $provider = app(AiProviderResolver::class)->resolve();

            $agent = (new AdminAssistantAgent)->setHistory($history);
            $stream = $agent->stream($messageText, provider: $provider, timeout: $settings->timeout_seconds);

            $stream->then(function ($streamedResponse) use ($session): void {
                try {
                    $text = (string) $streamedResponse->text;
                    $thinking = ReasoningDelta::combine($streamedResponse->events);

                    if (trim($text) !== '' || filled($thinking)) {
                        $session->messages()->create([
                            'role' => 'assistant',
                            'content' => $text,
                            'thinking' => filled($thinking) ? $thinking : null,
                        ]);
                        $session->touch();
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return AiSseResponse::from($stream);
        } catch (Throwable $e) {
            report($e);

            return AiSseResponse::from([AiSseResponse::ERROR_MESSAGE]);
        }
    }

    public function destroy(Request $request, ChatSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $session->delete();

        return response()->json(['status' => 'success']);
    }

    private function authorizeSession(Request $request, ChatSession $session): void
    {
        $user = $this->authorizeAdmin($request);

        if ((int) $session->user_id !== (int) $user->id) {
            abort(403, 'Unauthorized access to chat session.');
        }
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasAnyRole(['admin', 'super_admin']), 403);

        return $user;
    }

    private function authorizeManualChat(): AiSettings
    {
        $settings = app(AiSettings::class);

        abort_unless($settings->manual_chat_enabled, 403, 'ARC manual chat is disabled.');
        abort_unless($settings->mayCallProvider(), 403, 'ARC provider access is disabled.');

        return $settings;
    }

    /**
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function buildHistoryMessages(ChatSession $session, int $beforeId): array
    {
        return $session->messages()
            ->where('id', '<', $beforeId)
            ->orderBy('id')
            ->get()
            ->map(function (ChatMessage $msg): UserMessage|AssistantMessage {
                if ($msg->role === 'user') {
                    return new UserMessage($msg->content ?? '');
                }

                return new AssistantMessage($msg->content ?? '');
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(ChatSession $session): array
    {
        return [
            'id' => $session->id,
            'uuid' => $session->uuid ?? (string) $session->id,
            'title' => $session->title ?: 'New chat',
            'updated_at' => $session->updated_at?->toISOString(),
            'updated_at_human' => $session->updated_at?->diffForHumans(),
        ];
    }
}
