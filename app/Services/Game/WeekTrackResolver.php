<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\Race;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * „Пистата на уикенда": най-близкото състезание от календара, чиято писта я
 * има в играта — кара се там, където Ф1 кара тази седмица. До 3 дни след
 * старта неделя вечер още „брои" за уикенда.
 *
 * Изнесено от GameController, защото го ползват и класацията (седмичният
 * прозорец), и тийзърът на началната страница, и каналните постове.
 */
class WeekTrackResolver
{
    /**
     * @return array{slug: string, race_id: int, week_start: Carbon}|null
     */
    public function resolve(): ?array
    {
        $cached = Cache::remember('game.week-track.v2', now()->addHour(), function (): ?array {
            $slugs = array_keys((array) config('game.tracks', []));

            if ($slugs === []) {
                return null;
            }

            /** @var Race|null $race */
            $race = Race::query()
                ->whereIn('circuit', $slugs)
                ->where('race_datetime_utc', '>=', now()->subDays(3))
                ->orderBy('race_datetime_utc')
                ->first();

            if ($race === null) {
                return null;
            }

            return [
                'slug' => $race->circuit,
                'race_id' => $race->id,
                // Седмицата тръгва от четвъртъка преди състезанието — покрива
                // и съботните спринтове.
                'week_start' => $race->race_datetime_utc->copy()->subDays(4)->startOfDay()->toIso8601String(),
            ];
        });

        if ($cached === null) {
            return null;
        }

        return [
            'slug' => $cached['slug'],
            'race_id' => $cached['race_id'],
            'week_start' => Carbon::parse($cached['week_start']),
        ];
    }

    /** Само slug-ът — колкото ползва подредбата на картите в играта. */
    public function slug(): ?string
    {
        return $this->resolve()['slug'] ?? null;
    }
}
