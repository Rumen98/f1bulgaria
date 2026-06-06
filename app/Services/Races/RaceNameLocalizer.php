<?php

declare(strict_types=1);

namespace App\Services\Races;

/**
 * Връща българското име на състезание по jolpica_id (slug на пистата),
 * с fallback към оригиналното (латинско) име.
 */
class RaceNameLocalizer
{
    public function localize(?string $jolpicaId, string $fallback): string
    {
        if ($jolpicaId === null) {
            return $fallback;
        }

        return config("race-names-bg.{$jolpicaId}", $fallback);
    }
}
