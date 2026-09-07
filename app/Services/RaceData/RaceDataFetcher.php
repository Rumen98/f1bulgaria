<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Services\LiveTiming\OpenF1Client;
use Illuminate\Support\Collection;

/**
 * Дърпа всичко нужно за един рекап — единадесет заявки, под мегабайт общо.
 *
 * Всички методи на клиента тук са „историческите“ с 24-часов кеш: сесията е
 * приключила, данните ѝ вече не се менят. Затова повторно пускане на командата
 * (напр. след поправка в текста) не струва нито една нова заявка към OpenF1.
 */
class RaceDataFetcher
{
    public function __construct(private readonly OpenF1Client $client) {}

    public function fetch(RaceSessionKeys $keys): RaceDataBundle
    {
        return new RaceDataBundle(
            drivers: $this->client->getFinishedDrivers($keys->race),
            result: $this->client->getSessionResult($keys->race),
            laps: $this->client->getFinishedLaps($keys->race),
            stints: $this->client->getFinishedStints($keys->race),
            pits: $this->client->getPitStops($keys->race),
            raceControl: $this->client->getRaceControl($keys->race),
            weather: $this->client->getWeather($keys->race),
            // Решетката виси при квалификацията. Без неин ключ секцията
            // „спечелени позиции“ просто не се показва — не е фатално.
            grid: $keys->qualifying !== null
                ? $this->client->getStartingGrid($keys->qualifying)
                : new Collection,
            overtakes: $this->client->getOvertakes($keys->race),
            championshipDrivers: $this->client->getChampionshipDrivers($keys->race),
            championshipTeams: $this->client->getChampionshipTeams($keys->race),
            // Най-тежката заявка в набора (~3 MB). Дърпа се веднъж и от нея
            // остават само няколко числа — суровите редове не се пазят.
            intervals: $this->client->getRaceIntervals($keys->race),
            teamRadio: $this->client->getTeamRadio($keys->race),
        );
    }
}
