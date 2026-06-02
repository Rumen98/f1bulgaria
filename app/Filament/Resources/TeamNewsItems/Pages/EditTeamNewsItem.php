<?php

namespace App\Filament\Resources\TeamNewsItems\Pages;

use App\Filament\Resources\TeamNewsItems\TeamNewsItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeamNewsItem extends EditRecord
{
    protected static string $resource = TeamNewsItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
