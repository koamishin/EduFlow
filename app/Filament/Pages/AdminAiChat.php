<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class AdminAiChat extends Page
{
    protected static string $layout = 'filament-panels::components.layout.base';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Intelligence';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'ARC AI Chat';

    protected static ?string $navigationLabel = 'Manual Chat';

    protected static ?string $slug = 'ai-chat';

    protected string $view = 'filament.pages.ai-chat';

    #[Locked]
    public bool $manualChatEnabled = false;

    #[Locked]
    public bool $providerCallsAllowed = false;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->manualChatEnabled = app(AiSettings::class)->manual_chat_enabled;
    }

    public function setManualChatEnabled(bool $enabled): bool
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(AiSettings::class);
        $settings->manual_chat_enabled = $enabled;
        $settings->save();
        $this->manualChatEnabled = $enabled;

        return $enabled;
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && ($user->hasRole('super_admin') || $user->hasRole('admin'));
    }

    /**
     * @return array<NavigationItem>
     */
    #[\Override]
    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->activeIcon(static::getActiveNavigationIcon())
                ->sort(static::getNavigationSort())
                ->url(static::getNavigationUrl()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $resolver = app(AiProviderResolver::class);
        $status = $resolver->describe();

        $provider = (string) ($status['driver'] ?? $status['name'] ?? '');
        $providerLabel = (string) ($status['display_name'] ?? 'Not configured');
        $model = (string) ($status['model'] ?? 'Provider default');

        $requestedParam = request()->query('c') ?? request()->query('session');
        $initialActiveSession = null;

        $sessionsQuery = ChatSession::query()
            ->where('user_id', $user?->id)
            ->latest('updated_at')
            ->limit(30)
            ->get();

        if ($requestedParam && $user) {
            $isParamUuid = is_string($requestedParam) && Str::isUuid($requestedParam);
            $isParamNumeric = is_numeric($requestedParam);

            $matchedSession = $sessionsQuery->first(fn (ChatSession $s): bool => (string) $s->id === (string) $requestedParam
                || ($isParamUuid && $s->uuid === $requestedParam));

            if (! $matchedSession && ($isParamNumeric || $isParamUuid)) {
                $matchedSession = ChatSession::query()
                    ->where('user_id', $user->id)
                    ->where(function ($q) use ($requestedParam, $isParamNumeric, $isParamUuid): void {
                        if ($isParamNumeric) {
                            $q->where('id', (int) $requestedParam);
                        } elseif ($isParamUuid) {
                            $q->where('uuid', $requestedParam);
                        }
                    })
                    ->first();

                if ($matchedSession) {
                    $sessionsQuery->prepend($matchedSession);
                }
            }

            $initialActiveSession = $matchedSession;
        }

        $initialSessions = $sessionsQuery
            ->map(fn (ChatSession $session): array => [
                'id' => $session->id,
                'uuid' => $session->uuid ?? (string) $session->id,
                'title' => $session->title ?: 'New chat',
                'updated_at' => $session->updated_at?->toISOString(),
                'updated_at_human' => $session->updated_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        $settings = app(AiSettings::class);
        $this->manualChatEnabled = $settings->manual_chat_enabled;
        $this->providerCallsAllowed = $settings->mayCallProvider();

        return [
            'providerCallsAllowed' => $this->providerCallsAllowed,
            'provider' => $provider,
            'providerLabel' => $providerLabel,
            'modelName' => $model,
            'assistantName' => 'ARC AI',
            'schoolName' => 'EduFlow',
            'workspace' => [
                'id' => 1,
                'name' => 'EduFlow Financial Platform',
            ],
            'adminUser' => [
                'id' => $user?->id,
                'name' => $user?->name ?? 'Administrator',
                'first_name' => $user?->name ? explode(' ', trim($user->name))[0] : 'Administrator',
                'email' => $user?->email,
            ],
            'initialSessions' => $initialSessions,
            'initialActiveSession' => $initialActiveSession ? [
                'id' => $initialActiveSession->id,
                'uuid' => $initialActiveSession->uuid ?? (string) $initialActiveSession->id,
                'title' => $initialActiveSession->title ?: 'New chat',
            ] : null,
            'activityUrl' => ArcAiActivity::getUrl(),
        ];
    }
}
