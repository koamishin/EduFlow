<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

/**
 * The switches that govern AI behaviour.
 *
 * Provider endpoints and their encrypted keys are managed separately under AI
 * Providers, because there can be many of them and each carries its own key.
 *
 * @property-read Schema $form
 */
class AiSettingsPage extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'AI';

    protected static ?string $navigationLabel = 'AI';

    protected string $view = 'filament.clusters.settings.pages.ai-settings-page';

    public ?array $data = [];

    /**
     * @var array<string, mixed>
     */
    public array $status = [];

    #[\Override]
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $settings = app(AiSettings::class);

        $this->status = app(AiProviderResolver::class)->describe();
        $defaultProvider = AiProvider::query()->where('is_default', true)->first();
        $openAiProvider = AiProvider::query()->where('driver', 'openai')->first();

        $compatibleProviders = AiProvider::query()
            ->where('driver', 'openai-compatible')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (AiProvider $provider): array {
                $headers = [];
                if (is_array($provider->headers)) {
                    foreach ($provider->headers as $k => $v) {
                        if (is_array($v) && isset($v['name'], $v['value'])) {
                            $headers[] = ['name' => (string) $v['name'], 'value' => (string) $v['value']];
                        } elseif (is_string($k)) {
                            $headers[] = ['name' => $k, 'value' => (string) $v];
                        }
                    }
                }

                return [
                    'id' => (string) $provider->id,
                    'name' => $provider->name,
                    'model' => $provider->model,
                    'url' => $provider->base_url,
                    'api_key' => null,
                    'headers' => $headers,
                    'is_default' => (bool) $provider->is_default,
                ];
            })
            ->all();

        $this->form->fill([
            'default_provider' => $defaultProvider?->id,
            'advisory_enabled' => $settings->advisory_enabled,
            'disclosure_accepted' => $settings->disclosure_accepted,
            'allow_settlement_proposals' => $settings->allow_settlement_proposals,
            'timeout_seconds' => $settings->timeout_seconds,
            'failover_provider' => $settings->failover_provider,
            'openai_api_key' => null,
            'openai_model' => $openAiProvider?->model ?? 'gpt-4o-mini',
            'openai_url' => $openAiProvider?->base_url,
            'openai_is_default' => (bool) ($openAiProvider?->is_default ?? false),
            'openai_compatible_providers' => $compatibleProviders,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Current provider')
                        ->description('Resolved at request time. An admin-configured provider takes precedence over .env.')
                        ->schema([
                            TextEntry::make('status_name')
                                ->label('Active provider')
                                ->state(fn (): string => (string) ($this->status['name'] ?? 'none'))
                                ->badge()
                                ->color(fn (): string => ($this->status['usable'] ?? false) ? 'success' : 'warning'),

                            TextEntry::make('status_source')
                                ->label('Configured in')
                                ->state(fn (): string => ($this->status['source'] ?? 'env') === 'admin' ? 'Admin panel' : '.env (config/ai.php)'),

                            Select::make('default_provider')
                                ->label('Default AI Provider')
                                ->placeholder('Select default provider')
                                ->options(fn (): array => AiProvider::query()->usable()->pluck('name', 'id')->all())
                                ->helperText('The provider used when no explicit provider is requested.')
                                ->native(false)
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('OpenAI')
                        ->description('Text via the Laravel AI SDK.')
                        ->collapsible()
                        ->schema([
                            Checkbox::make('openai_is_default')
                                ->label('Default provider')
                                ->helperText('Use this provider for the chat widget, essay grading, and AI question generation.')
                                ->live(),

                            Grid::make(2)->schema([
                                TextInput::make('openai_api_key')
                                    ->label('API Key')
                                    ->password()
                                    ->revealable()
                                    ->autocomplete('new-password')
                                    ->placeholder(fn (): string => $this->hasStoredApiKey('openai') ? '••••••••' : 'Enter API key')
                                    ->helperText(fn (): string => $this->hasStoredApiKey('openai')
                                        ? 'API key saved (encrypted). Leave blank to keep it; enter a new key to replace it.'
                                        : 'No API key saved. Falls back to OPENAI_API_KEY if left empty.'),

                                TextInput::make('openai_url')
                                    ->label('Base URL')
                                    ->placeholder('https://api.openai.com/v1')
                                    ->helperText('Leave empty to use the default endpoint.'),

                                TextInput::make('openai_model')
                                    ->label('Model')
                                    ->placeholder('gpt-4o-mini')
                                    ->default('gpt-4o-mini')
                                    ->helperText('Used for chat, grading, and question generation. Leave empty to use gpt-4o-mini.'),
                            ]),
                        ]),

                    Section::make('OpenAI-Compatible Providers')
                        ->key('compatible-providers-section')
                        ->columnSpanFull()
                        ->extraAttributes(['class' => 'ai-compatible-providers'])
                        ->description('Add hosted APIs, local gateways, or self-hosted models that implement the OpenAI Chat Completions API. The API key is optional; use custom headers for gateways with another authentication scheme.')
                        ->collapsible()
                        ->schema([
                            Repeater::make('openai_compatible_providers')
                                ->columnSpanFull()
                                ->columns(1)
                                ->label('Providers')
                                ->addActionLabel('Add OpenAI-Compatible Provider')
                                ->addAction(fn (Action $action): Action => $action->color('gray')->icon(null))
                                ->defaultItems(0)
                                ->collapsible()
                                ->itemLabel(fn (array $state): string => filled($state['name'] ?? null) ? $state['name'] : 'New provider')
                                ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
                                ->schema([
                                    Hidden::make('id')
                                        ->default(fn (): string => (string) Str::uuid()),

                                    Checkbox::make('is_default')
                                        ->label('Default provider')
                                        ->helperText('Use this provider for chat, essay grading, and AI question generation.')
                                        ->columnSpanFull()
                                        ->live(),

                                    Grid::make(['default' => 1, 'md' => 2])
                                        ->key('compatible-provider-fields')
                                        ->columnSpanFull()
                                        ->schema([
                                            TextInput::make('name')
                                                ->label('Provider Name')
                                                ->required()
                                                ->maxLength(80)
                                                ->distinct()
                                                ->live(onBlur: true)
                                                ->columnSpan(1)
                                                ->helperText('A descriptive label shown in AI provider pickers.'),

                                            TextInput::make('model')
                                                ->label('Model')
                                                ->required()
                                                ->maxLength(160)
                                                ->columnSpan(1)
                                                ->helperText('The text model used for chat, grading, and question generation.'),

                                            TextInput::make('url')
                                                ->label('Base URL')
                                                ->required()
                                                ->url()
                                                ->rule('regex:/^https?:\\/\\//i')
                                                ->maxLength(2048)
                                                ->columnSpan(1)
                                                ->placeholder('https://gateway.example.com/v1')
                                                ->helperText('Include the API version prefix when your gateway requires one.'),

                                            TextInput::make('api_key')
                                                ->label('Bearer API Key (optional)')
                                                ->columnSpan(1)
                                                ->password()
                                                ->revealable()
                                                ->autocomplete('new-password')
                                                ->placeholder(fn (Get $get): string => $this->hasStoredApiKey('openai-compatible', $get('id')) ? '••••••••' : 'Enter API key')
                                                ->helperText(fn (Get $get): string => $this->hasStoredApiKey('openai-compatible', $get('id'))
                                                    ? 'API key saved (encrypted). Leave blank to keep it; enter a new key to replace it.'
                                                    : 'No API key saved. Optional; an Authorization header below overrides Bearer authentication.'),
                                        ]),

                                    Repeater::make('headers')
                                        ->columnSpanFull()
                                        ->label('Custom Request Headers')
                                        ->addActionLabel('Add Header')
                                        ->addAction(fn (Action $action): Action => $action->color('gray')->icon(null))
                                        ->defaultItems(0)
                                        ->columns(2)
                                        ->schema([
                                            TextInput::make('name')
                                                ->label('Header Name')
                                                ->required()
                                                ->maxLength(255),
                                            TextInput::make('value')
                                                ->label('Header Value')
                                                ->required()
                                                ->password()
                                                ->revealable()
                                                ->maxLength(2048),
                                        ]),
                                ]),
                        ]),

                    Section::make('Advisory calls')
                        ->description('Model calls are advisory only. The deterministic policy engine remains authoritative and works with AI off.')
                        ->schema([
                            Toggle::make('advisory_enabled')
                                ->label('Allow advisory model calls')
                                ->helperText('Master switch. When off, no request leaves the application for a model call.')
                                ->live(),

                            Toggle::make('disclosure_accepted')
                                ->label('Student data may be sent to the provider')
                                ->helperText('Required before any advisory call runs. Advisory prompts include the student-stated reason for a request.')
                                ->disabled(fn (callable $get): bool => ! $get('advisory_enabled')),

                            TextInput::make('timeout_seconds')
                                ->label('Timeout (seconds)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(120)
                                ->helperText('Kept short on purpose: advisory calls must never delay a payment.'),
                        ])
                        ->columns(2),

                    Section::make('Settlement proposals')
                        ->description('Off by default. When enabled, the settlement operator may propose disbursements through Approvable tools. Policy still decides whether a human must approve, either way.')
                        ->schema([
                            Toggle::make('allow_settlement_proposals')
                                ->label('Let the AI propose disbursements')
                                ->helperText('Leave off to keep the AI annotate-only: it may classify hardship and write narrative, never propose a payment.')
                                ->disabled(fn (callable $get): bool => ! $get('advisory_enabled')),
                        ]),

                    Section::make('Failover')
                        ->description('Used when the default provider rate-limits or is overloaded.')
                        ->schema([
                            Select::make('failover_provider')
                                ->label('Failover provider')
                                ->options(fn (): array => AiProvider::query()->usable()->pluck('name', 'id')->all())
                                ->placeholder('None')
                                ->native(false)
                                ->helperText('Optional. Leave empty to fail closed instead.'),
                        ]),

                    Section::make('Add or change an endpoint')
                        ->description('Providers and their API keys live on their own screen, where each key is encrypted at rest with the application key and never rendered back.')
                        ->schema([
                            TextEntry::make('providers_hint')
                                ->label('')
                                ->state('Use the "Manage providers" button above to add an OpenAI-compatible endpoint such as Ollama, LM Studio, vLLM or a gateway.')
                                ->color('gray'),
                        ])
                        ->collapsible()
                        ->collapsed(),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save Settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function hasStoredApiKey(string $driver, mixed $id = null): bool
    {
        return AiProvider::query()
            ->where('driver', $driver)
            ->when($driver === 'openai-compatible', fn ($query) => $query->whereKey($id))
            ->whereNotNull('api_key')
            ->where('api_key', '!=', '')
            ->exists();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = app(AiSettings::class);
        $settings->advisory_enabled = (bool) ($data['advisory_enabled'] ?? false);
        $settings->disclosure_accepted = (bool) ($data['disclosure_accepted'] ?? false);
        $settings->allow_settlement_proposals = (bool) ($data['allow_settlement_proposals'] ?? false);
        $settings->timeout_seconds = (int) ($data['timeout_seconds'] ?? 20);
        $settings->failover_provider = $data['failover_provider'] ?? null;
        $settings->save();

        if (filled($data['default_provider'] ?? null)) {
            AiProvider::clearDefault();
            AiProvider::query()->where('id', $data['default_provider'])->update(['is_default' => true]);
        }

        // 1. Process standard OpenAI provider card
        $openAiProvider = AiProvider::query()->where('driver', 'openai')->first() ?? new AiProvider;
        $hasOpenAi = filled($data['openai_api_key'] ?? null) || filled($data['openai_url'] ?? null) || filled($data['openai_model'] ?? null);

        if ($hasOpenAi) {
            $openAiProvider->name = $openAiProvider->name ?: 'OpenAI';
            $openAiProvider->driver = 'openai';
            $openAiProvider->base_url = filled($data['openai_url'] ?? null) ? trim((string) $data['openai_url']) : null;
            $openAiProvider->model = filled($data['openai_model'] ?? null) ? trim((string) $data['openai_model']) : 'gpt-4o-mini';
            if (filled($data['openai_api_key'] ?? null)) {
                $openAiProvider->api_key = trim((string) $data['openai_api_key']);
            }
            $openAiProvider->is_active = true;
            $openAiProvider->is_default = (bool) ($data['openai_is_default'] ?? false);
            $openAiProvider->save();
        }

        // 2. Process OpenAI-Compatible Providers repeater
        $submittedCompatible = (array) ($data['openai_compatible_providers'] ?? []);
        $processedIds = [];
        $defaultCompatibleId = null;

        foreach ($submittedCompatible as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (blank($item['name'] ?? null)) {
                continue;
            }
            $providerRecord = null;
            if (isset($item['id']) && is_numeric($item['id'])) {
                $providerRecord = AiProvider::find((int) $item['id']);
            }
            if (! $providerRecord) {
                $providerRecord = AiProvider::where('driver', 'openai-compatible')
                    ->where('name', trim((string) $item['name']))
                    ->first() ?? new AiProvider;
            }

            $providerRecord->name = trim((string) $item['name']);
            $providerRecord->driver = 'openai-compatible';
            $providerRecord->base_url = filled($item['url'] ?? null) ? trim((string) $item['url']) : null;
            $providerRecord->model = filled($item['model'] ?? null) ? trim((string) $item['model']) : null;

            if (filled($item['api_key'] ?? null)) {
                $providerRecord->api_key = trim((string) $item['api_key']);
            }

            $headers = [];
            foreach ((array) ($item['headers'] ?? []) as $header) {
                if (is_array($header) && filled($header['name'] ?? null) && filled($header['value'] ?? null)) {
                    $headers[trim((string) $header['name'])] = trim((string) $header['value']);
                }
            }
            $providerRecord->headers = $headers !== [] ? $headers : null;
            $providerRecord->is_active = true;
            $providerRecord->is_default = (bool) ($item['is_default'] ?? false);
            $providerRecord->save();

            $processedIds[] = $providerRecord->id;
            if ($providerRecord->is_default) {
                $defaultCompatibleId = $providerRecord->id;
            }
        }

        // Remove deleted compatible providers from DB
        if ($processedIds !== []) {
            AiProvider::where('driver', 'openai-compatible')
                ->whereNotIn('id', $processedIds)
                ->delete();
        } elseif ($submittedCompatible === []) {
            AiProvider::where('driver', 'openai-compatible')->delete();
        }

        // 3. Ensure only one default provider
        if ($defaultCompatibleId !== null) {
            AiProvider::where('id', '!=', $defaultCompatibleId)->update(['is_default' => false]);
            if ($openAiProvider->exists) {
                $openAiProvider->update(['is_default' => false]);
            }
        } elseif (! empty($data['openai_is_default']) && $openAiProvider->exists) {
            AiProvider::where('id', '!=', $openAiProvider->id)->update(['is_default' => false]);
        }

        // Clear newly entered secrets and refresh saved-key indicators.
        $this->mount();

        Notification::make()
            ->success()
            ->title('AI settings saved')
            ->send();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('providers')
                ->label('Manage providers')
                ->icon('heroicon-o-server-stack')
                ->url(fn (): string => AiProviderResource::getUrl('index')),
        ];
    }
}
