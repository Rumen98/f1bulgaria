<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\NewsClassification;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;

/**
 * Избира визуален header за новина по класификация и съдържание. Връща само
 * метаданни (тип + данни) — самото изображение се рендира от NewsImage.vue.
 *
 * Приоритет: снимка на споменат пилот → банер на обвързания отбор →
 * очертание на спомената писта → generic банер с цвят по категория.
 */
class NewsImageResolver
{
    /**
     * @return array{type:string, data:array<string, mixed>}
     */
    public function resolve(TeamNewsItem $item): array
    {
        $text = $this->haystack($item);
        $season = Season::current();

        if ($item->classification === NewsClassification::Driver && $season !== null) {
            $driver = $this->mentionedDriver($season, $text);

            if ($driver !== null && filled($driver->photo_url)) {
                return ['type' => 'driver_photo', 'data' => [
                    'photo_url' => $driver->photo_url,
                    'name' => $driver->fullName(),
                    'color' => $driver->constructor?->color_hex ?? '#e10600',
                ]];
            }
        }

        if ($item->constructor_id !== null && $item->constructor !== null) {
            return ['type' => 'team_banner', 'data' => [
                'name' => $item->constructor->name,
                'color' => $item->constructor->color_hex ?? '#e10600',
            ]];
        }

        $circuit = $this->mentionedCircuit($text);

        if ($circuit !== null) {
            return ['type' => 'circuit_outline', 'data' => $circuit];
        }

        $classification = $item->classification ?? NewsClassification::Other;

        return ['type' => 'generic', 'data' => [
            'color' => $classification->color(),
            'label' => $classification->label(),
        ]];
    }

    private function haystack(TeamNewsItem $item): string
    {
        return mb_strtolower(trim(implode(' ', array_filter([
            $item->title_original,
            $item->title_bg,
            $item->content_snippet,
            $item->summary_bg,
        ]))));
    }

    private function mentionedDriver(Season $season, string $text): ?Driver
    {
        foreach ($season->drivers()->with('constructor')->get() as $driver) {
            $last = mb_strtolower(trim((string) $driver->last_name));

            if (mb_strlen($last) >= 3 && str_contains($text, $last)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * @return array{slug:string, name:string}|null
     */
    private function mentionedCircuit(string $text): ?array
    {
        $circuits = Race::query()
            ->whereNotNull('jolpica_id')
            ->get(['jolpica_id', 'circuit', 'country'])
            ->unique('jolpica_id');

        foreach ($circuits as $race) {
            foreach ([$race->jolpica_id, $race->country] as $keyword) {
                $needle = mb_strtolower(trim((string) $keyword));

                if (mb_strlen($needle) >= 4 && str_contains($text, $needle)) {
                    return ['slug' => $race->jolpica_id, 'name' => $race->circuit];
                }
            }
        }

        return null;
    }
}
