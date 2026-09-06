<?php

declare(strict_types=1);

use App\Services\Races\RaceNameLocalizer;

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
