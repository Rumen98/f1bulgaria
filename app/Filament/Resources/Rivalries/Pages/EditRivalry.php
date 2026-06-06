<?php

namespace App\Filament\Resources\Rivalries\Pages;

use App\Filament\Resources\Rivalries\RivalryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRivalry extends EditRecord
{
    protected static string $resource = RivalryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
