<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Game\CircuitGeoJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Тегли ориентирите около пистата от OpenStreetMap (Overpass) и ги кешира.
 *
 * Трибуните са това, което прави силуета разпознаваем: Монца със стените от
 * трибуни покрай Rettifilo изглежда като Монца дори преди да си видял завой.
 * Контурите им са реални и на реалните си места — извеждат се като обеми,
 * не като модели.
 *
 * Данните са ODbL. По трасето НЯМА реклами и лога — те са търговски марки и
 * стоят извън обхвата съзнателно.
 */
class FetchTrackLandmarksCommand extends Command
{
    protected $signature = 'game:fetch-landmarks
        {slug? : Само тази писта (по подразбиране всички без кеш)}
        {--force : Тегли отново, дори когато има кеш}';

    protected $description = 'Тегли и кешира сгради, трибуни и гори около трасетата от OpenStreetMap.';

    public function __construct(private readonly CircuitGeoJson $geoJson)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = $this->argument('slug');

        /** @var array<string, array<string, mixed>> $tracks */
        $tracks = config('game.tracks');

        if ($slug !== null) {
            if (! isset($tracks[$slug])) {
                $this->error("Няма писта с slug '{$slug}'.");

                return self::FAILURE;
            }

            $tracks = [$slug => $tracks[$slug]];
        }

        try {
            $features = $this->geoJson->features();
        } catch (\Throwable $e) {
            $this->error("Неуспешно зареждане на GeoJSON: {$e->getMessage()}");

            return self::FAILURE;
        }

        $directory = storage_path('app/game/landmarks');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $first = true;

        foreach ($tracks as $trackSlug => $config) {
            $path = "{$directory}/{$trackSlug}.json";

            if (file_exists($path) && ! $this->option('force')) {
                $this->line("{$trackSlug}: вече е кеширана (--force за презапис)");

                continue;
            }

            if (! isset($features[$config['feature']])) {
                $this->warn("{$trackSlug}: липсва в GeoJSON");

                continue;
            }

            if (! $first) {
                // Overpass е безплатен и споделен — не го обстрелваме.
                sleep(12);
            }
            $first = false;

            $coordinates = $this->geoJson->longestLine($features[$config['feature']]['geometry']);
            $bbox = $this->boundingBox($coordinates, (float) config('game.landmarks.radius', 400));

            $this->line("{$trackSlug}: питам Overpass...");

            try {
                $elements = $this->query($bbox);
            } catch (\Throwable $e) {
                $this->error("{$trackSlug}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $payload = $this->classify($elements);

            file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $this->info(sprintf(
                '%s: %d трибуни, %d сгради, %d горски контура',
                $trackSlug,
                count($payload['grandstands']),
                count($payload['buildings']),
                count($payload['woods']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<int, float>>  $coordinates  [lon, lat]
     * @return array{south: float, west: float, north: float, east: float}
     */
    private function boundingBox(array $coordinates, float $radiusMetres): array
    {
        $lons = array_map(fn (array $c): float => (float) $c[0], $coordinates);
        $lats = array_map(fn (array $c): float => (float) $c[1], $coordinates);

        $latPad = $radiusMetres / 111320;
        $lonPad = $latPad / max(cos(deg2rad((min($lats) + max($lats)) / 2)), 0.01);

        return [
            'south' => min($lats) - $latPad,
            'west' => min($lons) - $lonPad,
            'north' => max($lats) + $latPad,
            'east' => max($lons) + $lonPad,
        ];
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}  $bbox
     * @return array<int, array<string, mixed>>
     */
    private function query(array $bbox): array
    {
        $box = sprintf('%.6f,%.6f,%.6f,%.6f', $bbox['south'], $bbox['west'], $bbox['north'], $bbox['east']);

        // `out geom` връща координатите вградени във way-овете — спестява
        // втора обиколка за резолване на node id-та.
        $query = <<<OVERPASS
            [out:json][timeout:90];
            (
              way["building"]({$box});
              way["natural"="wood"]({$box});
              way["landuse"="forest"]({$box});
            );
            out geom;
            OVERPASS;

        // Overpass раздава слотове: при натоварване връща 429 и изчаква да се
        // освободи място. Отстъпваме и опитваме пак, вместо да се предадем.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            // Отхвърля с 406 заявките, изпратени като form поле и без
            // разпознаваем User-Agent — иска суровата заявка в тялото.
            $response = Http::timeout(120)
                ->withHeaders([
                    'User-Agent' => 'padok.bg track builder (https://padok.bg)',
                    'Accept' => 'application/json',
                ])
                ->withBody($query, 'text/plain')
                ->post((string) config('game.landmarks.endpoint'));

            if ($response->successful()) {
                return $response->json('elements', []);
            }

            if ($response->status() !== 429 && $response->status() !== 504) {
                throw new \RuntimeException("HTTP {$response->status()} от Overpass");
            }

            $wait = 15 * $attempt;
            $this->line("  Overpass е зает ({$response->status()}) — чакам {$wait}s");
            sleep($wait);
        }

        throw new \RuntimeException('Overpass отказва след 4 опита — пробвай пак по-късно');
    }

    /**
     * Разделя елементите по вид и запазва само геометрията, която ни трябва.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @return array{grandstands: array<int, array<int, array<int, float>>>, buildings: array<int, array<int, array<int, float>>>, woods: array<int, array<int, array<int, float>>>}
     */
    private function classify(array $elements): array
    {
        $out = ['grandstands' => [], 'buildings' => [], 'woods' => []];

        foreach ($elements as $element) {
            $geometry = $element['geometry'] ?? null;

            if (! is_array($geometry) || count($geometry) < 3) {
                continue;
            }

            $ring = array_map(
                fn (array $p): array => [round((float) $p['lon'], 7), round((float) $p['lat'], 7)],
                $geometry
            );

            $tags = $element['tags'] ?? [];

            if (($tags['building'] ?? null) === 'grandstand') {
                $out['grandstands'][] = $ring;
            } elseif (isset($tags['building'])) {
                $out['buildings'][] = $ring;
            } else {
                $out['woods'][] = $ring;
            }
        }

        return $out;
    }
}
