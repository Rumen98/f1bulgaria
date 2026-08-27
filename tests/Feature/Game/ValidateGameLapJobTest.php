<?php

declare(strict_types=1);

use App\Jobs\ValidateGameLapJob;
use App\Models\GameLapRecord;
use App\Models\User;

/**
 * Истински end-to-end: PHP job → Node валидатор → същата симулация.
 * Фикстурата е реална обиколка (автопилот), генерирана със sim.js —
 * при промяна на физиката се регенерира (виж scripts/game/selftest.mjs).
 */
beforeEach(function () {
    config(['features.game' => true]);

    $this->fixture = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/game/monza-lap.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
});

it('потвърждава истинска обиколка чрез преиграване в Node', function () {
    $record = GameLapRecord::factory()->for(User::factory()->create())->create([
        'track_slug' => 'monza',
        'lap_ms' => $this->fixture['lap_ms'],
        'sector1_ms' => $this->fixture['sectors_ms'][0],
        'sector2_ms' => $this->fixture['sectors_ms'][1],
        'sector3_ms' => $this->fixture['sectors_ms'][2],
        'input_trace' => $this->fixture['trace'],
        'sim_version' => $this->fixture['sim_version'],
        'verify_status' => 'pending',
    ]);

    (new ValidateGameLapJob($record->id))->handle();

    $record->refresh();

    expect($record->verify_status)->toBe('verified')
        ->and($record->verified_lap_ms)->toBe($this->fixture['lap_ms'])
        // Преиграното време е авторитетното — записът го носи.
        ->and($record->lap_ms)->toBe($this->fixture['lap_ms']);
})->skip(
    fn (): bool => trim((string) shell_exec('node --version 2>&1')) === '',
    'Node не е наличен в тази среда.',
);

it('отхвърля обиколка с подправено (по-бързо) време', function () {
    $record = GameLapRecord::factory()->for(User::factory()->create())->create([
        'track_slug' => 'monza',
        // Твърди 30 секунди по-бързо от каквото трейсът реално вози.
        'lap_ms' => $this->fixture['lap_ms'] - 30000,
        'sector1_ms' => 30000,
        'sector2_ms' => 30000,
        'sector3_ms' => $this->fixture['lap_ms'] - 90000,
        'input_trace' => $this->fixture['trace'],
        'sim_version' => $this->fixture['sim_version'],
        'verify_status' => 'pending',
    ]);

    (new ValidateGameLapJob($record->id))->handle();

    expect($record->refresh()->verify_status)->toBe('rejected');
})->skip(
    fn (): bool => trim((string) shell_exec('node --version 2>&1')) === '',
    'Node не е наличен в тази среда.',
);

it('счупен трейс дава rejected, а не 500', function () {
    $record = GameLapRecord::factory()->for(User::factory()->create())->create([
        'track_slug' => 'monza',
        'lap_ms' => 90000,
        'input_trace' => 'нещо счупено',
        'sim_version' => 1,
        'verify_status' => 'pending',
    ]);

    (new ValidateGameLapJob($record->id))->handle();

    expect($record->refresh()->verify_status)->toBe('rejected');
})->skip(
    fn (): bool => trim((string) shell_exec('node --version 2>&1')) === '',
    'Node не е наличен в тази среда.',
);
