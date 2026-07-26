<?php

declare(strict_types=1);

use App\Enums\ResultSessionType;
use App\Enums\SessionType;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\SessionResult;
use Inertia\Testing\AssertableInertia as Assert;

function seedRaceWithQualifying(): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);
    $mclaren = Constructor::factory()->create([
        'season_id' => $season->id, 'name' => 'McLaren', 'slug' => 'mclaren',
    ]);
    $driver = Driver::factory()->create([
        'season_id' => $season->id, 'constructor_id' => $mclaren->id,
        'first_name' => 'Lando', 'last_name' => 'Norris', 'slug' => 'lando-norris',
    ]);

    $race = Race::factory()->create([
        'season_id' => $season->id, 'jolpica_id' => 'hungaroring', 'round' => 11,
        'race_datetime_utc' => now()->subHours(3),
    ]);

    SessionResult::query()->create([
        'race_id' => $race->id,
        'session_type' => SessionType::Qualifying->value,
        'driver_id' => $driver->id,
        'position' => 1,
        'q3' => '1:17.207',
    ]);

    return $race;
}

it('показва класацията от квалификацията, дори когато състезанието още няма резултати', function () {
    $race = seedRaceWithQualifying();

    // Точно случаят от продукцията: Jolpica изостава с резултатите от
    // състезанието, а квалификацията отдавна е в базата. Дотук страницата
    // стоеше празна.
    $this->get("/races/{$race->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Races/Show')
            ->has('classifications', 1)
            ->where('classifications.0.type', 'qualifying')
            ->where('classifications.0.label', 'Квалификация')
            ->where('classifications.0.rows.0.driver', 'Ландо Норис')
            ->where('classifications.0.rows.0.team', 'McLaren')
            ->where('classifications.0.rows.0.time', '1:17.207'));
});

it('подрежда сесиите в реда на уикенда', function () {
    $race = seedRaceWithQualifying();
    $driver = Driver::query()->first();

    Result::factory()->create([
        'race_id' => $race->id, 'driver_id' => $driver->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => 1, 'points' => 25, 'dnf' => false,
    ]);

    SessionResult::query()->create([
        'race_id' => $race->id,
        'session_type' => SessionType::FP1->value,
        'driver_id' => $driver->id,
        'position' => 3,
        'best_time' => '1:18.900',
    ]);

    $this->get("/races/{$race->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('classifications', 3)
            // Тренировка → квалификация → състезание, независимо в какъв ред
            // синхроните са ги записали.
            ->where('classifications.0.type', 'fp1')
            ->where('classifications.1.type', 'qualifying')
            ->where('classifications.2.type', 'race'));
});

it('дава точки само за сесиите, които носят точки', function () {
    $race = seedRaceWithQualifying();
    $driver = Driver::query()->first();

    Result::factory()->create([
        'race_id' => $race->id, 'driver_id' => $driver->id,
        'session_type' => ResultSessionType::Race->value,
        'position' => 1, 'points' => 25, 'dnf' => false,
    ]);

    $this->get("/races/{$race->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('classifications.0.rows.0.points', null) // квалификация
            // 25.0 се сериализира в JSON като 25 — сравняваме с целочислената форма.
            ->where('classifications.1.rows.0.points', 25)); // състезание
});
