<?php

namespace App\Filament\Resources\F2Teams\Pages;

use App\Filament\Resources\F2Teams\F2TeamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListF2Teams extends ListRecords
{
    protected static string $resource = F2TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
