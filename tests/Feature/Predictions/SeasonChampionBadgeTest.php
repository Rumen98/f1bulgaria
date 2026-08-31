<?php

declare(strict_types=1);

use App\Models\Badge;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Синхронът не бива да пипа мрежата в тест.
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['MRData' => ['RaceTable' => ['Races' => []]]], 200)]);

    Badge::factory()->create([
        'slug' => 'season-champion',
        'name' => BadgeService::DEFINITIONS['season-champion']['name'],
        'description' => BadgeService::DEFINITIONS['season-champion']['description'],
    ]);
});

/** Сезон, в който всички кръгове имат резултат от състезание. */
function completedSeasonWithLeader(User $leader, ?User $other = null): Season
{
    $season = Season::factory()->create(['is_current' => true]);

    $race = Race::factory()->create([
        'season_id' => $season->id,
        'race_datetime_utc' => now()->subWeek(),
        'qualifying_datetime_utc' => now()->subWeek()->subDay(),
    ]);

    Result::factory()->create(['race_id' => $race->id, 'session_type' => 'race', 'position' => 1]);

    $top = Prediction::factory()->create(['user_id' => $leader->id, 'race_id' => $race->id]);
    PredictionScore::factory()->create(['prediction_id' => $top->id, 'points' => 50]);

    if ($other !== null) {
        $second = Prediction::factory()->create(['user_id' => $other->id, 'race_id' => $race->id]);
        PredictionScore::factory()->create(['prediction_id' => $second->id, 'points' => 10]);
    }

    return $season;
}

it('присъжда значката на водача, когато сезонът е приключил', function () {
    $leader = User::factory()->create();
    $second = User::factory()->create();
    completedSeasonWithLeader($leader, $second);

    $this->artisan('f1:sync-results')->assertSuccessful();

    expect($leader->fresh()->badges()->where('slug', 'season-champion')->exists())->toBeTrue()
        ->and($second->fresh()->badges()->where('slug', 'season-champion')->exists())->toBeFalse();
});

it('не присъжда значка, докато има кръг без резултати', function () {
    $leader = User::factory()->create();
    $season = completedSeasonWithLeader($leader);

    // Още един кръг, който няма резултати — сезонът не е приключил.
    Race::factory()->create([
        'season_id' => $season->id,
        'race_datetime_utc' => now()->addWeek(),
        'qualifying_datetime_utc' => now()->addWeek()->subDay(),
    ]);

    $this->artisan('f1:sync-results')->assertSuccessful();

    expect($leader->fresh()->badges()->where('slug', 'season-champion')->exists())->toBeFalse();
});

it('не дублира значката при повторен синхрон', function () {
    $leader = User::factory()->create();
    completedSeasonWithLeader($leader);

    $this->artisan('f1:sync-results')->assertSuccessful();
    $this->artisan('f1:sync-results')->assertSuccessful();

    expect($leader->fresh()->badges()->where('slug', 'season-champion')->count())->toBe(1);
});
