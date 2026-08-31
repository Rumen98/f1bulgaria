<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\Season;

/**
 * Къде отива новорегистрираният. Дотук отиваше на `dashboard`, който е
 * публичният календар и не иска нищо от човека — формата за прогноза е
 * единственото действие, заради което си е направил акаунт.
 */
it('праща новорегистрирания към следващия кръг с отворени прогнози', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $next = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(3),
        'race_datetime_utc' => now()->addDays(4),
    ]);

    $this->post('/register', [
        'name' => 'Нов Фен',
        'email' => 'parvi@example.bg',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('races.show', $next->id, absolute: false));

    $this->assertAuthenticated();
});

it('прескача вече заключените кръгове', function () {
    $season = Season::factory()->create(['is_current' => true]);

    Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->subDay(),
        'race_datetime_utc' => now()->subHours(2),
    ]);

    $upcoming = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(6),
        'race_datetime_utc' => now()->addDays(7),
    ]);

    $this->post('/register', [
        'name' => 'Втори Фен',
        'email' => 'vtori@example.bg',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('races.show', $upcoming->id, absolute: false));
});

it('пада към класирането, когато няма предстоящ кръг', function () {
    Season::factory()->create(['is_current' => true]);

    $this->post('/register', [
        'name' => 'Трети Фен',
        'email' => 'treti@example.bg',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('leaderboard', absolute: false));
});
