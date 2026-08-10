<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\ConstructorCanonical;

/**
 * Записва конструкторските титли от `config/team-championships.php` върху
 * каноничните конструктори. Идемпотентно.
 *
 * По подразбиране пише само върху записи с 0 титли — ръчна корекция през
 * Filament не бива да се губи при следващ деплой/синк.
 */
class ChampionshipBackfiller
{
    /**
     * @return array{applied: array<string, int>, skipped: array<string, int>, missing: list<string>}
     */
    public function apply(bool $force = false): array
    {
        /** @var array<string, int> $titles */
        $titles = config('team-championships', []);

        $applied = [];
        $skipped = [];
        $missing = [];

        foreach ($titles as $slug => $count) {
            $canonical = ConstructorCanonical::query()->where('slug', $slug)->first();

            if ($canonical === null) {
                $missing[] = $slug;

                continue;
            }

            if ($canonical->championships_count === $count) {
                continue;
            }

            // Вече въведена ръчно стойност се пази, освен при --force.
            if (! $force && $canonical->championships_count > 0) {
                $skipped[$slug] = $canonical->championships_count;

                continue;
            }

            $canonical->update(['championships_count' => $count]);
            $applied[$slug] = $count;
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'missing' => $missing];
    }
}
