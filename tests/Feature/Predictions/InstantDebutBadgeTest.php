<?php

declare(strict_types=1);

use App\Models\Badge;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use App\Services\Badges\BadgeService;

/**
 * „Дебют" се дава ВЕДНАГА при първата прогноза. Регресията, която пазим:
 * значката идваше чак при неделния синхрон на резултати — човек подава
 * прогноза, отваря профила си и я няма.
 */
function openRaceWithDrivers(): array
{
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(2),
        'race_datetime_utc' => now()->addDays(3),
    ]);
    $drivers = Driver::factory()->count(3)->create(['season_id' => $season->id]);

    Badge::factory()->create([
        'slug' => 'first-prediction',
        'name' => BadgeService::DEFINITIONS['first-prediction']['name'],
        'description' => BadgeService::DEFINITIONS['first-prediction']['description'],
    ]);

    return [$race, $drivers];
}

it('дава „Дебют" веднага при подаване на първата прогноза', function () {
    [$race, $drivers] = openRaceWithDrivers();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('predictions.store', $race), [
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
    ])->assertRedirect();

    expect($user->fresh()->badges()->where('slug', 'first-prediction')->exists())->toBeTrue();

    // Тостът я обявява при следващото зареждане — seen_at е празно.
    $pivot = $user->fresh()->badges()->where('slug', 'first-prediction')->first()->pivot;
    expect($pivot->seen_at)->toBeNull();
});

it('редакция на прогнозата не дублира значката', function () {
    [$race, $drivers] = openRaceWithDrivers();
    $user = User::factory()->create();

    $payload = [
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
    ];

    $this->actingAs($user)->post(route('predictions.store', $race), $payload);
    $this->actingAs($user)->post(route('predictions.store', $race), $payload);

    expect($user->fresh()->badges()->count())->toBe(1);
});

it('заключен кръг не дава значка', function () {
    [$race, $drivers] = openRaceWithDrivers();
    $race->update(['qualifying_datetime_utc' => now()->subHour()]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('predictions.store', $race), [
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
    ]);

    expect($user->fresh()->badges()->count())->toBe(0);
});
