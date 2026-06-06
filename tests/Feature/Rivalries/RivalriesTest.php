<?php

declare(strict_types=1);

use App\Models\DriverCanonical;
use App\Models\Rivalry;
use App\Models\User;
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

it('страницата за създаване изисква вход', function () {
    $this->get('/rivalries/create')->assertRedirect(route('login'));
});

it('store изисква вход', function () {
    makeCanonical('ayrton-senna', 'Ayrton', 'Senna');
    makeCanonical('alain-prost', 'Alain', 'Prost');

    $this->post('/rivalries', ['driver_one' => 'ayrton-senna', 'driver_two' => 'alain-prost'])
        ->assertRedirect(route('login'));

    expect(Rivalry::count())->toBe(0);
});

it('логнат потребител вижда формата за създаване', function () {
    makeCanonical('ayrton-senna', 'Ayrton', 'Senna');

    $this->actingAs(User::factory()->create())
        ->get('/rivalries/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Rivalries/Create')->has('drivers'));
});

it('логнат потребител създава custom дуел', function () {
    $senna = makeCanonical('ayrton-senna', 'Ayrton', 'Senna');
    $prost = makeCanonical('alain-prost', 'Alain', 'Prost');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/rivalries', ['driver_one' => 'ayrton-senna', 'driver_two' => 'alain-prost'])
        ->assertRedirect();

    $rivalry = Rivalry::first();
    expect($rivalry)->not->toBeNull()
        ->and($rivalry->is_custom)->toBeTrue()
        ->and($rivalry->user_id)->toBe($user->id)
        ->and($rivalry->title_bg)->toBe('Senna срещу Prost')
        ->and($rivalry->driver_one_canonical_id)->toBe($senna->id)
        ->and($rivalry->driver_two_canonical_id)->toBe($prost->id);
});

it('генерира уникален slug при повтаряща се двойка', function () {
    makeCanonical('ayrton-senna', 'Ayrton', 'Senna');
    makeCanonical('alain-prost', 'Alain', 'Prost');
    $user = User::factory()->create();

    $this->actingAs($user)->post('/rivalries', ['driver_one' => 'ayrton-senna', 'driver_two' => 'alain-prost']);
    $this->actingAs($user)->post('/rivalries', ['driver_one' => 'ayrton-senna', 'driver_two' => 'alain-prost']);

    expect(Rivalry::count())->toBe(2)
        ->and(Rivalry::pluck('slug')->unique()->count())->toBe(2);
});

it('отхвърля еднакви пилоти', function () {
    makeCanonical('ayrton-senna', 'Ayrton', 'Senna');
    $user = User::factory()->create();

    $this->actingAs($user)->from('/rivalries/create')
        ->post('/rivalries', ['driver_one' => 'ayrton-senna', 'driver_two' => 'ayrton-senna'])
        ->assertSessionHasErrors('driver_two');

    expect(Rivalry::count())->toBe(0);
});
