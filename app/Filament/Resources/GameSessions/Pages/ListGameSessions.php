<?php

namespace App\Filament\Resources\GameSessions\Pages;

use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Filament\Resources\GameSessions\Widgets\GameSessionsOverview;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

class ListGameSessions extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = GameSessionResource::class;

    public function getSubheading(): ?string
    {
        return 'Едно каране = един опит („Карай", рестарт или „Нова обиколка"). Статистиката следва филтрите и обхваща само регистрирани играчи. Липсата на краен сигнал не доказва отказване; старите записи пазят само старта.';
    }

    protected function getHeaderWidgets(): array
    {
        return [GameSessionsOverview::class];
    }
}
