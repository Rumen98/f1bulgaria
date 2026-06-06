<?php

namespace App\Filament\Resources\Rivalries\Pages;

use App\Filament\Resources\Rivalries\RivalryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRivalries extends ListRecords
{
    protected static string $resource = RivalryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
