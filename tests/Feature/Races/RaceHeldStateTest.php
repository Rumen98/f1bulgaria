<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\Season;
use Inertia\Testing\AssertableInertia as Assert;

it('отбелязва изкараното състезание като проведено, дори без резултати', function () {
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);

    Race::factory()->create([
        'season_id' => $season->id, 'round' => 11,
        'race_datetime_utc' => now()->subHours(5),
    ]);

    Race::factory()->create([
        'season_id' => $season->id, 'round' => 12,
        'race_datetime_utc' => now()->addWeek(),
    ]);

    // Източникът на резултати закъснява с часове. Докато календарът делеше по
    // „има ли резултати", изкараното състезание оставаше „предстоящо" и дори
    // получаваше брояч към час в миналото.
    $this->get('/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('races.0.round', 11)
            ->where('races.0.held', true)
            ->where('races.0.finished', false)
            ->where('races.1.round', 12)
            ->where('races.1.held', false));
});
