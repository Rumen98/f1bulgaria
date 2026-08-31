<?php

declare(strict_types=1);

use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Блокът с точките на страницата на състезание.
 *
 * Регресията, която тези тестове пазят: шаблонът проверяваше голо `finished`,
 * което не е проп — Vue го резолвваше до undefined и наградата не се
 * показваше нито веднъж, откакто съществува.
 */
function finishedRaceWithPrediction(User $user): Race
{
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->subDays(2),
        'race_datetime_utc' => now()->subDay(),
    ]);

    Result::factory()->create([
        'race_id' => $race->id,
        'session_type' => 'race',
        'position' => 1,
    ]);

    $prediction = Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $race->id,
    ]);

    PredictionScore::factory()->create([
        'prediction_id' => $prediction->id,
        'points' => 33,
        'breakdown_json' => [
            'p1' => 25,
            'p2' => 0,
            'p3' => 8,
            'pole' => 0,
            'fastest_lap' => 0,
            'dnf' => 0,
            'safety_car' => 0,
        ],
    ]);

    return $race;
}

it('подава finished и разбивката към страницата на завършен кръг', function () {
    $user = User::factory()->create();
    $race = finishedRaceWithPrediction($user);

    $this->actingAs($user)
        ->get(route('races.show', $race->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Races/Show')
            // Без този проп шаблонът няма как да реши, че кръгът е минал.
            ->where('race.finished', true)
            ->where('userPrediction.points', 33)
            ->where('userPrediction.breakdown.p1', 25)
        );
});

it('не маркира предстоящ кръг като завършен', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(2),
        'race_datetime_utc' => now()->addDays(3),
    ]);

    $this->get(route('races.show', $race->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('race.finished', false));
});
