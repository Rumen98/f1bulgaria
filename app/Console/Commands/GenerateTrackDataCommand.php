<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Game\CircuitGeoJson;
use App\Services\Game\ElevationProfile;
use App\Services\Game\LandmarkBuilder;
use Illuminate\Console\Command;

/**
 * Генерира метричните данни за пистите за 3D играта.
 *
 * За разлика от circuits:generate-svgs (който нормализира към viewBox и губи
 * мащаба), тук изходът е в МЕТРИ — иначе физиката няма как да е реалистична,
 * а праговете за анти-чийт не значат нищо.
 *
 * Пайплайн: GPS полилиния → локална равнинна проекция → centripetal
 * Catmull-Rom → ресемплиране на равни разстояния → надморска височина от
 * кеша на game:fetch-elevation.
 *
 * Няма мрежа: всичко идва от файлове в storage, така че генерирането е
 * офлайн и възпроизводимо.
 */
class GenerateTrackDataCommand extends Command
{
    protected $signature = 'game:generate-tracks
        {--source= : Път до geojson (по подразбиране storage/app/bacinger-circuits.geojson)}
        {--output= : Изходна директория (по подразбиране public/game/tracks)}
        {--only= : Само тази писта (slug)}
        {--spacing=4.0 : Разстояние между точките след ресемплиране, в метри}
        {--flat : Игнорира надморската височина, дори когато е кеширана}';

    protected $description = 'Генерира метрични JSON данни за пистите, ползвани от 3D играта.';

    /** Среден радиус на Земята (WGS84), метри. */
    private const EARTH_RADIUS = 6371008.8;

    /**
     * Centripetal Catmull-Rom. α=0.5 е задължително при GPS данни: uniform
     * (α=0) прави примки и cusps там, където точките са неравномерно гъсти,
     * а в OSM трасетата завоите са далеч по-плътни от правите.
     */
    private const ALPHA = 0.5;

