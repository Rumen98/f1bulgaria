<?php

declare(strict_types=1);

use App\Filament\Resources\F2Drivers\Pages\CreateF2Driver;
use App\Filament\Resources\F2Drivers\Pages\ListF2Drivers;
use App\Models\F2Driver;
use App\Models\F2Season;
use App\Models\F2Team;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('отваря списъка с F2 пилоти', function () {
    $season = F2Season::create(['year' => 2024]);
    $team = F2Team::create(['f2_season_id' => $season->id, 'name' => 'Invicta', 'slug' => 'invicta']);
    $driver = F2Driver::create([
        'f2_season_id' => $season->id, 'f2_team_id' => $team->id,
        'first_name' => 'Gabriel', 'last_name' => 'Bortoleto', 'slug' => 'gabriel-bortoleto', 'is_champion' => true,
    ]);

    Livewire::test(ListF2Drivers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$driver]);
});

it('създава F2 пилот ръчно през админ', function () {
    $season = F2Season::create(['year' => 2025, 'is_current' => true]);
    $team = F2Team::create(['f2_season_id' => $season->id, 'name' => 'Campos', 'slug' => 'campos']);

    Livewire::test(CreateF2Driver::class)
        ->fillForm([
            'f2_season_id' => $season->id,
            'f2_team_id' => $team->id,
            'first_name' => 'Никола',
            'last_name' => 'Цолов',
            'slug' => 'nikola-tsolov-test',
            'country_code' => 'BGR',
            'points' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(F2Driver::where('slug', 'nikola-tsolov-test')->exists())->toBeTrue();
});
