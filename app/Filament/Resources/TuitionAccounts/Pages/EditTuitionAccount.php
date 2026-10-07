<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Pages;

use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTuitionAccount extends EditRecord
{
    protected static string $resource = TuitionAccountResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        TuitionAccountResource::assertUniqueAccount($data, $this->record->getKey());

        return $data;
    }
}
