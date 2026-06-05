<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\CanonicalDriverBackfiller;

function backfillCanonical(): array
{
    return app(CanonicalDriverBackfiller::class)->backfill();
}

it('създава 1 каноничен запис на човек и свързва per-season записите', function () {
    $s1 = Season::factory()->create(['year' => 2007, 'is_current' => false]);
    $s2 = Season::factory()->current()->create(['year' => 2008]);
    $d1 = Driver::factory()->create(['season_id' => $s1->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);
    $d2 = Driver::factory()->create(['season_id' => $s2->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);

    $r1 = Race::factory()->create(['season_id' => $s1->id]);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $d1->id]);
    $r2 = Race::factory()->create(['season_id' => $s2->id]);
    Result::factory()->position(1)->create(['race_id' => $r2->id, 'driver_id' => $d2->id, 'grid_position' => 1]); // pole

    backfillCanonical();

    expect(DriverCanonical::count())->toBe(1);

    $c = DriverCanonical::first();
    expect($c->slug)->toBe('lewis-hamilton')
        ->and($c->total_wins)->toBe(2)
        ->and($c->total_podiums)->toBe(2)
        ->and($c->total_races)->toBe(2)
        ->and($c->total_poles)->toBe(1)
        ->and($c->is_active)->toBeTrue()
        ->and($d1->fresh()->canonical_id)->toBe($c->id)
        ->and($d2->fresh()->canonical_id)->toBe($c->id);
});

it('различни хора получават различни канонични записи', function () {
    $s = Season::factory()->current()->create();
    Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Max', 'last_name' => 'Verstappen', 'slug' => 'max-verstappen', 'driver_code' => 'VER']);
    Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Jos', 'last_name' => 'Verstappen', 'slug' => 'jos-verstappen', 'driver_code' => 'VES']);

    backfillCanonical();

    expect(DriverCanonical::count())->toBe(2);
});

it('обединява пилот със split кодове в 1 каноничен запис (по slug)', function () {
    $s1 = Season::factory()->create(['year' => 1995]);
    $s2 = Season::factory()->create(['year' => 1998]);
    Driver::factory()->create(['season_id' => $s1->id, 'first_name' => 'Jan', 'last_name' => 'Magnussen', 'slug' => 'jan-magnussen', 'driver_code' => 'MAGJ']);
    Driver::factory()->create(['season_id' => $s2->id, 'first_name' => 'Jan', 'last_name' => 'Magnussen', 'slug' => 'jan-magnussen', 'driver_code' => 'MAJA']);

    backfillCanonical();

    expect(DriverCanonical::where('slug', 'jan-magnussen')->count())->toBe(1)
        ->and(Driver::whereNull('canonical_id')->count())->toBe(0);
});

it('е идемпотентна — втори run не дублира', function () {
    $s = Season::factory()->current()->create();
    Driver::factory()->create(['season_id' => $s->id, 'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna', 'driver_code' => 'SEN']);

    backfillCanonical();
    backfillCanonical();

    expect(DriverCanonical::count())->toBe(1);
});
