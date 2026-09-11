<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Driver;
use App\Models\Race;
use App\Support\DriverName;
use Illuminate\Support\Collection;

/**
 * Превръща номера на пилот от OpenF1 в това, което сайтът показва: българско
 * име, трибуквен код, отбор и цвят.
 *
 * Резолюцията е като в OpenF1SessionSync — първо по постоянен номер, после по
 * трибуквен код: номерът се мени при смяна на отбор в рамките на сезона
 * по-често, отколкото кодът. Ако и двете се провалят, се пада към името, което
 * OpenF1 връща — един непознат пилот не бива да оставя графиката празна.
 */
class DriverResolver
{
    /**
     * Кешът е по състезание, не просто „веднъж“: една команда може да обходи
     * няколко кръга, а глобален кеш би подал пилотите на първия за всички.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $cache = [];

    /**
     * @param  Collection<int, array<string, mixed>>  $openF1Drivers
     * @return array<int, array{number:int, name:string, short:string, team:?string, colour:string, driver_id:?int, slug:?string}>
     */
    public function map(Race $race, Collection $openF1Drivers): array
    {
        if (isset($this->cache[$race->id])) {
            return $this->cache[$race->id];
        }

        $ours = Driver::query()
            ->where('season_id', $race->season_id)
            ->with('constructor:id,name,color_hex')
            ->get(['id', 'slug', 'first_name', 'last_name', 'driver_code', 'permanent_number', 'constructor_id']);

        $byNumber = $ours->keyBy('permanent_number');
        $byCode = $ours->keyBy('driver_code');

        $out = [];

        foreach ($openF1Drivers as $row) {
            $number = (int) ($row['driver_number'] ?? 0);

            if ($number === 0) {
                continue;
            }

            $acronym = (string) ($row['name_acronym'] ?? '');
            $ourDriver = $byNumber->get($number) ?? ($acronym !== '' ? $byCode->get($acronym) : null);

            $latin = trim((string) ($row['full_name'] ?? $row['broadcast_name'] ?? ''));

            $out[$number] = [
                'number' => $number,
                'name' => $ourDriver !== null
                    ? DriverName::display($ourDriver->slug, $ourDriver->first_name.' '.$ourDriver->last_name)
                    : ($latin !== '' ? $latin : '#'.$number),
                'short' => $acronym !== '' ? $acronym : ($ourDriver->driver_code ?? '#'.$number),
                'team' => $row['team_name'] ?? $ourDriver?->constructor?->name,
                // Нашият цвят печели: той е един и същ навсякъде в сайта, а
                // OpenF1 понякога дава различен оттенък за същия отбор.
                'colour' => $ourDriver?->constructor?->color_hex
                    ?? $this->hex($row['team_colour'] ?? null)
                    ?? '#83838d',
                'driver_id' => $ourDriver?->id,
                'slug' => $ourDriver?->slug,
            ];
        }

        return $this->cache[$race->id] = $out;
    }

    private function hex(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $hex = '#'.ltrim($value, '#');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? $hex : null;
    }
}
