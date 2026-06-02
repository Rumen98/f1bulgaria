<?php

namespace App\Filament\Resources\TeamNewsSources\Pages;

use App\Filament\Resources\TeamNewsSources\TeamNewsSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeamNewsSources extends ListRecords
{
    protected static string $resource = TeamNewsSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
