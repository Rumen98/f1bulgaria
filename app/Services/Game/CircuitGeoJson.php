<?php

declare(strict_types=1);

namespace App\Services\Game;

use Illuminate\Support\Facades\Http;

/**
 * Достъп до изходните очертания на пистите (bacinger/f1-circuits).
 *
 * Данните произхождат от OpenStreetMap и се ползват под ODbL — виж
 * config/game.php за атрибуцията.
 */
class CircuitGeoJson
{
    private const URL = 'https://raw.githubusercontent.com/bacinger/f1-circuits/master/f1-circuits.geojson';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * Features, индексирани по id (напр. "it-1922").
     *
     * @return array<string, array<string, mixed>>
     */
    public function features(?string $source = null): array
    {
        if ($this->cache !== null && $source === null) {
            return $this->cache;
        }

        $path = $source ?: storage_path('app/bacinger-circuits.geojson');

        if (! file_exists($path)) {
            $body = Http::timeout(30)->retry(2, 500)->get(self::URL)->throw()->body();
            file_put_contents($path, $body);
        }

        $geo = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $byId = [];
        foreach ($geo['features'] ?? [] as $feature) {
            $byId[$feature['properties']['id']] = $feature;
        }

        if ($source === null) {
            $this->cache = $byId;
        }

        return $byId;
    }

    /**
     * Най-дългата линия в геометрията.
     *
     * Част от пистите са MultiLineString, в който освен основното трасе има и
     * пит лейн или алтернативни конфигурации — интересува ни най-дългата.
     *
     * @param  array<string, mixed>  $geometry
     * @return array<int, array<int, float>>
     */
    public function longestLine(array $geometry): array
    {
        if ($geometry['type'] === 'LineString') {
            return $geometry['coordinates'];
        }

        $best = [];
        foreach ($geometry['coordinates'] as $line) {
            if (count($line) > count($best)) {
                $best = $line;
            }
        }

        return $best;
    }

    /**
     * Маха повтарящата се затваряща точка на контура.
     *
     * @param  array<int, array<int, float>>  $coordinates
     * @return array<int, array<int, float>>
     */
    public function withoutClosingDuplicate(array $coordinates): array
    {
        $last = count($coordinates) - 1;

        if ($last > 0
            && abs($coordinates[0][0] - $coordinates[$last][0]) < 1e-9
            && abs($coordinates[0][1] - $coordinates[$last][1]) < 1e-9) {
            array_pop($coordinates);
        }

        return $coordinates;
    }
}
