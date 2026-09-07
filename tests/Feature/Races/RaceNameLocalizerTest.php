<?php

declare(strict_types=1);

use App\Models\Race;
use App\Services\Races\RaceNameLocalizer;
use Illuminate\Support\Carbon;

function localizer(): RaceNameLocalizer
{
    return app(RaceNameLocalizer::class);
}

it('превежда известните състезания по jolpica_id', function () {
    expect(localizer()->localize('albert_park', 'Australian Grand Prix'))->toBe('Гран при на Австралия')
        ->and(localizer()->localize('monaco', 'Monaco Grand Prix'))->toBe('Гран при на Монако')
        ->and(localizer()->localize('spa', 'Belgian Grand Prix'))->toBe('Гран при на Белгия');
});

it('пада към оригиналното име за непознати/липсващи', function () {
    expect(localizer()->localize('unknown_circuit', 'Some Grand Prix'))->toBe('Some Grand Prix')
        ->and(localizer()->localize(null, 'Fallback GP'))->toBe('Fallback GP');
});

it('конфигът покрива активните писти', function () {
    $names = config('race-names-bg');
    expect($names)->toHaveKeys(['albert_park', 'monaco', 'silverstone', 'monza', 'suzuka', 'spa', 'interlagos', 'yas_marina'])
        ->and(count($names))->toBeGreaterThanOrEqual(24);
});

it('прилага изключението по сезон за 2026: Каталуния е Барселона, Мадрид е Испания', function () {
    // Jolpica връща „Barcelona Grand Prix“ за кръг 7 (catalunya) и
    // „Spanish Grand Prix“ за кръг 14 (madring) през 2026.
    expect(localizer()->localize('catalunya', 'Barcelona Grand Prix', 2026))->toBe('Гран при на Барселона')
        ->and(localizer()->localize('madring', 'Spanish Grand Prix', 2026))->toBe('Гран при на Испания');
});

it('не пипа сезоните преди 2026 — Каталуния си остава Испания', function () {
    expect(localizer()->localize('catalunya', 'Spanish Grand Prix', 2025))->toBe('Гран при на Испания')
        ->and(localizer()->localize('catalunya', 'Spanish Grand Prix', 1991))->toBe('Гран при на Испания')
        // Без подадена година се ползва картата — така се държат старите извиквания.
        ->and(localizer()->localize('catalunya', 'Spanish Grand Prix'))->toBe('Гран при на Испания');
});

it('forRace чете годината от датата на състезанието', function () {
    $race = new Race;
    $race->jolpica_id = 'catalunya';
    $race->name = 'Barcelona Grand Prix';
    $race->race_datetime_utc = Carbon::parse('2026-06-14 13:00:00');

    expect(localizer()->forRace($race))->toBe('Гран при на Барселона');

    $race->race_datetime_utc = Carbon::parse('2025-06-01 13:00:00');

    expect(localizer()->forRace($race))->toBe('Гран при на Испания');
});

it('forRace пада към картата, ако състезанието няма дата', function () {
    $race = new Race;
    $race->jolpica_id = 'madring';
    $race->name = 'Spanish Grand Prix';

    expect(localizer()->forRace($race))->toBe('Гран при на Мадрид');
});
