<?php

declare(strict_types=1);

use App\Filament\Resources\Rivalries\Pages\ListRivalries;
use App\Models\DriverCanonical;
use App\Models\Rivalry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('отваря списъка със съперничества в админ панела', function () {
    $a = DriverCanonical::create(['slug' => 'a', 'first_name' => 'A', 'last_name' => 'One']);
    $b = DriverCanonical::create(['slug' => 'b', 'first_name' => 'B', 'last_name' => 'Two']);
    $rivalry = Rivalry::create([
        'slug' => 'a-vs-b', 'driver_one_canonical_id' => $a->id, 'driver_two_canonical_id' => $b->id,
        'title_bg' => 'A срещу B', 'is_featured' => true,
    ]);

    Livewire::test(ListRivalries::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$rivalry]);
});
