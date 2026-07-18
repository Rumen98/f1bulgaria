<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config()->set('services.jolpica.retry_times', 2);
    config()->set('services.jolpica.retry_sleep_ms', 0);
});

it('изчаква cooldown-а при ударен rate limit и повтаря същия сезон', function () {
    Sleep::fake();

    // Първите 2 опита (retry_times) удрят 429 → rate limit изчакване →
    // при повторението сезонът минава (календар без състезания).
    $empty = fn (string $table, string $list) => ['MRData' => ['total' => '0', $table => [$list => []]]];

    Http::fake([
        '*constructors*' => Http::sequence()
            ->push('', 429)
            ->push('', 429)
            ->push($empty('ConstructorTable', 'Constructors'), 200),
        '*drivers*' => Http::response($empty('DriverTable', 'Drivers'), 200),
        '*driverstandings*' => Http::response(['MRData' => ['StandingsTable' => ['StandingsLists' => []]]], 200),
        '*' => Http::response($empty('RaceTable', 'Races'), 200),
    ]);

    $this->artisan('f1:sync-history', ['from' => 1972, 'to' => 1972, '--cooldown' => 600])
        ->assertSuccessful()
        ->expectsOutputToContain('Rate limit на Jolpica — изчаквам 600 сек');

    Sleep::assertSleptTimes(1);
});

it('се отказва от сезона след изчерпване на всички rate limit изчаквания', function () {
    Sleep::fake();

    Http::fake(['*' => Http::response('', 429)]);

    $this->artisan('f1:sync-history', ['from' => 1972, 'to' => 1972, '--cooldown' => 600])
        ->assertSuccessful()
        ->expectsOutputToContain('Сезон 1972 се провали');

    // MAX_RATE_LIMIT_WAITS = 8 изчаквания, после отказ (не безкраен цикъл).
    Sleep::assertSleptTimes(8);
});
