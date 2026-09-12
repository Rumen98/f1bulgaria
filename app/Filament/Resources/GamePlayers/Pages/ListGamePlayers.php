<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePlayers\Pages;

use App\Filament\Resources\GamePlayers\GamePlayerResource;
use App\Filament\Resources\GamePlayers\Widgets\GameVisitsOverview;
use Filament\Resources\Pages\ListRecords;

class ListGamePlayers extends ListRecords
{
    protected static string $resource = GamePlayerResource::class;

    /** Посещенията (вкл. гости) стоят над списъка — той вижда само регистрирани. */
    protected function getHeaderWidgets(): array
    {
        return [GameVisitsOverview::class];
    }

    public function getSubheading(): ?string
    {
        return 'Опит = всяко „Карай", рестарт или „Нова обиколка". Финалите в играта и изпратените времена за класацията са различни данни: състезанията и невалидните обиколки завършват без запис в класацията. За старите опити няма подробна история.';
    }
}
