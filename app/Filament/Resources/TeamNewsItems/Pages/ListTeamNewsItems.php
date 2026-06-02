<?php

namespace App\Filament\Resources\TeamNewsItems\Pages;

use App\Filament\Resources\TeamNewsItems\TeamNewsItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeamNewsItems extends ListRecords
{
    protected static string $resource = TeamNewsItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
