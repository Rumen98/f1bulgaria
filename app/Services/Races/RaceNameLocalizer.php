<?php

declare(strict_types=1);

namespace App\Services\Races;

use App\Models\Race;

/**
 * Връща българското име на състезание по jolpica_id (slug на пистата),
 * с fallback към оригиналното (латинско) име.
 *
 * Пистата обикновено определя името на Гран При-то, но не винаги: от 2026
 * Каталуния и Мадрид си разменят имената. Затова съществува forRace() —
 * когато има Race под ръка, той знае годината и изключенията се прилагат.
 */
class RaceNameLocalizer
{
    public function localize(?string $jolpicaId, string $fallback, ?int $year = null): string
    {
        if ($jolpicaId === null) {
            return $fallback;
        }

        if ($year !== null) {
            $override = config("race-names-bg.overrides.{$year}.{$jolpicaId}");

            if (is_string($override)) {
                return $override;
            }
        }

        return config("race-names-bg.{$jolpicaId}", $fallback);
    }

    /**
     * Годината се чете от race_datetime_utc — колона на самия ред, не от
     * връзката към сезона: този метод се вика в цикли по десетки състезания и
     * зареждане на season щеше да е N+1.
     */
    public function forRace(Race $race): string
    {
        return $this->localize(
            $race->jolpica_id,
            $race->name,
            $race->race_datetime_utc?->year,
        );
    }
}
