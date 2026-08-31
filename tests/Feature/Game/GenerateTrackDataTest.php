<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Точките на трасето минават през проекция от GPS към метри и през
 * ресемплиране по сплайн. И двете стъпки могат да са тихо сгрешени — трасето
 * пак ще изглежда като писта, но мащабът ще е крив, а с него и всяко време на
 * обиколка. Затова тестваме срещу фигура с аналитично известна обиколка.
 */
const EARTH_RADIUS = 6371008.8;

/**
 * GeoJSON с правилен многоъгълник, вписан в окръжност с даден радиус.
 *
 * @return array{path: string, circumference: float}
 */
function circleFixture(float $radiusMetres, int $vertices, float $lat = 45.6, float $lon = 9.28): array
{
    $metresPerDegree = EARTH_RADIUS * M_PI / 180;
    $coordinates = [];

    for ($i = 0; $i < $vertices; $i++) {
        $angle = 2 * M_PI * $i / $vertices;

        $coordinates[] = [
            $lon + ($radiusMetres * cos($angle)) / ($metresPerDegree * cos(deg2rad($lat))),
            $lat + ($radiusMetres * sin($angle)) / $metresPerDegree,
        ];
    }

    // Затворен контур, както го дава OSM.
    $coordinates[] = $coordinates[0];

    $path = tempnam(sys_get_temp_dir(), 'track').'.geojson';
    file_put_contents($path, json_encode([
        'type' => 'FeatureCollection',
        'features' => [[
            'type' => 'Feature',
            'properties' => ['id' => 'it-1922', 'Name' => 'Тестов кръг', 'Location' => 'Тест', 'length' => 0],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        ]],
    ], JSON_THROW_ON_ERROR));

    return ['path' => $path, 'circumference' => 2 * M_PI * $radiusMetres];
}

beforeEach(function () {
    $this->output = storage_path('framework/testing/tracks-'.bin2hex(random_bytes(4)));
});

afterEach(function () {
    File::deleteDirectory($this->output);
});

it('запазва мащаба: обиколката на кръг съвпада с 2πR', function () {
    $fixture = circleFixture(800.0, 90);

    $this->artisan('game:generate-tracks', [
        '--source' => $fixture['path'],
        '--output' => $this->output,
        '--only' => 'monza',
        '--spacing' => 4.0,
    ])->assertSuccessful();

    $track = json_decode(file_get_contents("{$this->output}/monza.json"), true, 512, JSON_THROW_ON_ERROR);

    expect($track['length'])->toBeGreaterThan($fixture['circumference'] * 0.99);
    expect($track['length'])->toBeLessThan($fixture['circumference'] * 1.01);

    unlink($fixture['path']);
});

it('разполага точките на равни разстояния, включително през затварянето', function () {
    $fixture = circleFixture(600.0, 72);
    $spacing = 5.0;

    $this->artisan('game:generate-tracks', [
        '--source' => $fixture['path'],
        '--output' => $this->output,
        '--only' => 'monza',
        '--spacing' => $spacing,
    ])->assertSuccessful();

    $points = json_decode(file_get_contents("{$this->output}/monza.json"), true, 512, JSON_THROW_ON_ERROR)['points'];
    $count = count($points);

    $gaps = [];
    for ($i = 0; $i < $count; $i++) {
        $a = $points[$i];
        // Последният сегмент затваря цикъла — точно там неравномерността би
        // накарала колата да „подскочи" при пресичане на стартовата линия.
        $b = $points[($i + 1) % $count];
        // Точките са [x, y, z]; равномерността е хоризонтална, затова y се
        // прескача — иначе стръмен участък би изглеждал като по-дълъг сегмент.
        $gaps[] = hypot($b[0] - $a[0], $b[2] - $a[2]);
    }

    expect(min($gaps))->toBeGreaterThan($spacing * 0.9);
    expect(max($gaps))->toBeLessThan($spacing * 1.1);

    unlink($fixture['path']);
});

it('не повтаря първата точка накрая', function () {
    $fixture = circleFixture(400.0, 48);

    $this->artisan('game:generate-tracks', [
        '--source' => $fixture['path'],
        '--output' => $this->output,
        '--only' => 'monza',
    ])->assertSuccessful();

    $points = json_decode(file_get_contents("{$this->output}/monza.json"), true, 512, JSON_THROW_ON_ERROR)['points'];

    expect($points[0])->not->toBe($points[count($points) - 1]);

    unlink($fixture['path']);
});

it('пише каталог, който страницата може да чете', function () {
    $fixture = circleFixture(500.0, 60);

    $this->artisan('game:generate-tracks', [
        '--source' => $fixture['path'],
        '--output' => $this->output,
        '--only' => 'monza',
    ])->assertSuccessful();

    $index = json_decode(file_get_contents("{$this->output}/index.json"), true, 512, JSON_THROW_ON_ERROR);

    expect($index)->toHaveCount(1);
    expect($index[0])->toHaveKeys(['slug', 'name', 'location', 'length']);
    expect($index[0]['slug'])->toBe('monza');

    unlink($fixture['path']);
});

it('се проваля разбираемо при непознат slug', function () {
    $fixture = circleFixture(500.0, 60);

    $this->artisan('game:generate-tracks', [
        '--source' => $fixture['path'],
        '--output' => $this->output,
        '--only' => 'nurburgring',
    ])->assertFailed();

    unlink($fixture['path']);
});
