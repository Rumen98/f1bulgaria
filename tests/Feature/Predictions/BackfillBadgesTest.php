<?php

declare(strict_types=1);

use App\Models\Badge;
use App\Models\Prediction;
use App\Models\PredictionScore;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Illuminate\Support\Carbon;

it('създава дефинициите на значките, когато базата е празна', function () {
    expect(Badge::query()->count())->toBe(0);

    $this->artisan('padok:backfill-badges')->assertSuccessful();

    expect(Badge::query()->count())->toBe(count(BadgeService::DEFINITIONS))
        ->and(Badge::query()->where('slug', 'first-prediction')->exists())->toBeTrue();
});

it('дава „Дебют" на всеки с поне една прогноза, включително за предстоящ кръг', function () {
    $season = Season::factory()->create(['is_current' => true]);

    // Прогноза за кръг БЕЗ резултати — evaluateForRace не би я видяла.
    $upcoming = Race::factory()->create([
        'season_id' => $season->id,
        'race_datetime_utc' => now()->addWeek(),
        'qualifying_datetime_utc' => now()->addDays(6),
    ]);

    $player = User::factory()->create();
    Prediction::factory()->create(['user_id' => $player->id, 'race_id' => $upcoming->id]);

    $spectator = User::factory()->create();

    $this->artisan('padok:backfill-badges')->assertSuccessful();

    expect($player->fresh()->badges()->where('slug', 'first-prediction')->exists())->toBeTrue()
        ->and($spectator->fresh()->badges()->count())->toBe(0);
});

it('датира „Дебют" с първата прогноза, а не с наваксването', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create(['season_id' => $season->id]);
    $user = User::factory()->create();

    $firstAt = now()->subMonths(2)->startOfSecond();
    Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $race->id,
        'created_at' => $firstAt,
    ]);

    $this->artisan('padok:backfill-badges')->assertSuccessful();

    $pivot = $user->fresh()->badges()->where('slug', 'first-prediction')->first()->pivot;

    expect(Carbon::parse($pivot->awarded_at)->equalTo($firstAt))->toBeTrue();
});

it('присъжда и значките от изиграните кръгове', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'race_datetime_utc' => now()->subWeek(),
    ]);
    $user = User::factory()->create();

    $prediction = Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $race->id]);
    PredictionScore::factory()->create([
        'prediction_id' => $prediction->id,
        'points' => 70, // над прага на „Снайперист"
        'breakdown_json' => ['p1' => 25, 'p2' => 0, 'p3' => 0, 'pole' => 0, 'fastest_lap' => 0, 'dnf' => 0, 'safety_car' => 0],
    ]);

    $this->artisan('padok:backfill-badges')->assertSuccessful();

    expect($user->fresh()->badges()->pluck('slug')->all())
        ->toContain('first-prediction')
        ->toContain('high-scorer');
});

it('повторен пуск не дублира нищо', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create(['season_id' => $season->id]);
    $user = User::factory()->create();
    Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $race->id]);

    $this->artisan('padok:backfill-badges')->assertSuccessful();
    $this->artisan('padok:backfill-badges')->assertSuccessful();

    expect($user->fresh()->badges()->count())->toBe(1)
        ->and(Badge::query()->count())->toBe(count(BadgeService::DEFINITIONS));
});

it('dry-run не пише нищо', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create(['season_id' => $season->id]);
    Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $race->id]);

    $this->artisan('padok:backfill-badges', ['--dry-run' => true])->assertSuccessful();

    expect(Badge::query()->count())->toBe(0)
        ->and($user->fresh()->badges()->count())->toBe(0);
});
