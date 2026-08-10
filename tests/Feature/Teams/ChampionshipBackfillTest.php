<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\ConstructorCanonical;
use App\Models\Season;
use App\Services\Teams\CanonicalConstructorBackfiller;
use App\Services\Teams\ChampionshipBackfiller;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedTeams = function (array $slugs): void {
        $season = Season::factory()->current()->create();

        foreach ($slugs as $slug) {
            Constructor::factory()->create(['season_id' => $season->id, 'slug' => $slug, 'name' => Str::headline($slug)]);
        }

        app(CanonicalConstructorBackfiller::class)->backfill();
    };
});

it('записва конструкторските титли от конфига', function () {
    ($this->seedTeams)(['ferrari', 'mclaren', 'haas-f1-team']);

    $result = app(ChampionshipBackfiller::class)->apply();

    expect(ConstructorCanonical::where('slug', 'ferrari')->value('championships_count'))->toBe(16)
        ->and(ConstructorCanonical::where('slug', 'mclaren')->value('championships_count'))->toBe(10)
        // Хаас няма титли — не бива да получава стойност от нищото.
        ->and(ConstructorCanonical::where('slug', 'haas-f1-team')->value('championships_count'))->toBe(0)
        ->and($result['applied'])->toHaveKeys(['ferrari', 'mclaren']);
});

it('пази ръчно въведената стойност, освен при --force', function () {
    ($this->seedTeams)(['ferrari']);
    ConstructorCanonical::where('slug', 'ferrari')->update(['championships_count' => 99]);

    $result = app(ChampionshipBackfiller::class)->apply();

    expect(ConstructorCanonical::where('slug', 'ferrari')->value('championships_count'))->toBe(99)
        ->and($result['skipped'])->toHaveKey('ferrari');

    app(ChampionshipBackfiller::class)->apply(force: true);

    expect(ConstructorCanonical::where('slug', 'ferrari')->value('championships_count'))->toBe(16);
});

it('докладва отборите от конфига, които липсват в базата', function () {
    ($this->seedTeams)(['ferrari']);

    $result = app(ChampionshipBackfiller::class)->apply();

    // Тихият провал е реалният риск: сменено изписване от източника → 0 титли.
    expect($result['missing'])->toContain('mclaren', 'team-lotus');
});

it('командата попълва титлите и страницата на отбора ги показва', function () {
    ($this->seedTeams)(['ferrari']);

    $this->artisan('constructors:backfill-championships')->assertSuccessful();

    $this->get('/teams/ferrari')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.championships', 16));
});

it('конфигът покрива всички шампионати от 1958 до 2025 с валидни slug-ове', function () {
    $titles = config('team-championships');

    // 1958–2025 = 68 конструкторски шампионата; сборът трябва да ги дава точно.
    expect(array_sum($titles))->toBe(68);

    foreach ($titles as $slug => $count) {
        expect(Str::slug($slug))->toBe($slug, "slug-ът {$slug} не е в канонична форма")
            ->and($count)->toBeGreaterThan(0);
    }
});
