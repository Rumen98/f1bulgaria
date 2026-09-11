<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePlayers\Pages;

use App\Filament\Resources\GamePlayers\GamePlayerResource;
use Filament\Resources\Pages\ListRecords;

class ListGamePlayers extends ListRecords
{
    protected static string $resource = GamePlayerResource::class;
}
