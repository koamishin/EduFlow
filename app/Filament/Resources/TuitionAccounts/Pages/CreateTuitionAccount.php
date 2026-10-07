<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Pages;

use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTuitionAccount extends CreateRecord
{
    protected static string $resource = TuitionAccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        TuitionAccountResource::assertUniqueAccount($data);

        return $data;
    }
}
