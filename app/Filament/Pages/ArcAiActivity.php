<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Models\AgentDecision;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\InstallationInstitution;
use App\Settings\AiSettings;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class ArcAiActivity extends Page
{
    protected static string $layout = 'filament-panels::components.layout.base';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Intelligence';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'ARC AI Activity';

    protected static ?string $navigationLabel = 'ARC AI Activity';

    protected static ?string $slug = 'ai-activity';

    protected string $view = 'filament.pages.ai-activity';

    #[Locked]
    public ?int $selectedDecisionId = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function showDecision(int $id): void
    {
        abort_unless(static::canAccess(), 403);

        $institution = app(InstallationInstitution::class)->current();
        abort_unless($institution !== null, 404);

        $decision = AgentDecision::query()
            ->where('organization_id', $institution->id)
            ->findOrFail($id);

        $this->selectedDecisionId = $decision->id;
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
        assert($user instanceof User || $user === null);
        $resolver = app(AiProviderResolver::class);
        $status = $resolver->describe();

        $provider = (string) ($status['driver'] ?? $status['name'] ?? '');
        $providerLabel = (string) ($status['display_name'] ?? 'Not configured');
        $model = (string) ($status['model'] ?? 'Provider default');

        $institution = app(InstallationInstitution::class)->current();
        $decisions = $institution === null ? collect() : AgentDecision::query()
            ->where('organization_id', $institution->id)
            ->latest('id')
            ->limit(30)
            ->get();
        $selectedDecision = $institution === null || $this->selectedDecisionId === null ? null : AgentDecision::query()
            ->where('organization_id', $institution->id)
            ->find($this->selectedDecisionId);
        $settings = app(AiSettings::class);
        $providerCallsAllowed = $settings->mayCallProvider();

        $userName = $user instanceof User ? $user->name : 'Administrator';
        $firstName = $user instanceof User && $user->name !== '' ? explode(' ', trim($user->name))[0] : 'Administrator';

        return [
            'cycleUrl' => route('finance.autonomous-cycle.store'),
            'cycleAvailable' => $institution?->primaryWallet() !== null,
            'decisions' => $decisions,
            'selectedDecision' => $selectedDecision,
            'decisionLogUrl' => AgentDecisionResource::getUrl('index', panel: 'admin'),
            'providerCallsAllowed' => $providerCallsAllowed,
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
                'name' => $userName,
                'first_name' => $firstName,
                'email' => $user?->email,
            ],
            'chatUrl' => AdminAiChat::getUrl(),
        ];
    }
}
