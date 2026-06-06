<?php

namespace App\Filament\Resources\F2Seasons\Pages;

use App\Filament\Resources\F2Seasons\F2SeasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListF2Seasons extends ListRecords
{
    protected static string $resource = F2SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
