<?php

declare(strict_types=1);

use App\Models\Prediction;
use App\Models\Race;
use App\Services\Predictions\PredictionLockService;

it('маркира състезание като заключено 5 минути преди квалификацията', function () {
    $service = app(PredictionLockService::class);

    $soon = Race::factory()->create([
        'qualifying_datetime_utc' => now()->addMinutes(4),
    ]);
    $later = Race::factory()->create([
        'qualifying_datetime_utc' => now()->addMinutes(30),
    ]);

    expect($service->isLocked($soon))->toBeTrue()
        ->and($service->isLocked($later))->toBeFalse();
});

it('остава отключено без обявено време на квалификация', function () {
    $race = Race::factory()->create(['qualifying_datetime_utc' => null]);

    expect(app(PredictionLockService::class)->isLocked($race))->toBeFalse();
});

it('заключва само просрочените прогнози', function () {
    $dueRace = Race::factory()->create(['qualifying_datetime_utc' => now()->addMinutes(2)]);
    $futureRace = Race::factory()->create(['qualifying_datetime_utc' => now()->addHours(3)]);

    $due = Prediction::factory()->create(['race_id' => $dueRace->id, 'locked_at' => null]);
    $future = Prediction::factory()->create(['race_id' => $futureRace->id, 'locked_at' => null]);

    $locked = app(PredictionLockService::class)->lockDue();

    expect($locked)->toBe(1)
        ->and($due->fresh()->locked_at)->not->toBeNull()
        ->and($future->fresh()->locked_at)->toBeNull();
});
