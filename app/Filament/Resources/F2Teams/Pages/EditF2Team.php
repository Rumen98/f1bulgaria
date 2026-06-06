<?php

namespace App\Filament\Resources\F2Teams\Pages;

use App\Filament\Resources\F2Teams\F2TeamResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditF2Team extends EditRecord
{
    protected static string $resource = F2TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
