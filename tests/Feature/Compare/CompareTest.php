<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\CanonicalDriverBackfiller;
use App\Services\Drivers\ComparisonService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Създава двама канонични пилоти с резултати и връща [a, b] canonical модели.
 */
function seedComparablePair(): array
{
    $s1 = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    $s2 = Season::factory()->create(['year' => 1991, 'is_current' => false]);
    $mclaren = Constructor::factory()->create(['season_id' => $s1->id, 'name' => 'McLaren', 'slug' => 'mclaren']);
    $ferrari = Constructor::factory()->create(['season_id' => $s1->id, 'name' => 'Ferrari', 'slug' => 'ferrari']);

    $senna = Driver::factory()->create(['season_id' => $s1->id, 'constructor_id' => $mclaren->id, 'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna']);
    $prost = Driver::factory()->create(['season_id' => $s1->id, 'constructor_id' => $ferrari->id, 'first_name' => 'Alain', 'last_name' => 'Prost', 'slug' => 'alain-prost']);

    // Общо състезание 1990: Senna побеждава (P1, grid 1), Prost втори (P2, grid 3).
    $monaco = Race::factory()->create(['season_id' => $s1->id, 'jolpica_id' => 'monaco', 'circuit' => 'Monaco', 'race_datetime_utc' => Carbon::create(1990, 5, 27)]);
    Result::factory()->position(1)->create(['race_id' => $monaco->id, 'driver_id' => $senna->id, 'grid_position' => 1]);
    Result::factory()->position(2)->create(['race_id' => $monaco->id, 'driver_id' => $prost->id, 'grid_position' => 3]);

    app(CanonicalDriverBackfiller::class)->backfill();

    return [
        DriverCanonical::where('slug', 'ayrton-senna')->first(),
        DriverCanonical::where('slug', 'alain-prost')->first(),
    ];
}

it('страницата за избор връща 200 с пилоти и пресети', function () {
    $s = Season::factory()->current()->create();
    Driver::factory()->create(['season_id' => $s->id, 'slug' => 'max-verstappen']);
    app(CanonicalDriverBackfiller::class)->backfill();

    $this->get('/compare')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Compare/Index')
            ->has('drivers')
            ->has('presets'));
});

it('сравнението на двама пилоти връща 200', function () {
    seedComparablePair();

    $this->get('/compare/ayrton-senna/alain-prost')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Compare/Show')
            // `name` е името за екрана — DriverName::display дава кирилица за
            // slug-овете от config/driver-names-bg.php. Ключовете и slug-овете
            // в URL-а остават на латиница.
            ->where('a.name', 'Айртон Сена')
            ->where('b.name', 'Ален Прост')
            ->has('comparison.career.a')
            ->has('comparison.era_overlap')
            ->has('comparison.common_circuits'));
});

it('връща 404 ако някой от пилотите не съществува', function () {
    seedComparablePair();

    $this->get('/compare/ayrton-senna/nonexistent')->assertNotFound();
});

it('изчислява era overlap и head-to-head за припокриващи се пилоти', function () {
    [$senna, $prost] = seedComparablePair();

    $result = app(ComparisonService::class)->compare($senna, $prost);

    expect($result['era_overlap'])->not->toBeNull()
        ->and($result['era_overlap']['start_year'])->toBe(1990)
        ->and($result['head_to_head']['races_together'])->toBe(1)
        ->and($result['head_to_head']['qualifying']['a'])->toBe(1)  // Senna по-добър старт
        ->and($result['head_to_head']['race']['a'])->toBe(1)        // Senna по-добър финиш
        ->and($result['common_circuits'])->toHaveCount(1)
        ->and($result['common_circuits']->first()['circuit_slug'])->toBe('monaco');
});

it('era overlap е NULL за пилоти от различни ери (Senna † 1994 vs Hamilton 2007+)', function () {
    $s90 = Season::factory()->create(['year' => 1991, 'is_current' => false]);
    $s94 = Season::factory()->create(['year' => 1994, 'is_current' => false]);
    $s07 = Season::factory()->create(['year' => 2007, 'is_current' => false]);
    $s20 = Season::factory()->create(['year' => 2020, 'is_current' => false]);

    $senna1 = Driver::factory()->create(['season_id' => $s90->id, 'slug' => 'ayrton-senna', 'first_name' => 'Ayrton', 'last_name' => 'Senna']);
    $senna2 = Driver::factory()->create(['season_id' => $s94->id, 'slug' => 'ayrton-senna', 'first_name' => 'Ayrton', 'last_name' => 'Senna']);
    $ham1 = Driver::factory()->create(['season_id' => $s07->id, 'slug' => 'lewis-hamilton', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ham2 = Driver::factory()->create(['season_id' => $s20->id, 'slug' => 'lewis-hamilton', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);

    foreach ([[$senna1, $s90], [$senna2, $s94], [$ham1, $s07], [$ham2, $s20]] as [$d, $s]) {
        $race = Race::factory()->create(['season_id' => $s->id, 'jolpica_id' => 'monaco', 'race_datetime_utc' => Carbon::create($s->year, 5, 1)]);
        Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $d->id]);
    }

    app(CanonicalDriverBackfiller::class)->backfill();
    $senna = DriverCanonical::where('slug', 'ayrton-senna')->first();
    $hamilton = DriverCanonical::where('slug', 'lewis-hamilton')->first();

    $result = app(ComparisonService::class)->compare($senna, $hamilton);

    expect($result['era_overlap'])->toBeNull()
        ->and($result['head_to_head'])->toBeNull();
});
