<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Game\CircuitGeoJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Тегли надморската височина по осевата линия на пистата и я кешира.
 *
 * Отделена от game:generate-tracks нарочно: това е единствената стъпка, която
 * иска мрежа и се блъска в чужди rate limit-и. Кешираният резултат влиза в git,
 * така че генерирането остава офлайн и възпроизводимо.
 *
 * Точките са суровите върхове от GeoJSON-а (на 20–50 m един от друг), което
 * съвпада с резолюцията на DEM-а. По-гъсто взимане не носи информация — просто
 * пита многократно същия пиксел.
 */
class FetchTrackElevationCommand extends Command
{
    protected $signature = 'game:fetch-elevation
        {slug? : Само тази писта (по подразбиране всички без кеш)}
        {--force : Тегли отново, дори когато има кеш}
        {--dataset= : DEM датасет (по подразбиране config game.elevation.dataset)}';

    protected $description = 'Тегли и кешира надморската височина по трасетата.';

    public function __construct(private readonly CircuitGeoJson $geoJson)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dataset = $this->option('dataset') ?: config('game.elevation.dataset');
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

        $directory = storage_path('app/game/elevation');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $fetched = 0;
        $totalPoints = 0;

        foreach ($tracks as $trackSlug => $config) {
            $path = "{$directory}/{$trackSlug}.json";

            // Изключена в конфига (плоска писта) — не хаби дневния лимит на
            // API-то за данни, които generate-tracks няма да прочете.
            if (($config['elevation'] ?? true) === false) {
                $this->line("{$trackSlug}: elevation е изключена в конфига — пропускам");

                continue;
            }

            if (file_exists($path) && ! $this->option('force')) {
                $this->line("{$trackSlug}: вече е кеширана (--force за презапис)");

                continue;
            }

            if (! isset($features[$config['feature']])) {
                $this->warn("{$trackSlug}: липсва в GeoJSON");

                continue;
            }

            $coordinates = $this->geoJson->longestLine($features[$config['feature']]['geometry']);
            $coordinates = $this->geoJson->withoutClosingDuplicate($coordinates);

            $this->line("{$trackSlug}: тегля ".count($coordinates).' точки от '.$dataset.'...');

            try {
                $elevations = $this->fetch($coordinates, $dataset);
            } catch (\Throwable $e) {
                $this->error("{$trackSlug}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $known = array_values(array_filter($elevations, fn (?float $v): bool => $v !== null));

            if ($known === []) {
                $this->warn("{$trackSlug}: датасетът няма покритие тук — пропускам");

                continue;
            }

            file_put_contents($path, json_encode([
                'slug' => $trackSlug,
                'dataset' => $dataset,
                'count' => count($elevations),
                'min' => round(min($known), 1),
                'max' => round(max($known), 1),
                // null означава дупка в покритието; изглаждането я запълва.
                'elevations' => array_map(
                    fn (?float $v): ?float => $v === null ? null : round($v, 1),
                    $elevations
                ),
            ], JSON_THROW_ON_ERROR));

            $this->info(sprintf(
                '%s: %.0f–%.0f m, денивелация %.0f m',
                $trackSlug,
                min($known),
                max($known),
                max($known) - min($known),
            ));

            $fetched++;
            $totalPoints += count($coordinates);
        }

        if ($fetched > 0) {
            $this->newLine();
            $this->line("Изтеглени {$fetched} писти, {$totalPoints} точки.");
            $this->line('Данни: OpenTopoData / '.$dataset.'. Пусни game:generate-tracks, за да влязат в трасетата.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<int, float>>  $coordinates  [lon, lat]
     * @return array<int, float|null> Височини в метри, подредени като входа
     */
    private function fetch(array $coordinates, string $dataset): array
    {
        $batchSize = (int) config('game.elevation.batch_size', 100);
        $delayMs = (int) config('game.elevation.delay_ms', 1200);
        $endpoint = rtrim((string) config('game.elevation.endpoint'), '/');

        $elevations = [];
        $batches = array_chunk($coordinates, $batchSize);
        $bar = $this->output->createProgressBar(count($batches));
        $bar->start();

        foreach ($batches as $index => $batch) {
            if ($index > 0) {
                // Публичният API иска не повече от една заявка в секунда.
                usleep($delayMs * 1000);
            }

            $locations = implode('|', array_map(
                fn (array $c): string => sprintf('%.6f,%.6f', $c[1], $c[0]),
                $batch
            ));

            $response = Http::timeout(60)
                ->retry(3, 2000)
                ->get("{$endpoint}/{$dataset}", ['locations' => $locations]);

            if ($response->failed()) {
                throw new \RuntimeException("HTTP {$response->status()} от OpenTopoData");
            }

            $body = $response->json();

            if (($body['status'] ?? null) !== 'OK') {
                throw new \RuntimeException($body['error'] ?? 'непознат отговор от OpenTopoData');
            }

            foreach ($body['results'] as $result) {
                $value = $result['elevation'] ?? null;
                $elevations[] = $value === null ? null : (float) $value;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $elevations;
    }
}
