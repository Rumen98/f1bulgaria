<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Season;
use App\Services\Drivers\DriverCodeGenerator;

function codeGen(): DriverCodeGenerator
{
    return app(DriverCodeGenerator::class);
}

it('генерира код от първите 3 букви на фамилията', function () {
    $s = Season::factory()->create();
    $d = Driver::factory()->create([
        'season_id' => $s->id, 'first_name' => 'Juan', 'last_name' => 'Fangio',
        'slug' => 'juan-fangio', 'driver_code' => null,
    ]);

    codeGen()->assignAll();

    expect($d->fresh()->driver_code)->toBe('FAN');
});

it('разрешава колизия между различни пилоти със същата фамилия', function () {
    $s = Season::factory()->create();
    Driver::factory()->create([
        'season_id' => $s->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton',
        'slug' => 'lewis-hamilton', 'driver_code' => 'HAM',
    ]);
    $duncan = Driver::factory()->create([
        'season_id' => $s->id, 'first_name' => 'Duncan', 'last_name' => 'Hamilton',
        'slug' => 'duncan-hamilton', 'driver_code' => null,
    ]);

    codeGen()->assignAll();

    expect($duncan->fresh()->driver_code)->toBe('HAD'); // HA + D (HAM е зает)
});

it('дава един и същ код на пилот през различни сезони', function () {
    $s1 = Season::factory()->create(['year' => 1991]);
    $s2 = Season::factory()->create(['year' => 1994]);
    $a = Driver::factory()->create(['season_id' => $s1->id, 'first_name' => 'Michael', 'last_name' => 'Schumacher', 'slug' => 'msc-91', 'driver_code' => null]);
    $b = Driver::factory()->create(['season_id' => $s2->id, 'first_name' => 'Michael', 'last_name' => 'Schumacher', 'slug' => 'msc-94', 'driver_code' => null]);

    codeGen()->assignAll();

    expect($a->fresh()->driver_code)->toBe('SCH')
        ->and($b->fresh()->driver_code)->toBe('SCH');
});

it('преизползва съществуващ код за същото име в друг сезон', function () {
    $s1 = Season::factory()->create(['year' => 2006]);
    $s2 = Season::factory()->create(['year' => 2001]);
    Driver::factory()->create(['season_id' => $s1->id, 'first_name' => 'Michael', 'last_name' => 'Schumacher', 'slug' => 'msc-06', 'driver_code' => 'MSC']);
    $nullRow = Driver::factory()->create(['season_id' => $s2->id, 'first_name' => 'Michael', 'last_name' => 'Schumacher', 'slug' => 'msc-01', 'driver_code' => null]);

    codeGen()->assignAll();

    expect($nullRow->fresh()->driver_code)->toBe('MSC'); // преизползва, не 'SCH'
});

it('транслитерира специални знаци (Räikkönen → RAI)', function () {
    $s = Season::factory()->create();
    $d = Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Kimi', 'last_name' => 'Räikkönen', 'slug' => 'kimi-raikkonen', 'driver_code' => null]);

    codeGen()->assignAll();

    expect($d->fresh()->driver_code)->toBe('RAI');
});

it('е идемпотентна — втори run не променя нищо', function () {
    $s = Season::factory()->create();
    Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna', 'driver_code' => null]);

    expect(codeGen()->assignAll()['updated'])->toBe(1)
        ->and(codeGen()->assignAll()['updated'])->toBe(0);
});
