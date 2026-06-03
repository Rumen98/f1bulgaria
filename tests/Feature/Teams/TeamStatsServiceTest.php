<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Teams\TeamStatsService;

it('изчислява сезонната статистика на отбора', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $a = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);
    $b = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);

    $race1 = Race::factory()->create(['season_id' => $season->id, 'round' => 1, 'pole_driver_id' => $a->id]);
    Result::factory()->position(1)->create(['race_id' => $race1->id, 'driver_id' => $a->id, 'points' => 25, 'fastest_lap' => true]);
    Result::factory()->position(3)->create(['race_id' => $race1->id, 'driver_id' => $b->id, 'points' => 15]);

    $race2 = Race::factory()->create(['season_id' => $season->id, 'round' => 2]);
    Result::factory()->dnf()->create(['race_id' => $race2->id, 'driver_id' => $a->id]);
    Result::factory()->position(2)->create(['race_id' => $race2->id, 'driver_id' => $b->id, 'points' => 18]);

    $stats = app(TeamStatsService::class)->getSeasonStats($team, $season);

    expect($stats->points)->toBe(58.0)   // 25 + 15 + 18
        ->and($stats->wins)->toBe(1)
        ->and($stats->podiums)->toBe(3)   // P1, P3, P2
        ->and($stats->poles)->toBe(1)
        ->and($stats->fastestLaps)->toBe(1)
        ->and($stats->dnfs)->toBe(1)
        ->and($stats->position)->toBe(1); // единствен отбор
});
