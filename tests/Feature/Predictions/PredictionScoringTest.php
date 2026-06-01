<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\User;
use App\Services\Predictions\PredictionScoringService;

beforeEach(function () {
    $this->season = Season::factory()->current()->create();

    [$this->a, $this->b, $this->c, $this->d] = collect(range(1, 4))
        ->map(fn () => Driver::factory()->create(['season_id' => $this->season->id]))
        ->all();

    // A pole + победа + най-бърза обиколка, B втори, C трети, D отпада. SC: да.
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'pole_driver_id' => $this->a->id,
        'had_safety_car' => true,
    ]);

    Result::factory()->position(1)->create(['race_id' => $this->race->id, 'driver_id' => $this->a->id, 'fastest_lap' => true]);
    Result::factory()->position(2)->create(['race_id' => $this->race->id, 'driver_id' => $this->b->id]);
    Result::factory()->position(3)->create(['race_id' => $this->race->id, 'driver_id' => $this->c->id]);
    Result::factory()->dnf()->create(['race_id' => $this->race->id, 'driver_id' => $this->d->id]);
});

it('дава максимум точки за перфектна прогноза', function () {
    $prediction = Prediction::factory()->create([
        'user_id' => User::factory(),
        'race_id' => $this->race->id,
        'p1_driver_id' => $this->a->id,
        'p2_driver_id' => $this->b->id,
        'p3_driver_id' => $this->c->id,
        'pole_driver_id' => $this->a->id,
        'fastest_lap_driver_id' => $this->a->id,
        'dnf_count' => 1,
        'safety_car' => true,
    ]);

    app(PredictionScoringService::class)->scoreRace($this->race);

    $score = $prediction->score()->first();

    // 25 + 18 + 15 + 10 (pole) + 10 (FL) + 10 (dnf) + 10 (SC) = 98
    expect($score->points)->toBe(98)
        ->and($score->breakdown_json['p1'])->toBe(25)
        ->and($score->breakdown_json['pole'])->toBe(10)
        ->and($score->breakdown_json['safety_car'])->toBe(10);
});

it('дава частични точки за пилот в топ 3 на грешна позиция и близък DNF', function () {
    $prediction = Prediction::factory()->create([
        'user_id' => User::factory(),
        'race_id' => $this->race->id,
        'p1_driver_id' => $this->b->id,   // в подиума, грешна позиция → 5
        'p2_driver_id' => $this->a->id,   // в подиума, грешна позиция → 5
        'p3_driver_id' => $this->d->id,   // извън подиума → 0
        'pole_driver_id' => $this->b->id, // грешен → 0
        'fastest_lap_driver_id' => $this->b->id, // грешен → 0
        'dnf_count' => 2,                 // реално 1 → близо → 5
        'safety_car' => false,            // реално да → 0
    ]);

    app(PredictionScoringService::class)->scoreRace($this->race);

    expect($prediction->score()->first()->points)->toBe(15);
});

it('не точкува докато няма резултати', function () {
    $emptyRace = Race::factory()->create(['season_id' => $this->season->id]);
    Prediction::factory()->create(['race_id' => $emptyRace->id]);

    $scored = app(PredictionScoringService::class)->scoreRace($emptyRace);

    expect($scored)->toBe(0);
});

it('е идемпотентен — повторното точкуване не дублира резултата', function () {
    $prediction = Prediction::factory()->create([
        'race_id' => $this->race->id,
        'p1_driver_id' => $this->a->id,
        'p2_driver_id' => $this->b->id,
        'p3_driver_id' => $this->c->id,
        'pole_driver_id' => $this->a->id,
        'fastest_lap_driver_id' => $this->a->id,
        'dnf_count' => 1,
        'safety_car' => true,
    ]);

    $service = app(PredictionScoringService::class);
    $service->scoreRace($this->race);
    $service->scoreRace($this->race);

    expect($prediction->score()->count())->toBe(1);
});
