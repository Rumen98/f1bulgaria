<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'qualifying_datetime_utc' => now()->addDays(3),
    ]);
    $this->drivers = Driver::factory()->count(5)->create(['season_id' => $this->season->id]);
});

function validPayload($drivers): array
{
    return [
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
        'pole_driver_id' => $drivers[3]->id,
        'fastest_lap_driver_id' => $drivers[4]->id,
        'dnf_count' => 3,
        'safety_car' => true,
    ];
}

it('изисква вход за подаване на прогноза', function () {
    $this->post(route('predictions.store', $this->race), validPayload($this->drivers))
        ->assertRedirect(route('login'));
});

it('запазва прогноза за отключено състезание', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('predictions.store', $this->race), validPayload($this->drivers))
        ->assertRedirect();

    $this->assertDatabaseHas('predictions', [
        'user_id' => $user->id,
        'race_id' => $this->race->id,
        'dnf_count' => 3,
    ]);
});

it('обновява съществуваща прогноза вместо да дублира', function () {
    $user = User::factory()->create();
    $payload = validPayload($this->drivers);

    $this->actingAs($user)->post(route('predictions.store', $this->race), $payload);
    $this->actingAs($user)->post(route('predictions.store', $this->race), [...$payload, 'dnf_count' => 7]);

    expect($user->predictions()->count())->toBe(1)
        ->and($user->predictions()->first()->dnf_count)->toBe(7);
});

it('отхвърля еднакви пилоти на подиума', function () {
    $user = User::factory()->create();
    $payload = validPayload($this->drivers);
    $payload['p2_driver_id'] = $payload['p1_driver_id'];

    $this->actingAs($user)
        ->post(route('predictions.store', $this->race), $payload)
        ->assertSessionHasErrors('p1_driver_id');
});

it('отхвърля прогноза за заключено състезание', function () {
    $lockedRace = Race::factory()->create([
        'season_id' => $this->season->id,
        'qualifying_datetime_utc' => now()->addMinutes(2),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('predictions.store', $lockedRace), validPayload($this->drivers))
        ->assertSessionHasErrors('prediction');

    $this->assertDatabaseCount('predictions', 0);
});