    public function __construct(
        private readonly CircuitGeoJson $geoJson,
        private readonly ElevationProfile $elevation,
        private readonly LandmarkBuilder $landmarks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $output = $this->option('output') ?: public_path('game/tracks');
        $spacing = max(1.0, (float) $this->option('spacing'));

        if (! is_dir($output)) {
            mkdir($output, 0755, true);
        }

        try {
            $features = $this->geoJson->features($this->option('source'));
        } catch (\Throwable $e) {
            $this->error("Неуспешно зареждане на GeoJSON: {$e->getMessage()}");

            return self::FAILURE;
        }

        $only = $this->option('only');
        /** @var array<string, array<string, mixed>> $tracks */
        $tracks = config('game.tracks');

        if ($only !== null) {
            if (! isset($tracks[$only])) {
                $this->error("Няма писта с slug '{$only}'.");

                return self::FAILURE;
            }

            $tracks = [$only => $tracks[$only]];
        }

        $index = [];

        foreach ($tracks as $slug => $config) {
            if (! isset($features[$config['feature']])) {
                $this->warn("Липсва в GeoJSON: {$slug}");

                continue;
            }

            $feature = $features[$config['feature']];
            $track = $this->buildTrack($slug, $feature, $config, $spacing);

            file_put_contents(
                "{$output}/{$slug}.json",
                json_encode($track, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            $stated = (float) ($feature['properties']['length'] ?? 0);
            $drift = $stated > 0 ? abs($track['length'] - $stated) / $stated * 100 : 0;

            $index[] = [
                'slug' => $slug,
                'name' => $feature['properties']['Name'] ?? $slug,
                'location' => $feature['properties']['Location'] ?? '',
                'length' => $track['length'],
                'elevation' => $track['elevation_range'],
            ];

            $this->line(sprintf(
                '%-14s %5.0f m  Δ%.2f%%  дениv %5.1f m  наклон %4.1f%%  %3d трибуни  %3d сгради  %3d дървета',
                $slug,
                $track['length'],
                $drift,
                $track['elevation_range'],
                $track['max_slope'] * 100,
                count($track['landmarks']['grandstands']),
                count($track['landmarks']['buildings']),
                count($track['landmarks']['trees']),
            ));
        }

        file_put_contents(
            "{$output}/index.json",
            json_encode($index, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        $this->info(count($index).' писти генерирани в '.$output);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function buildTrack(string $slug, array $feature, array $config, float $spacing): array
    {
        $coords = $this->geoJson->withoutClosingDuplicate(
            $this->geoJson->longestLine($feature['geometry'])
        );

        $project = $this->projectionFor($coords);
        $projected = $this->projectToMetres($coords, $project);
        $resampled = $this->resample($projected, $spacing);

        $heights = $this->heightsFor($slug, $config, $projected, count($resampled));

        $points = [];
        foreach ($resampled as $i => $point) {
            $points[] = [$point[0], $heights[$i] ?? 0.0, $point[1]];
        }

        $points = $this->applyStartOffset($points, (float) ($config['start_offset'] ?? 0), $spacing);

        $ys = array_column($points, 1);
        $range = $ys === [] ? 0.0 : max($ys) - min($ys);

        $landmarks = $this->landmarksFor($slug, $resampled, $project);

        return [
            'slug' => $slug,
            'name' => $feature['properties']['Name'] ?? $slug,
            'location' => $feature['properties']['Location'] ?? '',
            'length' => round(count($points) * $spacing, 1),
            'width' => (float) $config['width'],
            'spacing' => $spacing,
            'elevation_range' => round($range, 1),
            'max_slope' => round($this->maxSlope($points, $spacing), 4),
            'landmarks' => $landmarks,
            // [x, y, z] в метри; y е височина. Затворен цикъл — последната
            // точка НЕ повтаря първата, JS страната wrap-ва по модул.
            'points' => array_map(
                fn (array $p): array => [round($p[0], 2), round($p[1], 2), round($p[2], 2)],
                $points
            ),
        ];
    }

    /**
     * Височина за всяка ресемплирана точка.
     *
     * Профилът се почиства върху СУРОВИТЕ точки (там е мащабът на DEM-а), после
     * се пренася върху гъстата мрежа по нормализирано разстояние. Обратното —
     * първо сгъстяване, после филтриране — би изгладило истинските наклони.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, array<int, float>>  $projected
     * @return array<int, float>
     */
    private function heightsFor(string $slug, array $config, array $projected, int $resampledCount): array
    {
        if ($this->option('flat') || ($config['elevation'] ?? true) === false) {
            return array_fill(0, $resampledCount, 0.0);
        }

        $path = storage_path("app/game/elevation/{$slug}.json");

        if (! file_exists($path)) {
            return array_fill(0, $resampledCount, 0.0);
        }

        try {
            $cached = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->warn("{$slug}: повреден кеш на височините — трасето остава плоско");

            return array_fill(0, $resampledCount, 0.0);
        }

        $raw = $cached['elevations'] ?? [];

        if (count($raw) !== count($projected)) {
            $this->warn(sprintf(
                '%s: кешът е за %d точки, а трасето има %d — пусни game:fetch-elevation --force',
                $slug,
                count($raw),
                count($projected),
            ));

            return array_fill(0, $resampledCount, 0.0);
        }

        $segments = $this->segmentLengths($projected);

        $maxSlope = (float) ($config['max_slope'] ?? config('game.elevation.max_slope', 0.20));

        $cleaned = $this->elevation->clean(
            array_map(fn ($v) => $v === null ? null : (float) $v, $raw),
            $segments,
            $maxSlope,
            (int) config('game.elevation.smoothing_passes', 6),
        );

        $dense = $this->interpolateAlong($cleaned['profile'], $segments, $resampledCount);

        return $this->elevation->limitSlope($dense, (float) $this->option('spacing'), $maxSlope);
    }

    /**
     * Пренася профил, зададен върху рядка полилиния, върху N равноотдалечени
     * точки по същата крива.
     *
     * @param  array<int, float>  $profile
     * @param  array<int, float>  $segments
     * @return array<int, float>
     */
    private function interpolateAlong(array $profile, array $segments, int $count): array
    {
        $sourceCount = count($profile);

        // Кумулативно разстояние по суровата линия, нормализирано в [0,1).
        $cumulative = [0.0];
        $total = 0.0;
        for ($i = 0; $i < $sourceCount; $i++) {
            $total += $segments[$i];
            $cumulative[] = $total;
        }

        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $target = ($i / $count) * $total;

            // Сегментът, в който пада целта.
            $k = 0;
            while ($k < $sourceCount - 1 && $cumulative[$k + 1] < $target) {
                $k++;
            }

            $spanStart = $cumulative[$k];
            $spanLength = max($cumulative[$k + 1] - $spanStart, 1e-9);
            $t = min(1.0, max(0.0, ($target - $spanStart) / $spanLength));

            $a = $profile[$k % $sourceCount];
            $b = $profile[($k + 1) % $sourceCount];

            // Smoothstep вместо линейна интерполация: премахва изломите на
            // всяка сурова точка, които иначе се усещат като стъпала.
            $t = $t * $t * (3 - 2 * $t);

            $out[] = $a + ($b - $a) * $t;
        }

        return $out;
    }

    /**
     * Дължина на всеки сегмент от полилинията, циклично.
     *
     * @param  array<int, array<int, float>>  $points
     * @return array<int, float>
     */
    private function segmentLengths(array $points): array
    {
        $count = count($points);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % $count];
            $out[] = hypot($b[0] - $a[0], $b[1] - $a[1]);
        }

        return $out;
    }

    /**
     * Най-стръмният наклон в готовото трасе (m/m).
     *
     * @param  array<int, array<int, float>>  $points
     */
    private function maxSlope(array $points, float $spacing): float
    {
        $count = count($points);
        $worst = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $worst = max($worst, abs($points[$j][1] - $points[$i][1]) / $spacing);
        }

        return $worst;
    }

    /**
     * Локална равнинна проекция (equirectangular около центъра на пистата).
     *
     * За обект от няколко километра изкривяването е под 0.5% — проверено
     * срещу обявените дължини на пистите.
     *
     * Връща функция, а не резултат, защото ориентирите трябва да минат през
     * ТОЧНО СЪЩАТА проекция като трасето. Различен център означава трибуни на
     * стотици метри от пистата.
     *
     * @param  array<int, array<int, float>>  $coords  [lon, lat]
     * @return callable(float, float): array{0: float, 1: float}
     */
    private function projectionFor(array $coords): callable
    {
        $lats = array_map(fn (array $c): float => (float) $c[1], $coords);
        $lons = array_map(fn (array $c): float => (float) $c[0], $coords);
        $lat0 = (min($lats) + max($lats)) / 2;
        $lon0 = (min($lons) + max($lons)) / 2;
        $k = cos(deg2rad($lat0));

        $rad = M_PI / 180 * self::EARTH_RADIUS;

        return static fn (float $lon, float $lat): array => [
            ($lon - $lon0) * $k * $rad,
            ($lat - $lat0) * $rad,
        ];
    }

    /**
     * @param  array<int, array<int, float>>  $coords  [lon, lat]
     * @return array<int, array<int, float>> [x, z] в метри
     */
    private function projectToMetres(array $coords, callable $project): array
    {
        return array_map(
            fn (array $c): array => $project((float) $c[0], (float) $c[1]),
            $coords
        );
    }

    /**
     * Ориентирите около трасето, ако са кеширани от game:fetch-landmarks.
     *
     * @param  array<int, array<int, float>>  $trackPoints
     * @return array{grandstands: array<int, mixed>, buildings: array<int, mixed>, trees: array<int, mixed>}
     */
    private function landmarksFor(string $slug, array $trackPoints, callable $project): array
    {
        $empty = ['grandstands' => [], 'buildings' => [], 'trees' => []];
        $path = storage_path("app/game/landmarks/{$slug}.json");

        if (! file_exists($path)) {
            return $empty;
        }

        try {
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->warn("{$slug}: повреден кеш на ориентирите — пропускам ги");

            return $empty;
        }

        return $this->landmarks->build(
            $raw,
            $trackPoints,
            $project,
            (float) config('game.landmarks.radius', 400),
        );
    }

    /**
     * Centripetal Catmull-Rom през точките, после walk на равни разстояния.
     *
     * @param  array<int, array<int, float>>  $pts
     * @return array<int, array<int, float>>
     */
    private function resample(array $pts, float $spacing): array
    {
        $n = count($pts);
        if ($n < 4) {
            return $pts;
        }

        // Плътна дискретизация на сплайна: достатъчно ситна, че последвалият
        // arc-length walk да няма видима грешка при интерполацията.
        $dense = [];
        for ($i = 0; $i < $n; $i++) {
            $p0 = $pts[($i - 1 + $n) % $n];
            $p1 = $pts[$i];
            $p2 = $pts[($i + 1) % $n];
            $p3 = $pts[($i + 2) % $n];

            $segLength = hypot($p2[0] - $p1[0], $p2[1] - $p1[1]);
            $steps = max(2, (int) ceil($segLength / ($spacing / 4)));

            for ($s = 0; $s < $steps; $s++) {
                $dense[] = $this->catmullRom($p0, $p1, $p2, $p3, $s / $steps);
            }
        }

        return $this->walkEvenly($dense, $spacing);
    }

    /**
     * Barry-Goldman пирамидална формула за Catmull-Rom с centripetal
     * параметризация. Връща точка при t ∈ [0,1] между $p1 и $p2.
     *
     * @param  array<int, float>  $p0
     * @param  array<int, float>  $p1
     * @param  array<int, float>  $p2
     * @param  array<int, float>  $p3
     * @return array<int, float>
     */
    private function catmullRom(array $p0, array $p1, array $p2, array $p3, float $t): array
    {
        $tj = static function (float $ti, array $a, array $b): float {
            $d = hypot($b[0] - $a[0], $b[1] - $a[1]);

            // Съвпадащи точки биха дали нулев интервал и деление на нула.
            return $ti + max($d, 1e-9) ** self::ALPHA;
        };

        $t0 = 0.0;
        $t1 = $tj($t0, $p0, $p1);
        $t2 = $tj($t1, $p1, $p2);
        $t3 = $tj($t2, $p2, $p3);

        $t = $t1 + ($t2 - $t1) * $t;

        $lerp = static fn (array $a, array $b, float $w): array => [
            $a[0] + ($b[0] - $a[0]) * $w,
            $a[1] + ($b[1] - $a[1]) * $w,
        ];

        $a1 = $lerp($p0, $p1, ($t - $t0) / ($t1 - $t0));
        $a2 = $lerp($p1, $p2, ($t - $t1) / ($t2 - $t1));
        $a3 = $lerp($p2, $p3, ($t - $t2) / ($t3 - $t2));
        $b1 = $lerp($a1, $a2, ($t - $t0) / ($t2 - $t0));
        $b2 = $lerp($a2, $a3, ($t - $t1) / ($t3 - $t1));

        return $lerp($b1, $b2, ($t - $t1) / ($t2 - $t1));
    }

    /**
     * Обхожда плътната полилиния и взима точка на всеки $spacing метра.
     *
     * Стъпката се коригира леко нагоре/надолу, за да се затвори цикълът точно
     * — иначе последният сегмент е с произволна дължина и колата „подскача"
     * при преминаване през стартовата линия.
     *
     * @param  array<int, array<int, float>>  $dense
     * @return array<int, array<int, float>>
     */
    private function walkEvenly(array $dense, float $spacing): array
    {
        $n = count($dense);
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = $dense[$i];
            $b = $dense[($i + 1) % $n];
            $total += hypot($b[0] - $a[0], $b[1] - $a[1]);
        }

        $count = max(4, (int) round($total / $spacing));
        $step = $total / $count;

        $out = [$dense[0]];
        $target = $step;
        $travelled = 0.0;

        for ($i = 0; $i < $n && count($out) < $count; $i++) {
            $a = $dense[$i];
            $b = $dense[($i + 1) % $n];
            $segment = hypot($b[0] - $a[0], $b[1] - $a[1]);

            if ($segment < 1e-12) {
                continue;
            }

            while ($travelled + $segment >= $target && count($out) < $count) {
                $w = ($target - $travelled) / $segment;
                $out[] = [$a[0] + ($b[0] - $a[0]) * $w, $a[1] + ($b[1] - $a[1]) * $w];
                $target += $step;
            }

            $travelled += $segment;
        }

        return $out;
    }

    /**
     * Ротира масива, за да започне обиколката от стартовата линия.
     *
     * @param  array<int, array<int, float>>  $pts
     * @return array<int, array<int, float>>
     */
    private function applyStartOffset(array $pts, float $offset, float $spacing): array
    {
        if (abs($offset) < 1e-9 || $pts === []) {
            return $pts;
        }

        $shift = (int) round($offset / $spacing) % count($pts);
        if ($shift === 0) {
            return $pts;
        }
        if ($shift < 0) {
            $shift += count($pts);
        }

        return array_merge(array_slice($pts, $shift), array_slice($pts, 0, $shift));
    }
}
