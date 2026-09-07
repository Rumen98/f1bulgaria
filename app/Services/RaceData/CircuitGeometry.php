<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Очертанието на пистата и номерата на завоите.
 *
 * Източникът е MultiViewer — самият OpenF1 сочи натам през полето
 * `meetings.circuit_info_url`. Координатната система е СЪЩАТА като на
 * `/v1/location`, така че линията на болида ляга върху очертанието без никакво
 * преобразуване. Това е причината да ползваме точно този източник, а не да си
 * рисуваме писта.
 *
 * Две особености, платени с опити:
 *   1. Без браузърски User-Agent Cloudflare връща 403 (error 1010).
 *   2. Геометрията е кеширана от по-стар сезон — за Монца/2026 обектът вътре
 *      носи "year": 2021. За очертание и номера на завои това е без значение;
 *      не го ползвай за нещо, което зависи от промени по трасето.
 *
 * Липсата не е грешка: картата просто се рисува без номера на завоите.
 */
class CircuitGeometry
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    /** Геометрията на писта не се мени между кръговете — кешът е дълъг. */
    private const TTL_DAYS = 30;

    /**
     * @return array{outline: array<int, array{0: float, 1: float}>, corners: array<int, array{number:int, x:float, y:float}>, rotation: float}|null
     */
    public function forCircuit(?int $circuitKey, ?int $year): ?array
    {
        if ($circuitKey === null || $year === null) {
            return null;
        }

        return Cache::remember(
            "circuit-geometry:{$circuitKey}:{$year}",
            now()->addDays(self::TTL_DAYS),
            fn () => $this->fetch($circuitKey, $year),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(int $circuitKey, int $year): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::UA])
                ->acceptJson()
                ->timeout(10)
                ->get("https://api.multiviewer.app/api/v1/circuits/{$circuitKey}/{$year}");

            if (! $response->successful()) {
                Log::info('Геометрията на пистата е недостъпна', [
                    'circuit' => $circuitKey,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();
        } catch (Throwable $e) {
            Log::info('Геометрията на пистата хвърли изключение', ['error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($data) || ! isset($data['x'], $data['y']) || ! is_array($data['x'])) {
            return null;
        }

        $xs = array_values($data['x']);
        $ys = array_values($data['y']);
        $outline = [];

        foreach ($xs as $i => $x) {
            if (isset($ys[$i]) && is_numeric($x) && is_numeric($ys[$i])) {
                $outline[] = [(float) $x, (float) $ys[$i]];
            }
        }

        if (count($outline) < 20) {
            return null;
        }

        return [
            'outline' => $outline,
            'corners' => $this->corners($data['corners'] ?? []),
            'rotation' => is_numeric($data['rotation'] ?? null) ? (float) $data['rotation'] : 0.0,
        ];
    }

    /**
     * @return array<int, array{number:int, x:float, y:float}>
     */
    private function corners(mixed $corners): array
    {
        if (! is_array($corners)) {
            return [];
        }

        $out = [];

        foreach ($corners as $corner) {
            $position = $corner['trackPosition'] ?? null;

            if (! is_array($position) || ! isset($corner['number'], $position['x'], $position['y'])) {
                continue;
            }

            $out[] = [
                'number' => (int) $corner['number'],
                'x' => (float) $position['x'],
                'y' => (float) $position['y'],
            ];
        }

        return $out;
    }
}
