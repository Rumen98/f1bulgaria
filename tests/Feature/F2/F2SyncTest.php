<?php

declare(strict_types=1);

use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Services\F2\F2WikipediaSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['services.wikipedia.rate_limit_ms' => 0]);

    $melbourne = file_get_contents(base_path('tests/Fixtures/f2/melbourne-2026-round.wikitext'));
    $season = '== Race calendar ==
[[2026 Melbourne Formula 2 round]]
[[2026 Miami Formula 2 round]]';

    Http::fake(function ($request) use ($season, $melbourne) {
        $url = urldecode($request->url());
        if (str_contains($url, '2026 Formula 2 Championship')) {
            return Http::response(['parse' => ['wikitext' => $season]]);
        }
        if (str_contains($url, '2026 Melbourne Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => $melbourne]]);
        }

        // Miami кръг → още няма страница (бъдещ) → missing
        return Http::response(['error' => ['code' => 'missingtitle']]);
    });
});

function sync(): F2WikipediaSync
{
    return app(F2WikipediaSync::class);
}

it('синхронизира сезон: race, сесии, резултати, пилоти', function () {
    $result = sync()->syncYear(2026);

    expect($result['season'])->toBe(2026)
        ->and($result['rounds'])->toBe(2)
        ->and($result['races'])->toBe(1)        // Melbourne синхронизиран
        ->and($result['skipped'])->toBe(1)      // Miami липсва
        ->and($result['results'])->toBeGreaterThan(40); // ~22 спринт + ~22 главно

    $race = F2Race::first();
    expect($race->location_name)->toBe('Melbourne')
        ->and($race->circuit_jolpica_id)->toBe('albert_park')
        ->and($race->round)->toBe(1)
        ->and($race->sessions)->toHaveCount(2);
});

it('създава Цолов от Wikipedia (#6, Campos, BUL) с победа в главното', function () {
    sync()->syncYear(2026);

    $tsolov = F2Driver::query()->where('slug', 'nikola-tsolov')->first();
    expect($tsolov)->not->toBeNull()
        ->and($tsolov->car_number)->toBe(6)
        ->and($tsolov->country_code)->toBe('BUL')
        ->and($tsolov->team->name)->toBe('Campos Racing');

    $feature = F2RaceSession::query()->where('session_type', 'feature_race')->first();
    $win = F2Result::query()->where('f2_race_session_id', $feature->id)->where('f2_driver_id', $tsolov->id)->first();
    expect($win->position)->toBe(1)
        ->and((float) $win->points)->toBe(25.0);
});

it('е идемпотентна — втори синхрон не дублира', function () {
    sync()->syncYear(2026);
    $races1 = F2Race::count();
    $results1 = F2Result::count();
    $drivers1 = F2Driver::count();

    Cache::flush(); // нов синхрон дърпа отново (кешът иначе пести)
    sync()->syncYear(2026);

    expect(F2Race::count())->toBe($races1)
        ->and(F2Result::count())->toBe($results1)
        ->and(F2Driver::count())->toBe($drivers1);
});

it('командата работи end-to-end', function () {
    $this->artisan('f2:sync-wikipedia', ['--year' => '2026'])->assertSuccessful();

    expect(F2Race::where('location_name', 'Melbourne')->exists())->toBeTrue();
});

it('изчислява класиране (точки + позиция) за текущ сезон без шампион', function () {
    sync()->syncYear(2026);

    $tsolov = F2Driver::query()->where('slug', 'nikola-tsolov')->first();
    expect($tsolov->points)->toBeGreaterThan(0)
        ->and($tsolov->position)->not->toBeNull()
        ->and($tsolov->is_champion)->toBeFalse(); // текущ сезон → няма шампион още
});
