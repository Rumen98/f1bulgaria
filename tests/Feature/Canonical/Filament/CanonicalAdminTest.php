<?php

declare(strict_types=1);

use App\Filament\Resources\ConstructorCanonicals\Pages\EditConstructorCanonical;
use App\Filament\Resources\ConstructorCanonicals\Pages\ListConstructorCanonicals;
use App\Filament\Resources\DriverCanonicals\Pages\EditDriverCanonical;
use App\Filament\Resources\DriverCanonicals\Pages\ListDriverCanonicals;
use App\Models\ConstructorCanonical;
use App\Models\DriverCanonical;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('отваря списъка с канонични пилоти', function () {
    $drivers = collect([
        DriverCanonical::query()->create(['first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'total_wins' => 104]),
        DriverCanonical::query()->create(['first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna', 'total_wins' => 41]),
    ]);

    Livewire::test(ListDriverCanonicals::class)
        ->assertOk()
        ->assertCanSeeTableRecords($drivers);
});

it('edit page запазва ръчните полета на каноничен пилот', function () {
    $driver = DriverCanonical::query()->create([
        'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton',
        'is_active' => false, 'total_wins' => 104,
    ]);

    Livewire::test(EditDriverCanonical::class, ['record' => $driver->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'bio_bg' => 'Седемкратен световен шампион.',
            'country_code' => 'GBR',
            'permanent_number' => 44,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $driver->refresh();
    expect($driver->bio_bg)->toBe('Седемкратен световен шампион.')
        ->and($driver->country_code)->toBe('GBR')
        ->and($driver->permanent_number)->toBe(44)
        ->and($driver->is_active)->toBeTrue()
        ->and($driver->total_wins)->toBe(104); // изчисленото поле остава непокътнато
});

it('отваря списъка с канонични отбори', function () {
    $teams = collect([
        ConstructorCanonical::query()->create(['name' => 'Ferrari', 'slug' => 'ferrari', 'total_wins' => 240]),
        ConstructorCanonical::query()->create(['name' => 'McLaren', 'slug' => 'mclaren', 'total_wins' => 193]),
    ]);

    Livewire::test(ListConstructorCanonicals::class)
        ->assertOk()
        ->assertCanSeeTableRecords($teams);
});

it('edit page запазва ръчните полета на каноничен отбор', function () {
    $team = ConstructorCanonical::query()->create([
        'name' => 'Ferrari', 'slug' => 'ferrari', 'is_active' => false, 'total_wins' => 240,
    ]);

    Livewire::test(EditConstructorCanonical::class, ['record' => $team->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'bio_bg' => 'Скудерия Ферари.',
            'color_hex' => '#dc0000',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $team->refresh();
    expect($team->bio_bg)->toBe('Скудерия Ферари.')
        ->and($team->color_hex)->toBe('#dc0000')
        ->and($team->is_active)->toBeTrue()
        ->and($team->total_wins)->toBe(240); // изчисленото поле остава непокътнато
});
