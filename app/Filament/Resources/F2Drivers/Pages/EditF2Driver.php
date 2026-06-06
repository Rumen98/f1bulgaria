<?php

namespace App\Filament\Resources\F2Drivers\Pages;

use App\Filament\Resources\F2Drivers\F2DriverResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditF2Driver extends EditRecord
{
    protected static string $resource = F2DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
