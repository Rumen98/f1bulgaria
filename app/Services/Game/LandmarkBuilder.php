<?php

declare(strict_types=1);

namespace App\Services\Game;

/**
 * Превръща суровите OSM ориентири в компактни данни за сцената.
 *
 * Overpass връща всичко в правоъгълника — около Интерлагос това са 4716
 * сгради, повечето от които са квартали на километър от пистата. Тук се
 * отсяват само тези, които се виждат от трасето, опростяват се контурите им
 * и горите се превръщат в позиции за дървета.
 *
 * Проекцията ПОЛЗВА ЦЕНТЪРА НА ТРАСЕТО, а не свой собствен — иначе сградите
 * се озовават на стотици метри от пистата.
 */
class LandmarkBuilder
{
    /** Максимум сгради (без трибуните, които са малко и важни). */
    private const MAX_BUILDINGS = 160;

    /** Максимум върхове на контур след опростяване. */
    private const MAX_RING_POINTS = 8;

    /** Максимум дървета на писта. */
    private const MAX_TREES = 700;

    /** През колко точки на трасето да се мери близостта (груб, но бърз филтър). */
    private const TRACK_SAMPLE_STEP = 10;

    /**
     * @param  array<string, mixed>  $raw  Кешираният отговор от Overpass
     * @param  array<int, array<int, float>>  $trackPoints  Осевата линия в метри [x, z]
     * @param  callable(float, float): array{0: float, 1: float}  $project  lon/lat → метри
     * @return array{grandstands: array<int, mixed>, buildings: array<int, mixed>, trees: array<int, array<int, float>>}
     */
    public function build(array $raw, array $trackPoints, callable $project, float $radius): array
    {
        $samples = $this->sampleTrack($trackPoints);

        $grandstands = $this->rings($raw['grandstands'] ?? [], $project, $samples, $radius);
        $buildings = $this->rings($raw['buildings'] ?? [], $project, $samples, $radius);
        $woods = $this->rings($raw['woods'] ?? [], $project, $samples, $radius, simplify: false);

        // Трибуните минават без ограничение; обикновените сгради се подреждат
        // по близост до трасето и се реже опашката.
        usort($buildings, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $buildings = array_slice($buildings, 0, self::MAX_BUILDINGS);

        return [
            'grandstands' => array_map(fn (array $b): array => $b['ring'], $grandstands),
            'buildings' => array_map(fn (array $b): array => $b['ring'], $buildings),
            'trees' => $this->scatterTrees($woods, $samples, $radius),
        ];
    }

    /**
     * Проектира, филтрира по близост и опростява контурите.
     *
     * @param  array<int, array<int, array<int, float>>>  $rings
     * @param  array<int, array<int, float>>  $samples
     * @return array<int, array{ring: array<int, array<int, float>>, distance: float}>
     */
    private function rings(array $rings, callable $project, array $samples, float $radius, bool $simplify = true): array
    {
        $out = [];

        foreach ($rings as $ring) {
            $projected = [];
            foreach ($ring as $point) {
                $projected[] = $project($point[0], $point[1]);
            }

            // OSM затваря контурите, повтаряйки първата точка.
            $last = count($projected) - 1;
            if ($last > 0
                && abs($projected[0][0] - $projected[$last][0]) < 1e-6
                && abs($projected[0][1] - $projected[$last][1]) < 1e-6) {
                array_pop($projected);
            }

            if (count($projected) < 3) {
                continue;
            }

            $distance = $this->distanceToTrack($projected, $samples);

            if ($distance > $radius) {
                continue;
            }

            $out[] = [
                'ring' => $simplify ? $this->simplify($projected) : $projected,
                'distance' => $distance,
            ];
        }

        return $out;
    }

    /**
     * Най-малкото разстояние от контур до трасето, метри.
     *
     * @param  array<int, array<int, float>>  $ring
     * @param  array<int, array<int, float>>  $samples
     */
    private function distanceToTrack(array $ring, array $samples): float
    {
        $best = INF;

        foreach ($ring as $point) {
            foreach ($samples as $sample) {
                $d = hypot($point[0] - $sample[0], $point[1] - $sample[1]);

                if ($d < $best) {
                    $best = $d;
                }
            }
        }

        return $best;
    }

    /**
     * Свежда контура до най-много MAX_RING_POINTS върха, като запазва тези с
     * най-остри ъгли — те носят формата.
     *
     * @param  array<int, array<int, float>>  $ring
     * @return array<int, array<int, float>>
     */
    private function simplify(array $ring): array
    {
        $count = count($ring);

        if ($count <= self::MAX_RING_POINTS) {
            return array_map(
                fn (array $p): array => [round($p[0], 1), round($p[1], 1)],
                $ring
            );
        }

        $scored = [];

        for ($i = 0; $i < $count; $i++) {
            $prev = $ring[($i - 1 + $count) % $count];
            $curr = $ring[$i];
            $next = $ring[($i + 1) % $count];

            $ax = $curr[0] - $prev[0];
            $az = $curr[1] - $prev[1];
            $bx = $next[0] - $curr[0];
            $bz = $next[1] - $curr[1];

            $la = hypot($ax, $az) ?: 1e-9;
            $lb = hypot($bx, $bz) ?: 1e-9;

            // Косинусът между съседните ребра: колкото по-малък, толкова
            // по-рязък е ъгълът и толкова по-важен е върхът.
            $cos = ($ax * $bx + $az * $bz) / ($la * $lb);

            $scored[] = ['index' => $i, 'score' => $cos];
        }

        usort($scored, fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        $keep = array_slice($scored, 0, self::MAX_RING_POINTS);

        $indices = array_column($keep, 'index');
        sort($indices);

        return array_map(
            fn (int $i): array => [round($ring[$i][0], 1), round($ring[$i][1], 1)],
            $indices
        );
    }

    /**
     * Разпръсква дървета в горските контури.
     *
     * Позициите са детерминирани (хеш по индекс, без rand) — иначе всяко
     * пускане на командата разбърква гората и вкарва шум в git diff-а.
     *
     * @param  array<int, array{ring: array<int, array<int, float>>, distance: float}>  $woods
     * @param  array<int, array<int, float>>  $samples
     * @return array<int, array<int, float>>
     */
    private function scatterTrees(array $woods, array $samples, float $radius): array
    {
        if ($woods === []) {
            return [];
        }

        $trees = [];
        $seed = 1;

        // Общата площ решава кой контур колко дървета получава.
        $areas = array_map(fn (array $w): float => $this->area($w['ring']), $woods);
        $totalArea = array_sum($areas);

        if ($totalArea <= 0) {
            return [];
        }

        foreach ($woods as $index => $wood) {
            $quota = (int) round(self::MAX_TREES * ($areas[$index] / $totalArea));

            if ($quota < 1) {
                continue;
            }

            $ring = $wood['ring'];
            $xs = array_column($ring, 0);
            $zs = array_column($ring, 1);
            [$minX, $maxX, $minZ, $maxZ] = [min($xs), max($xs), min($zs), max($zs)];

            $placed = 0;
            $attempts = 0;

            while ($placed < $quota && $attempts < $quota * 20) {
                $attempts++;

                $x = $minX + $this->noise($seed++) * ($maxX - $minX);
                $z = $minZ + $this->noise($seed++) * ($maxZ - $minZ);

                if (! $this->contains($ring, $x, $z)) {
                    continue;
                }

                // Дърво насред асфалта би било комично — държим ги извън
                // трасето и run-off зоната.
                if ($this->nearTrack($x, $z, $samples, 22.0)) {
                    continue;
                }

                if (! $this->nearTrack($x, $z, $samples, $radius)) {
                    continue;
                }

                $trees[] = [round($x, 1), round($z, 1), round(0.75 + $this->noise($seed++) * 0.6, 2)];
                $placed++;
            }
        }

        return array_slice($trees, 0, self::MAX_TREES);
    }

    /**
     * @param  array<int, array<int, float>>  $ring
     */
    private function area(array $ring): float
    {
        $sum = 0.0;
        $count = count($ring);

        for ($i = 0; $i < $count; $i++) {
            $a = $ring[$i];
            $b = $ring[($i + 1) % $count];
            $sum += $a[0] * $b[1] - $b[0] * $a[1];
        }

        return abs($sum) / 2;
    }

    /**
     * Ray casting: точка вътре в полигон.
     *
     * @param  array<int, array<int, float>>  $ring
     */
    private function contains(array $ring, float $x, float $z): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i][0];
            $zi = $ring[$i][1];
            $xj = $ring[$j][0];
            $zj = $ring[$j][1];

            if (($zi > $z) !== ($zj > $z)
                && $x < ($xj - $xi) * ($z - $zi) / (($zj - $zi) ?: 1e-9) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @param  array<int, array<int, float>>  $samples
     */
    private function nearTrack(float $x, float $z, array $samples, float $within): bool
    {
        foreach ($samples as $sample) {
            if (hypot($x - $sample[0], $z - $sample[1]) < $within) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<int, float>>  $trackPoints
     * @return array<int, array<int, float>>
     */
    private function sampleTrack(array $trackPoints): array
    {
        $out = [];

        for ($i = 0; $i < count($trackPoints); $i += self::TRACK_SAMPLE_STEP) {
            $out[] = $trackPoints[$i];
        }

        return $out;
    }

    /**
     * Детерминиран псевдошум в [0,1).
     */
    private function noise(int $n): float
    {
        $x = sin($n * 12.9898) * 43758.5453;

        return $x - floor($x);
    }
}
