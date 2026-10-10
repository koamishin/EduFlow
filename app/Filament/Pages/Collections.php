<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\CaptureCollectionBatch;
use App\Actions\ReviewCollectionBatch;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\User;
use App\Services\InstallationInstitution;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use UnitEnum;

class Collections extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Financial Operations';

    protected static ?string $navigationLabel = 'Collections';

    protected static ?string $title = 'Collections ledger';

    protected static ?int $navigationSort = 2;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.collections';

    #[Locked]
    public ?int $mountedBatchId = null;

    #[Locked]
    public string $mountedBatchDigest = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $mountedBatchEvidence = [];

    #[\Override]
    public static function canAccess(): bool
    {
        return Auth::user() instanceof User
            && Gate::forUser(Auth::user())->allows('create', CollectionBatch::class);
    }

    public function table(Table $table): Table
    {
        Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);
        /** @var Builder<CollectionBatch> $query */
        $query = CollectionBatch::query()
            ->where('organization_id', app(InstallationInstitution::class)->current()?->id)
            ->addSelect(['review_decision' => CollectionBatchReview::query()
                ->select('decision')
                ->whereColumn('collection_batch_id', 'collection_batches.id')
                ->limit(1)]);

        return $table
            ->query($query)
            ->heading('Received-funds evidence')
            ->description('Immutable source batches. Recorded receipt decisions are staff attestations, not bank or Arc settlement verification.')
            ->defaultSort('id', 'desc')
            ->striped()
            ->poll('10s')
            ->columns([
                TextColumn::make('id')->label('Batch')->prefix('#')->sortable(),
                TextColumn::make('source_reference')->label('Source reference')->searchable()->wrap()
                    ->description(fn (CollectionBatch $record): string => $record->source_stream),
                TextColumn::make('received_minor_units')->label('Received')->alignEnd()
                    ->formatStateUsing(fn (CollectionBatch $record): string => $this->batchMoney($record, 'received_minor_units')),
                TextColumn::make('restricted_minor_units')->label('Restricted')->alignEnd()
                    ->formatStateUsing(fn (CollectionBatch $record): string => $this->batchMoney($record, 'restricted_minor_units')),
                TextColumn::make('collected_until')->label('Collected until (UTC)')->dateTime('Y-m-d H:i', 'UTC')->sortable(),
                TextColumn::make('prepared_by')->label('Captured by')->prefix('User #')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review_decision')->label('Recorded decision')->badge()->placeholder('Awaiting review')
                    ->formatStateUsing(fn (string $state): string => $this->decisionLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'approve_receipts' => 'success', 'reject' => 'danger', default => 'warning',
                    }),
            ])
            ->filters([
                Filter::make('awaiting_review')->label('Awaiting independent review')
                    ->query(function (Builder $query): void {
                        $query->whereNotIn('id', CollectionBatchReview::query()->select('collection_batch_id'));
                    }),
            ])
            ->recordActions([
                Action::make('viewCollectionBatch')->label('Evidence')->icon(Heroicon::Eye)->color('gray')
                    ->visible(fn (CollectionBatch $record): bool => Gate::forUser(Auth::user())->allows('view', $record))
                    ->modalHeading('Collection source evidence')->modalWidth('4xl')->slideOver()
                    ->mountUsing(function (CollectionBatch $record, Schema $schema): void {
                        $this->mountBatchEvidence($record);
                        $schema->fill();
                    })
                    ->schema(fn (): array => $this->batchEvidenceSchema())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('reviewCollectionBatch')->label('Review receipts')->icon(Heroicon::ClipboardDocumentCheck)
                    ->visible(fn (CollectionBatch $record): bool => $record->getAttribute('review_decision') === null
                        && Gate::forUser(Auth::user())->allows('review', $record))
                    ->modalHeading('Independent receipt review')->modalWidth('4xl')
                    ->modalDescription('Review source, interval, amounts and restrictions. This records a receipt decision only; it does not post funds or authorize payment.')
                    ->modalSubmitActionLabel('Record receipt decision')
                    ->mountUsing(function (CollectionBatch $record, Schema $schema): void {
                        Gate::forUser(Auth::user())->authorize('review', $record);
                        $this->mountBatchEvidence($record);
                        $schema->fill();
                    })
                    ->schema(fn (): array => [
                        ...$this->batchEvidenceSchema(),
                        Section::make('Receipt decision')->schema([
                            Select::make('decision')->options([
                                'approve_receipts' => 'Approve receipts evidence',
                                'reject' => 'Reject evidence',
                                'hold' => 'Hold for clarification',
                            ])->required()->in(['approve_receipts', 'reject', 'hold']),
                            TextInput::make('verification_reference')->label('Independent verification reference')->required()->maxLength(255),
                            Textarea::make('reason')->label('Review reason')->required()->maxLength(1000)->rows(3)->columnSpanFull(),
                            Checkbox::make('attestation')
                                ->label('I independently checked this exact source evidence, received funds and restrictions. This is not payment authorization.')
                                ->accepted()->required()->columnSpanFull(),
                        ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
                    ])
                    ->action(function (CollectionBatch $record, array $data, Schema $schema, ReviewCollectionBatch $review): void {
                        Gate::forUser(Auth::user())->authorize('review', $record);
                        try {
                            if ($this->mountedBatchId !== $record->id || $this->mountedBatchDigest === '') {
                                throw ValidationException::withMessages(['attestation' => 'Reopen the review and inspect the exact batch evidence.']);
                            }
                            $review->handle($this->actor(), $record, $this->mountedBatchDigest, $data['decision'], $data['verification_reference'], $data['reason']);
                        } catch (ValidationException $exception) {
                            $this->reportActionValidation($exception, $schema);
                        }
                        Notification::make()->title('Receipt decision recorded')->body('Evidence review only. No accounts changed and no payment authorized.')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No collection evidence captured')
            ->emptyStateDescription('Capture an exact, closed source interval of received fees. Forecasts and overlapping sources cannot be counted as collections.');
    }

    /** @return list<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('captureCollectionBatch')->label('Capture collection batch')->icon(Heroicon::Plus)
                ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', CollectionBatch::class))
                ->modalHeading('Capture received-funds evidence')->modalWidth('4xl')
                ->modalDescription('Use exact decimal strings and ISO 8601 UTC source intervals. Capture is immutable; no ledger posting, student allocation or transfer occurs.')
                ->modalSubmitActionLabel('Capture evidence')
                ->mountUsing(function (Schema $schema): void {
                    Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);
                    $schema->fill(['capture_key' => (string) Str::uuid()]);
                })
                ->schema(fn (): array => $this->captureSchema())
                ->action(function (array $data, Schema $schema, CaptureCollectionBatch $capture): void {
                    Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);
                    try {
                        $batch = $capture->handle($this->actor(), $data);
                    } catch (ValidationException $exception) {
                        $this->reportActionValidation($exception, $schema);
                    }
                    Notification::make()->title('Collection evidence captured')->body('Batch #'.$batch->id.' awaits independent review. No accounts changed.')->success()->send();
                }),
        ];
    }

    /** @return list<Component> */
    private function captureSchema(): array
    {
        $rules = CaptureCollectionBatch::inputRules();

        return [
            Section::make('Source identity')->schema([
                TextInput::make('capture_key')->label('Capture key (UUID)')->required()->rules($rules['capture_key'])
                    ->helperText('Keep this key when retrying the same capture. New source evidence needs a new key.')->columnSpanFull(),
                TextInput::make('source_stream')->label('Source stream')->placeholder('tuition.bank-deposits')->required()->rules($rules['source_stream']),
                TextInput::make('source_reference')->label('Source reference')->required()->rules($rules['source_reference']),
                TextInput::make('source_document_digest')->label('Source document SHA-256')->required()->rules($rules['source_document_digest'])->columnSpanFull(),
                TextInput::make('cash_evidence_reference')->label('Received cash evidence reference')->required()->rules($rules['cash_evidence_reference'])->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Exact receipts and source interval')->schema([
                Select::make('currency')->options(array_combine(CurrencyCode::values(), CurrencyCode::values()))->required()->rules($rules['currency']),
                TextInput::make('received_amount')->label('Received amount')->inputMode('decimal')->required()->rules($rules['received_amount'])
                    ->helperText('Plain decimal; no commas or rounding. USDC allows 6 places; other currencies allow 2.'),
                TextInput::make('restricted_amount')->label('Restricted amount')->inputMode('decimal')->required()->rules($rules['restricted_amount'])
                    ->helperText('Enter 0 explicitly when no received funds are restricted.'),
                TextInput::make('collected_from')->label('Collected from (UTC)')->placeholder('2026-10-01T00:00:00+00:00')
                    ->required()->rules($rules['collected_from'])->regex('/\+00:00$/D'),
                TextInput::make('collected_until')->label('Collected until (UTC)')->placeholder('2026-10-02T00:00:00+00:00')
                    ->required()->rules($rules['collected_until'])->regex('/\+00:00$/D')
                    ->helperText('Closed interval only; end must not be in the future.'),
                Checkbox::make('source_stream_disjoint')->label('This source stream is disjoint from other captured streams; receipts are not counted twice.')
                    ->required()->accepted()->columnSpanFull(),
                Checkbox::make('received_not_forecast')->label('These funds were received, not forecast, promised or expected.')
                    ->required()->accepted()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
    }

    private function mountBatchEvidence(CollectionBatch $batch): void
    {
        Gate::forUser(Auth::user())->authorize('view', $batch);
        $review = CollectionBatchReview::query()->where('collection_batch_id', $batch->id)->first();
        $this->mountedBatchId = $batch->id;
        $this->mountedBatchDigest = $batch->snapshot_digest;
        $this->mountedBatchEvidence = [
            'id' => $batch->id, 'capture_key' => $batch->capture_key, 'prepared_by' => $batch->prepared_by,
            'source_stream' => $batch->source_stream, 'source_reference' => $batch->source_reference,
            'source_document_digest' => $batch->source_document_digest,
            'received' => $this->batchMoney($batch, 'received_minor_units'), 'restricted' => $this->batchMoney($batch, 'restricted_minor_units'),
            'collected_from' => $batch->collected_from->copy()->utc()->toIso8601String(),
            'collected_until' => $batch->collected_until->copy()->utc()->toIso8601String(),
            'cash_evidence_reference' => $batch->snapshot['cash_evidence_reference'] ?? null,
            'integrity' => $batch->hasValidSnapshot() ? 'Intact source snapshot' : 'Invalid source snapshot — review cannot proceed',
            'review_decision' => $review === null ? 'Awaiting independent review' : $this->decisionLabel($review->decision),
            'verification_reference' => $review?->verification_reference, 'reason' => $review?->reason,
            'review_digest' => $review?->review_digest,
            'review_integrity' => $review === null ? 'No review recorded' : ($review->hasValidEvidence($batch) ? 'Intact review evidence' : 'Invalid review evidence'),
        ];
    }

    /** @return list<Component> */
    private function batchEvidenceSchema(): array
    {
        return [
            Section::make('Mounted source evidence')->description('This evidence stays fixed while the review is open. Changed evidence requires closing and reopening the review.')
                ->schema([
                    $this->evidenceEntry('id', 'Batch ID'), $this->evidenceEntry('prepared_by', 'Captured by user ID'),
                    $this->evidenceEntry('received', 'Received'), $this->evidenceEntry('restricted', 'Restricted'),
                    $this->evidenceEntry('source_stream', 'Source stream'), $this->evidenceEntry('source_reference', 'Source reference'),
                    $this->evidenceEntry('collected_from', 'Collected from (UTC)'), $this->evidenceEntry('collected_until', 'Collected until (UTC)'),
                    $this->evidenceEntry('cash_evidence_reference', 'Received cash evidence reference')->columnSpanFull(),
                    $this->evidenceEntry('integrity', 'Snapshot integrity')->columnSpanFull(),
                    TextEntry::make('expected_digest')->label('Mounted snapshot digest')
                        ->state(fn (): string => $this->mountedBatchDigest)->wrap()->copyable()->columnSpanFull(),
                    $this->evidenceEntry('source_document_digest', 'Source document SHA-256')->copyable()->columnSpanFull(),
                    $this->evidenceEntry('capture_key', 'Capture key')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Recorded review')->schema([
                $this->evidenceEntry('review_decision', 'Decision'), $this->evidenceEntry('review_integrity', 'Review integrity'),
                $this->evidenceEntry('verification_reference', 'Independent verification reference')->columnSpanFull(),
                $this->evidenceEntry('reason', 'Review reason')->columnSpanFull(),
                $this->evidenceEntry('review_digest', 'Review digest')->copyable()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
    }

    private function evidenceEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)->label($label)->state(fn (): mixed => $this->mountedBatchEvidence[$key] ?? null)->wrap()->placeholder('Not recorded');
    }

    private function batchMoney(CollectionBatch $batch, string $field): string
    {
        return new Money($batch->getAttribute($field), CurrencyCode::from($batch->currency))->format().' '.$batch->currency;
    }

    private function decisionLabel(string $decision): string
    {
        return match ($decision) {
            'approve_receipts' => 'Receipts approved', 'reject' => 'Rejected', 'hold' => 'Held', default => $decision,
        };
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function reportActionValidation(ValidationException $exception, Schema $schema): never
    {
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[$schema->getStatePath().'.'.($field === 'expected_digest' ? 'attestation' : $field)] = $messages;
        }
        Notification::make()->title('Evidence operation refused')->body(collect($exception->errors())->flatten()->first())->danger()->send();

        throw ValidationException::withMessages($errors);
    }
}
