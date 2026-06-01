<?php

namespace App\Filament\Resources\ConstructorResource\Pages;

use App\Filament\Resources\ConstructorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConstructors extends ListRecords
{
    protected static string $resource = ConstructorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
