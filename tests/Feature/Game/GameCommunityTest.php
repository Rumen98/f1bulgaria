<?php

declare(strict_types=1);

use App\Models\GameLapRecord;
use App\Models\Race;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.game' => true]);
    Queue::fake();
    Cache::flush(); // week-track кешът не бива да пренася между тестовете
});

it('класацията на пистата на уикенда носи седмична подредба', function () {
    Race::factory()->create([
        'circuit' => 'monza',
        'race_datetime_utc' => now()->addDays(2),
    ]);

    $veteran = User::factory()->create(['name' => 'Ветерана']);
    $rookie = User::factory()->create(['name' => 'Новака']);

    // Старо (по-бързо) време отпреди седмицата — брои се само в „Всички".
    GameLapRecord::factory()->for($veteran)->create([
        'lap_ms' => 88000,
        'created_at' => now()->subDays(30),
    ]);
    GameLapRecord::factory()->for($rookie)->create(['lap_ms' => 92000]);

    $this->getJson('/game/leaderboard/monza')
        ->assertOk()
        ->assertJsonPath('top.0.name', 'Ветерана')
        ->assertJsonPath('weekly.0.name', 'Новака')
        ->assertJsonCount(1, 'weekly');
});

it('класацията на друга писта е без седмичен блок', function () {
    Race::factory()->create([
        'circuit' => 'monza',
        'race_datetime_utc' => now()->addDays(2),
    ]);

    $this->getJson('/game/leaderboard/spa')
        ->assertOk()
        ->assertJsonPath('weekly', null);
});

it('публичният профил носи статистика от играта', function () {
    $user = User::factory()->create();
    GameLapRecord::factory()->for($user)->create(['lap_ms' => 91000]);
    GameLapRecord::factory()->for($user)->create(['track_slug' => 'spa', 'lap_ms' => 165000]);

    $this->get("/profiles/{$user->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('game.tracks_played', 2)
            ->where('game.firsts', 2));
});

it('валидирана обиколка присъжда значките от Хронометъра', function () {
    $this->seed(BadgeSeeder::class);

    $user = User::factory()->create();
    $record = GameLapRecord::factory()->for($user)->create([
        // По-бързо от официалния дух на Монца (120083 ms).
        'lap_ms' => 95000,
        'verify_status' => 'verified',
    ]);

    app(BadgeService::class)->evaluateForGameLap($record);

    $slugs = $user->badges()->pluck('slug')->all();

    expect($slugs)->toContain('game-first-lap')
        ->toContain('game-beat-official')
        ->toContain('game-track-record');
});

it('седмичната команда поставя постове в каналната опашка', function () {
    $race = Race::factory()->create([
        'circuit' => 'monza',
        'race_datetime_utc' => now()->addDays(2),
    ]);

    $this->artisan('game:weekly-channel', ['--mode' => 'open'])
        ->assertSuccessful();

    $this->assertDatabaseHas('channel_posts', [
        'kind' => 'game_challenge',
        'subject_id' => $race->id,
    ]);

    // Резултати: има карал тази седмица → пост + значка на победителя.
    $this->seed(BadgeSeeder::class);
    $winner = User::factory()->create();
    GameLapRecord::factory()->for($winner)->create(['lap_ms' => 90000]);

    $this->artisan('game:weekly-channel', ['--mode' => 'wrap'])
        ->assertSuccessful();

    $this->assertDatabaseHas('channel_posts', [
        'kind' => 'game_results',
        'subject_id' => $race->id,
    ]);
    expect($winner->badges()->pluck('slug'))->toContain('game-week-winner');
});
