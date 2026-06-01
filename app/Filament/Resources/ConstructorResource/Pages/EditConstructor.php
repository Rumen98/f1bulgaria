<?php

namespace App\Filament\Resources\ConstructorResource\Pages;

use App\Filament\Resources\ConstructorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConstructor extends EditRecord
{
    protected static string $resource = ConstructorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
