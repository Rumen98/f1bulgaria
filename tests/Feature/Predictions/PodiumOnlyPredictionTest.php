<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\User;
use App\Services\Predictions\PredictionScoringService;

/**
 * Задължителен е само подиумът. Бонус полетата (pole, най-бърза обиколка, DNF,
 * safety car) искат човек, който следи тренировките — като задължителни те
 * спираха случайния фен още преди бутона (измерено 12.08.2026: 9 връщащи се
 * потребители, 4 прогнозиращи).
 *
 * Критичното в точкуването: неподаден отговор е null, НЕ нула. Иначе празна
 * форма щеше да носи точки за „познат" брой отпаднали при състезание без DNF.
 */
beforeEach(function () {
    $this->season = Season::factory()->current()->create();
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'qualifying_datetime_utc' => now()->addDays(3),
    ]);
    $this->drivers = Driver::factory()->count(5)->create(['season_id' => $this->season->id]);
});

function podiumOnlyPayload($drivers): array
{
    return [
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
        'pole_driver_id' => null,
        'fastest_lap_driver_id' => null,
        'dnf_count' => null,
        'safety_car' => null,
    ];
}

it('приема прогноза само с подиум', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('predictions.store', $this->race), podiumOnlyPayload($this->drivers))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('predictions', [
        'user_id' => $user->id,
        'race_id' => $this->race->id,
        'pole_driver_id' => null,
        'dnf_count' => null,
        'safety_car' => null,
    ]);
});

it('продължава да изисква трите места на подиума', function () {
    $payload = podiumOnlyPayload($this->drivers);
    $payload['p3_driver_id'] = null;

    $this->actingAs(User::factory()->create())
        ->post(route('predictions.store', $this->race), $payload)
        ->assertSessionHasErrors('p3_driver_id');
});

it('не дава точки за DNF, когато полето е пропуснато', function () {
    // Състезание без нито един отпаднал: при старото поведение празното поле
    // се пазеше като 0 и вземаше точките за „точно познат" брой.
    $user = User::factory()->create();

    foreach ([1, 2, 3] as $position) {
        Result::factory()->create([
            'race_id' => $this->race->id,
            'driver_id' => $this->drivers[$position - 1]->id,
            'session_type' => 'race',
            'position' => $position,
            'dnf' => false,
        ]);
    }

    $prediction = Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $this->race->id,
        'p1_driver_id' => $this->drivers[0]->id,
        'p2_driver_id' => $this->drivers[1]->id,
        'p3_driver_id' => $this->drivers[2]->id,
        'pole_driver_id' => null,
        'fastest_lap_driver_id' => null,
        'dnf_count' => null,
        'safety_car' => null,
    ]);

    app(PredictionScoringService::class)->scoreRace($this->race->fresh());

    $breakdown = $prediction->fresh()->score->breakdown_json;

    expect($breakdown['dnf'])->toBe(0)
        ->and($breakdown['safety_car'])->toBe(0)
        ->and($breakdown['pole'])->toBe(0)
        ->and($breakdown['fastest_lap'])->toBe(0);
});

it('точкува подиума нормално и без бонусите', function () {
    $user = User::factory()->create();

    foreach ([1, 2, 3] as $position) {
        Result::factory()->create([
            'race_id' => $this->race->id,
            'driver_id' => $this->drivers[$position - 1]->id,
            'session_type' => 'race',
            'position' => $position,
            'dnf' => false,
        ]);
    }

    $prediction = Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $this->race->id,
        'p1_driver_id' => $this->drivers[0]->id,
        'p2_driver_id' => $this->drivers[1]->id,
        'p3_driver_id' => $this->drivers[2]->id,
        'pole_driver_id' => null,
        'fastest_lap_driver_id' => null,
        'dnf_count' => null,
        'safety_car' => null,
    ]);

    app(PredictionScoringService::class)->scoreRace($this->race->fresh());

    $rules = config('predictions.scoring.exact');

    expect($prediction->fresh()->score->points)
        ->toBe($rules['p1'] + $rules['p2'] + $rules['p3']);
});

it('още дава точки за DNF, когато човекът е попълнил бонуса', function () {
    $user = User::factory()->create();

    foreach ([1, 2, 3] as $position) {
        Result::factory()->create([
            'race_id' => $this->race->id,
            'driver_id' => $this->drivers[$position - 1]->id,
            'session_type' => 'race',
            'position' => $position,
            'dnf' => false,
        ]);
    }

    $prediction = Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $this->race->id,
        'p1_driver_id' => $this->drivers[0]->id,
        'p2_driver_id' => $this->drivers[1]->id,
        'p3_driver_id' => $this->drivers[2]->id,
        'pole_driver_id' => null,
        'fastest_lap_driver_id' => null,
        'dnf_count' => 0,
        'safety_car' => null,
    ]);

    app(PredictionScoringService::class)->scoreRace($this->race->fresh());

    expect($prediction->fresh()->score->breakdown_json['dnf'])
        ->toBe(config('predictions.scoring.dnf_exact'));
});
