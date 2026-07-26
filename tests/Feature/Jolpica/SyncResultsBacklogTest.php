<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\Season;
use App\Services\Jolpica\ResultSyncService;
use App\Services\Telegram\F1ChannelEnqueuer;

beforeEach(function () {
    // Каналът има собствени тестове; тук ни интересува кои състезания се синхронизират.
    $this->mock(F1ChannelEnqueuer::class)
        ->shouldReceive('enqueuePending')
        ->andReturn(['queued' => 0, 'updated' => 0, 'errors' => []]);
});

it('наваксва пропуснато състезание, а не само последното', function () {
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);

    $skipped = Race::factory()->create([
        'season_id' => $season->id, 'round' => 11,
        'race_datetime_utc' => now()->subDays(7),
    ]);

    $latest = Race::factory()->create([
        'season_id' => $season->id, 'round' => 12,
        'race_datetime_utc' => now()->subDay(),
    ]);

    $synced = [];

    $this->mock(ResultSyncService::class)
        ->shouldReceive('sync')
        ->andReturnUsing(function (Race $race) use (&$synced): array {
            $synced[] = $race->round;

            return ['results' => 0, 'sprint' => 0, 'scored' => 0];
        });

    $this->artisan('f1:sync-results')->assertSuccessful();

    // Преди поправката командата взимаше само ПОСЛЕДНОТО изминало състезание
    // без резултати. Кръг 11 оставаше без резултати завинаги — а с него и
    // прогнозите за него, неоценени.
    expect($synced)->toContain(11)
        ->and($synced)->toContain(12);

    expect($skipped->round)->toBe(11)
        ->and($latest->round)->toBe(12);
});

it('не пипа стари състезания без резултати извън прозореца', function () {
    $season = Season::factory()->create(['year' => 1954, 'is_current' => false]);

    Race::factory()->create([
        'season_id' => $season->id, 'round' => 3,
        'race_datetime_utc' => now()->subYears(70),
    ]);

    $synced = [];

    $this->mock(ResultSyncService::class)
        ->shouldReceive('sync')
        ->andReturnUsing(function (Race $race) use (&$synced): array {
            $synced[] = $race->round;

            return ['results' => 0, 'sprint' => 0, 'scored' => 0];
        });

    $this->artisan('f1:sync-results')->assertSuccessful();

    // Архивът пази 1150+ състезания и част от старите никога няма да получат
    // резултати. Без прозорец назад командата би ги дърпала вечно.
    expect($synced)->toBe([]);
});
