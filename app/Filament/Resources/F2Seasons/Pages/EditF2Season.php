<?php

namespace App\Filament\Resources\F2Seasons\Pages;

use App\Filament\Resources\F2Seasons\F2SeasonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditF2Season extends EditRecord
{
    protected static string $resource = F2SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
