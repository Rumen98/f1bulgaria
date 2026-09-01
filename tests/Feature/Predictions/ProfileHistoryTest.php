<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('показва прогнозите за заключени кръгове с точки и подиум', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'round' => 5,
        'qualifying_datetime_utc' => now()->subDays(2),
        'race_datetime_utc' => now()->subDay(),
    ]);
    $drivers = Driver::factory()->count(3)->create(['season_id' => $season->id]);
    $user = User::factory()->create();

    $prediction = Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $race->id,
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
    ]);
    PredictionScore::factory()->create([
        'prediction_id' => $prediction->id,
        'points' => 30,
        'breakdown_json' => ['p1' => 25, 'p2' => 0, 'p3' => 5, 'pole' => 0, 'fastest_lap' => 0, 'dnf' => 0, 'safety_car' => 0],
    ]);

    $this->get(route('profiles.show', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->has('predictionHistory', 1)
            ->where('predictionHistory.0.round', 5)
            ->where('predictionHistory.0.points', 30)
            ->where('predictionHistory.0.breakdown.p1', 25)
            ->has('predictionHistory.0.podium', 3)
        );
});

it('НИКОГА не показва прогноза за отворен кръг', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(3),
        'race_datetime_utc' => now()->addDays(4),
    ]);
    $user = User::factory()->create();
    Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $race->id]);

    // Отворената прогноза е подсказка за съперниците — не излиза публично.
    $this->get(route('profiles.show', $user))
        ->assertInertia(fn (Assert $page) => $page->has('predictionHistory', 0));
});

it('показва серията, когато е поне 2', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $races = collect([1, 2, 3])->map(fn (int $round) => Race::factory()->create([
        'season_id' => $season->id,
        'round' => $round,
    ]));
    $user = User::factory()->create();

    foreach ($races as $race) {
        Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $race->id]);
    }

    $this->get(route('profiles.show', $user))
        ->assertInertia(fn (Assert $page) => $page->where('streak', 3));
});
