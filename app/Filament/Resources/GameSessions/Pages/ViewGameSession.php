<?php

namespace App\Filament\Resources\GameSessions\Pages;

use App\Filament\Resources\GameSessions\GameSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGameSession extends ViewRecord
{
    protected static string $resource = GameSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
