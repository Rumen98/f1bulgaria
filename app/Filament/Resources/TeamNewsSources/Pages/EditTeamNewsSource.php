<?php

namespace App\Filament\Resources\TeamNewsSources\Pages;

use App\Filament\Resources\TeamNewsSources\TeamNewsSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeamNewsSource extends EditRecord
{
    protected static string $resource = TeamNewsSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
