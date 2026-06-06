<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\F2Driver;
use App\Models\F2Season;
use App\Models\F2Team;
use Illuminate\Database\Seeder;

/**
 * Засява минимален скелет за F2 — шампионите 2017-2024 + текущ сезон с Цолов.
 * Идемпотентно (updateOrCreate по година/slug). Реалните данни се поддържат
 * ръчно през Filament.
 */
class F2SeedSeeder extends Seeder
{
    public function run(): void
    {
        $seed = config('f2-seed');

        foreach ($seed['champions'] as [$year, $isCurrent, $champion]) {
            $season = $this->season($year, $isCurrent);
            $this->driver($season, $champion, isChampion: true, position: 1);
        }

        // Текущ сезон (без обявен шампион).
        $current = F2Season::query()->updateOrCreate(
            ['year' => $seed['current']['year']],
            ['is_current' => true],
        );

        foreach ($seed['current']['drivers'] as $driver) {
            $this->driver($current, $driver, isChampion: false, position: null);
        }
    }

    private function season(int $year, bool $isCurrent): F2Season
    {
        return F2Season::query()->updateOrCreate(['year' => $year], ['is_current' => $isCurrent]);
    }

    /**
     * @param  array{0:string,1:string,2:string,3:string,4:string,5:string}  $data
     */
    private function driver(F2Season $season, array $data, bool $isChampion, ?int $position): void
    {
        [$first, $last, $slug, $country, $teamName, $teamSlug] = $data;

        $team = F2Team::query()->updateOrCreate(
            ['f2_season_id' => $season->id, 'slug' => $teamSlug],
            ['name' => $teamName],
        );

        F2Driver::query()->updateOrCreate(
            ['f2_season_id' => $season->id, 'slug' => $slug],
            [
                'f2_team_id' => $team->id,
                'first_name' => $first,
                'last_name' => $last,
                'country_code' => $country,
                'is_champion' => $isChampion,
                'position' => $position,
            ],
        );
    }
}
