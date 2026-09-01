<?php

declare(strict_types=1);

use App\Models\Badge;
use App\Models\Driver;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use App\Services\Badges\BadgeService;

function streakSeason(): array
{
    $season = Season::factory()->create(['is_current' => true]);

    Badge::factory()->create([
        'slug' => 'streak-3',
        'name' => BadgeService::DEFINITIONS['streak-3']['name'],
        'description' => BadgeService::DEFINITIONS['streak-3']['description'],
    ]);

    $races = collect([1, 2, 3, 4])->map(fn (int $round) => Race::factory()->create([
        'season_id' => $season->id,
        'round' => $round,
        'qualifying_datetime_utc' => now()->addDays($round),
        'race_datetime_utc' => now()->addDays($round)->addDay(),
    ]));

    $drivers = Driver::factory()->count(3)->create(['season_id' => $season->id]);

    return [$season, $races, $drivers];
}

function predictRound(User $user, Race $race, $drivers, $test): void
{
    $test->actingAs($user)->post(route('predictions.store', $race), [
        'p1_driver_id' => $drivers[0]->id,
        'p2_driver_id' => $drivers[1]->id,
        'p3_driver_id' => $drivers[2]->id,
    ])->assertRedirect();
}

it('дава „Постоянство" на третия пореден кръг', function () {
    [, $races, $drivers] = streakSeason();
    $user = User::factory()->create();

    predictRound($user, $races[0], $drivers, $this);
    predictRound($user, $races[1], $drivers, $this);
    expect($user->fresh()->badges()->where('slug', 'streak-3')->exists())->toBeFalse();

    predictRound($user, $races[2], $drivers, $this);
    expect($user->fresh()->badges()->where('slug', 'streak-3')->exists())->toBeTrue();
});

it('дупка в серията я нулира', function () {
    [, $races, $drivers] = streakSeason();
    $user = User::factory()->create();

    // Кръг 1, после 3 и 4 — серията в кръг 4 е 2, не 3.
    predictRound($user, $races[0], $drivers, $this);
    predictRound($user, $races[2], $drivers, $this);
    predictRound($user, $races[3], $drivers, $this);

    expect($user->fresh()->badges()->where('slug', 'streak-3')->exists())->toBeFalse();
});

it('predictionStreak смята поредните кръгове назад', function () {
    [$season, $races, $drivers] = streakSeason();
    $user = User::factory()->create();

    foreach ([0, 1, 2] as $i) {
        Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $races[$i]->id]);
    }

    $badges = app(BadgeService::class);

    expect($badges->predictionStreak($user, $season->id, 3))->toBe(3)
        ->and($badges->predictionStreak($user, $season->id, 4))->toBe(0);
});
