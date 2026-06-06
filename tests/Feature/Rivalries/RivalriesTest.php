<?php

declare(strict_types=1);

use App\Models\DriverCanonical;
use App\Models\Rivalry;
use Database\Seeders\RivalrySeeder;
use Inertia\Testing\AssertableInertia as Assert;

function makeCanonical(string $slug, string $first, string $last): DriverCanonical
{
    return DriverCanonical::create([
        'slug' => $slug, 'first_name' => $first, 'last_name' => $last,
        'first_race_at' => '1988-04-01', 'last_race_at' => '1993-11-01',
        'total_wins' => 40, 'total_podiums' => 80, 'total_poles' => 60, 'total_races' => 160,
    ]);
}

function makeRivalry(): Rivalry
{
    $senna = makeCanonical('ayrton-senna', 'Ayrton', 'Senna');
    $prost = makeCanonical('alain-prost', 'Alain', 'Prost');

    return Rivalry::create([
        'slug' => 'senna-vs-prost',
        'driver_one_canonical_id' => $senna->id,
        'driver_two_canonical_id' => $prost->id,
        'era_start_year' => 1988, 'era_end_year' => 1993,
        'title_bg' => 'Сена срещу Прост',
        'description_bg' => 'Най-интензивното съперничество.',
        'notable_moments' => [['year' => 1989, 'description' => 'Сузука.']],
        'is_featured' => true,
    ]);
}

it('индексът на съперничествата връща 200 със списък', function () {
    makeRivalry();

    $this->get('/rivalries')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rivalries/Index')
            ->has('rivalries', 1)
            ->where('rivalries.0.title', 'Сена срещу Прост'));
});

it('детайлната страница на съперничество връща 200 с comparison', function () {
    makeRivalry();

    $this->get('/rivalries/senna-vs-prost')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rivalries/Show')
            ->where('rivalry.title', 'Сена срещу Прост')
            ->has('rivalry.moments', 1)
            ->has('a.name')
            ->has('b.name')
            ->has('comparison.career'));
});

it('връща 404 за несъществуващо съперничество', function () {
    $this->get('/rivalries/nonexistent')->assertNotFound();
});

it('сийдърът създава съперничество когато каноничните пилоти съществуват', function () {
    makeCanonical('ayrton-senna', 'Ayrton', 'Senna');
    makeCanonical('alain-prost', 'Alain', 'Prost');

    app(RivalrySeeder::class)->run();

    expect(Rivalry::where('slug', 'senna-vs-prost')->exists())->toBeTrue();
});

it('сийдърът пропуска съперничество ако липсва каноничен пилот', function () {
    // Само единият съществува → двойката се пропуска.
    makeCanonical('ayrton-senna', 'Ayrton', 'Senna');

    app(RivalrySeeder::class)->run();

    expect(Rivalry::where('slug', 'senna-vs-prost')->exists())->toBeFalse();
});
