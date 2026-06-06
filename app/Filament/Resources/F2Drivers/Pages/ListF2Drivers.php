<?php

namespace App\Filament\Resources\F2Drivers\Pages;

use App\Filament\Resources\F2Drivers\F2DriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListF2Drivers extends ListRecords
{
    protected static string $resource = F2DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
