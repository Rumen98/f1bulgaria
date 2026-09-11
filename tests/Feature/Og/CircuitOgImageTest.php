<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Models\Season;
use App\Services\Og\CircuitOgImage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['features.data_recap' => true]);
    Storage::fake('local');
});

/**
 * Затворена крива с формата на писта — в координатната система на MultiViewer
 * (числа от порядъка на хилядите, y расте нагоре).
 *
 * @return array<int, array{0: float, 1: float}>
 */
function trackOutline(int $points = 64): array
{
    $outline = [];

    for ($i = 0; $i < $points; $i++) {
        $angle = 2 * M_PI * $i / $points;
        $outline[] = [
            round(1400 + 4200 * cos($angle), 1),
            round(-300 + 1900 * sin($angle) + 700 * sin($angle * 2), 1),
        ];
    }

    return $outline;
}

function raceWithTrackMap(mixed $trackMap): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'round' => 13,
        'name' => 'Italian Grand Prix',
        'jolpica_id' => 'monza',
        'circuit' => 'Autodromo Nazionale Monza',
        'race_datetime_utc' => now()->subDays(2),
    ]);

    RaceDataRecap::factory()->create([
        'race_id' => $race->id,
        'charts' => ['total_laps' => 53, 'track_map' => $trackMap],
    ]);

    return $race;
}

it('рисува PNG с размерите за социалните карти', function () {
    $png = app(CircuitOgImage::class)->render([
        'outline' => trackOutline(),
        'corners' => [],
        'rotation' => 32.5,
    ]);

    expect($png)->not->toBeNull();

    $size = getimagesizefromstring($png);

    expect($size[0])->toBe(1200)
        ->and($size[1])->toBe(630)
        ->and($size['mime'])->toBe('image/png');
});

it('маршрутът връща картинката на пистата', function () {
    $race = raceWithTrackMap(['outline' => trackOutline(), 'corners' => [], 'rotation' => 0]);

    $response = $this->get(route('racedata.og', $race->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $size = getimagesizefromstring($response->getContent());

    expect($size[0])->toBe(1200)->and($size[1])->toBe(630);
});

it('втората заявка идва от диска, а не се рисува наново', function () {
    $race = raceWithTrackMap(['outline' => trackOutline(), 'corners' => [], 'rotation' => 0]);

    $first = $this->get(route('racedata.og', $race->id))->assertOk();

    expect(Storage::disk('local')->allFiles('og'))->toHaveCount(1);

    // Подменената услуга пада, ако рисуването се повтори.
    $renderer = Mockery::mock(CircuitOgImage::class);
    $renderer->shouldNotReceive('render');
    $this->instance(CircuitOgImage::class, $renderer);

    $second = $this->get(route('racedata.og', $race->id))->assertOk();

    expect($second->getContent())->toBe($first->getContent());
});

it('пада към линията на болида, когато очертанието липсва', function () {
    // Cloudflare отказва геометрията и `outline` остава null — линията на
    // болида описва същата писта и картинката пак се получава.
    $points = array_map(fn (array $p) => [$p[0], $p[1], 240.0], trackOutline());
    $race = raceWithTrackMap(['outline' => null, 'corners' => [], 'rotation' => 0, 'points' => $points]);

    $response = $this->get(route('racedata.og', $race->id))->assertOk();

    expect(getimagesizefromstring($response->getContent())[0])->toBe(1200);
});

it('връща 404 за рекап без карта на трасето', function () {
    $race = raceWithTrackMap(null);

    $this->get(route('racedata.og', $race->id))->assertNotFound();

    expect(Storage::disk('local')->allFiles('og'))->toBeEmpty();
});

it('връща 404, когато точките са твърде малко за писта', function () {
    $race = raceWithTrackMap(['outline' => trackOutline(8), 'corners' => [], 'rotation' => 0]);

    $this->get(route('racedata.og', $race->id))->assertNotFound();
});

it('връща 404 за състезание без готов рекап', function () {
    $season = Season::factory()->create(['year' => 2026]);
    $race = Race::factory()->create(['season_id' => $season->id]);

    $this->get(route('racedata.og', $race->id))->assertNotFound();
});

it('страницата на рекапа сочи og:image към маршрута', function () {
    $race = raceWithTrackMap(['outline' => trackOutline(), 'corners' => [], 'rotation' => 0]);
    $url = route('racedata.og', $race->id);

    $html = $this->get(route('racedata.show', $race->id))->assertOk()->getContent();

    expect($html)->toContain('property="og:image" content="'.$url.'"')
        ->and($html)->toContain('name="twitter:image" content="'.$url.'"')
        // Размерите се обявяват, за да не гадае Facebook layout-а на картата.
        ->and($html)->toContain('property="og:image:width" content="1200"')
        ->and($html)->toContain('property="og:image:height" content="630"')
        ->and($html)->toContain('name="twitter:card" content="summary_large_image"');
});

it('страница без карта на трасето пази общия банер', function () {
    // Иначе рекап без геометрия би обявил og:image, който връща 404, и в
    // емисията не остава никаква картинка — по-лошо от общия банер.
    $race = raceWithTrackMap(null);

    $html = $this->get(route('racedata.show', $race->id))->assertOk()->getContent();

    expect($html)->toContain('property="og:image" content="'.asset('og-image.jpg').'"')
        ->and($html)->not->toContain(route('racedata.og', $race->id));
});
