<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Season;
use App\Services\Drivers\DriverPhotoFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function fetcher(): DriverPhotoFetcher
{
    return app(DriverPhotoFetcher::class);
}

it('има nullable колона photo_url в drivers', function () {
    expect(Schema::hasColumn('drivers', 'photo_url'))->toBeTrue();
});

it('връща originalimage source когато заглавието съществува', function () {
    Http::fake(['*/page/summary/*' => Http::response([
        'originalimage' => ['source' => 'https://upload.wikimedia.org/max.jpg'],
        'thumbnail' => ['source' => 'https://upload.wikimedia.org/max_thumb.jpg'],
    ])]);

    $driver = Driver::factory()->create(['first_name' => 'Max', 'last_name' => 'Verstappen']);

    expect(fetcher()->fetch($driver))->toBe('https://upload.wikimedia.org/max.jpg');
});

it('пада към thumbnail когато няма originalimage', function () {
    Http::fake(['*/page/summary/*' => Http::response([
        'thumbnail' => ['source' => 'https://upload.wikimedia.org/thumb.jpg'],
    ])]);

    $driver = Driver::factory()->create(['first_name' => 'Lando', 'last_name' => 'Norris']);

    expect(fetcher()->fetch($driver))->toBe('https://upload.wikimedia.org/thumb.jpg');
});

it('връща null когато статията не съществува', function () {
    Http::fake(['*/page/summary/*' => Http::response('', 404)]);

    $driver = Driver::factory()->create(['first_name' => 'Няма', 'last_name' => 'Такъв']);

    expect(fetcher()->fetch($driver))->toBeNull();
});

it('пада към обикновеното име, ако „(racing driver)" липсва', function () {
    Http::fake([
        '*racing*' => Http::response('', 404), // уточненото заглавие не съществува
        '*/page/summary/*' => Http::response(['originalimage' => ['source' => 'https://upload.wikimedia.org/plain.jpg']]),
    ]);

    $driver = Driver::factory()->create(['first_name' => 'George', 'last_name' => 'Russell']);

    expect(fetcher()->fetch($driver))->toBe('https://upload.wikimedia.org/plain.jpg');
});

it('пропуска disambiguation страница и опитва „Jr." заглавието (Carlos Sainz)', function () {
    Http::fake([
        // "(racing driver)" → също дисамбигуация (и баща, и син са пилоти)
        '*%28racing%20driver%29*' => Http::response(['type' => 'disambiguation']),
        // чисто "Carlos Sainz" → дисамбигуация (баща + син)
        '*summary/Carlos%20Sainz' => Http::response(['type' => 'disambiguation']),
        // "Carlos Sainz Jr." → реалната статия на пилота от F1
        '*Carlos%20Sainz%20Jr.*' => Http::response([
            'type' => 'standard',
            'originalimage' => ['source' => 'https://upload.wikimedia.org/sainz_jr.jpg'],
        ]),
    ]);

    $driver = Driver::factory()->create(['first_name' => 'Carlos', 'last_name' => 'Sainz', 'country_code' => 'ESP']);

    expect(fetcher()->fetch($driver))->toBe('https://upload.wikimedia.org/sainz_jr.jpg');
});

it('опитва заглавие с текущия отбор първо (bias към актуална снимка)', function () {
    Http::fake([
        '*Carlos%20Sainz%20Williams*' => Http::response([
            'type' => 'standard',
            'originalimage' => ['source' => 'https://upload.wikimedia.org/sainz_williams.jpg'],
        ]),
        '*/page/summary/*' => Http::response('', 404),
    ]);

    $season = Season::factory()->current()->create();
    $williams = Constructor::factory()->create(['season_id' => $season->id, 'name' => 'Williams']);
    $sainz = Driver::factory()->create([
        'season_id' => $season->id, 'constructor_id' => $williams->id,
        'first_name' => 'Carlos', 'last_name' => 'Sainz', 'country_code' => 'ESP',
    ]);

    expect(fetcher()->fetch($sainz))->toBe('https://upload.wikimedia.org/sainz_williams.jpg');
});

it('--refresh презарежда дори пилоти с вече налична снимка', function () {
    Http::fake(['*/page/summary/*' => Http::response([
        'originalimage' => ['source' => 'https://upload.wikimedia.org/new.jpg'],
    ])]);

    $season = Season::factory()->current()->create();
    Driver::factory()->create(['season_id' => $season->id, 'photo_url' => 'https://old.example/old.jpg']);

    // Без --refresh: пилотът вече има снимка → пропуска се (без презапис).
    $this->artisan('drivers:fetch-photos', ['--sleep' => 0])->assertSuccessful();
    expect(Driver::first()->photo_url)->toBe('https://old.example/old.jpg');

    // С --refresh: презаписва.
    $this->artisan('drivers:fetch-photos', ['--sleep' => 0, '--refresh' => true])->assertSuccessful();
    expect(Driver::first()->photo_url)->toBe('https://upload.wikimedia.org/new.jpg');
});

it('--all записва photo_url на каноничните пилоти (легенди)', function () {
    Http::fake(['*/page/summary/*' => Http::response([
        'originalimage' => ['source' => 'https://upload.wikimedia.org/legend.jpg'],
    ])]);

    $season = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    $canonical = DriverCanonical::create(['slug' => 'ayrton-senna', 'first_name' => 'Ayrton', 'last_name' => 'Senna']);
    Driver::factory()->create([
        'season_id' => $season->id, 'canonical_id' => $canonical->id,
        'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna',
    ]);

    $this->artisan('drivers:fetch-photos', ['--all' => true, '--sleep' => 0])->assertSuccessful();

    expect($canonical->fresh()->photo_url)->toBe('https://upload.wikimedia.org/legend.jpg');
});

it('командата записва photo_url за пилотите от текущия сезон', function () {
    Http::fake(['*/page/summary/*' => Http::response([
        'originalimage' => ['source' => 'https://upload.wikimedia.org/x.jpg'],
    ])]);

    $season = Season::factory()->current()->create();
    Driver::factory()->count(2)->create(['season_id' => $season->id]);

    $this->artisan('drivers:fetch-photos', ['--sleep' => 0])->assertSuccessful();

    expect(Driver::whereNotNull('photo_url')->count())->toBe(2)
        ->and(Driver::first()->photo_url)->toBe('https://upload.wikimedia.org/x.jpg');
});
