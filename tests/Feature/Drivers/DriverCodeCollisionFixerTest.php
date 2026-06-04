<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\DriverCodeCollisionFixer;

function fixer(): DriverCodeCollisionFixer
{
    return app(DriverCodeCollisionFixer::class);
}

it('преразпределя кода на пилота с по-малко състезания', function () {
    $s = Season::factory()->create();
    $max = Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Max', 'last_name' => 'Verstappen', 'slug' => 'max-v', 'driver_code' => 'VER']);
    $vergne = Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Jean-Éric', 'last_name' => 'Vergne', 'slug' => 'jev', 'driver_code' => 'VER']);

    // Max с 3 състезания, Vergne с 1.
    foreach (Race::factory()->count(3)->create(['season_id' => $s->id]) as $race) {
        Result::factory()->create(['race_id' => $race->id, 'driver_id' => $max->id, 'session_type' => 'race']);
    }
    $r = Race::factory()->create(['season_id' => $s->id]);
    Result::factory()->create(['race_id' => $r->id, 'driver_id' => $vergne->id, 'session_type' => 'race']);

    $stats = fixer()->fix();

    expect($max->fresh()->driver_code)->toBe('VER')                 // повече състезания → задържа
        ->and($vergne->fresh()->driver_code)->not->toBe('VER')
        ->and($vergne->fresh()->driver_code)->not->toBeNull()
        ->and($stats['collisions'])->toBe(1)
        ->and($stats['reassigned'])->toBe(1);
});

it('не пипа код, споделен от един и същ пилот през сезони', function () {
    $s1 = Season::factory()->create(['year' => 1990]);
    $s2 = Season::factory()->create(['year' => 1991]);
    Driver::factory()->create(['season_id' => $s1->id, 'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'sen-90', 'driver_code' => 'SEN']);
    Driver::factory()->create(['season_id' => $s2->id, 'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'sen-91', 'driver_code' => 'SEN']);

    $stats = fixer()->fix();

    expect($stats['collisions'])->toBe(0)
        ->and(Driver::query()->where('driver_code', 'SEN')->count())->toBe(2);
});

it('е идемпотентна — втори run няма колизии', function () {
    $s = Season::factory()->create();
    Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Max', 'last_name' => 'Verstappen', 'slug' => 'm', 'driver_code' => 'VER']);
    Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Jean', 'last_name' => 'Vergne', 'slug' => 'v', 'driver_code' => 'VER']);

    fixer()->fix();

    expect(fixer()->fix()['collisions'])->toBe(0);
});
