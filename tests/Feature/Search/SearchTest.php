<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Извлича заглавията от една група в резултата.
 *
 * @return array<int, string>
 */
function searchTitles(array $groups, string $key): array
{
    foreach ($groups as $group) {
        if ($group['key'] === $key) {
            return array_column($group['items'], 'title');
        }
    }

    return [];
}

it('отваря се празна без заявка', function () {
    $this->get('/tarsene')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search/Index')
            ->where('term', '')
            ->where('total', 0)
        );
});

it('не търси под минималната дължина', function () {
    Driver::factory()->create(['last_name' => 'Verstappen']);

    $this->get('/tarsene?q=V')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('total', 0));
});

it('намира пилот по латинско име', function () {
    Driver::factory()->create(['first_name' => 'Max', 'last_name' => 'Verstappen', 'slug' => 'max-verstappen']);

    $response = $this->get('/tarsene?q=Verstappen')->assertOk();

    $groups = $response->viewData('page')['props']['groups'];

    expect(searchTitles($groups, 'drivers'))->toContain('Макс Верстапен');
});

it('намира пилот по българското му име, което не е в базата', function () {
    // Кирилицата живее в config/driver-names-bg.php — SQL LIKE по базата не би я хванал.
    config(['driver-names-bg' => ['test-driver' => 'Тестов Пилот']]);
    Driver::factory()->create(['first_name' => 'Test', 'last_name' => 'Driver', 'slug' => 'test-driver']);

    $response = $this->get('/tarsene?q=Тестов')->assertOk();

    expect(searchTitles($response->viewData('page')['props']['groups'], 'drivers'))
        ->toContain('Тестов Пилот');
});

it('намира отбор по българското му име', function () {
    config(['team-brands' => ['scuderia' => ['name_bg' => 'Ферари']]]);
    Constructor::factory()->create(['name' => 'Scuderia', 'slug' => 'scuderia']);

    $response = $this->get('/tarsene?q=Ферари')->assertOk();

    expect(searchTitles($response->viewData('page')['props']['groups'], 'teams'))
        ->toContain('Ферари');
});

it('намира публикувана новина по заглавие', function () {
    TeamNewsItem::factory()->create([
        'title_bg' => 'Ферари представя нов двигател',
        'status' => NewsStatus::AutoPublished->value,
    ]);

    $response = $this->get('/tarsene?q=двигател')->assertOk();

    expect(searchTitles($response->viewData('page')['props']['groups'], 'news'))
        ->toContain('Ферари представя нов двигател');
});

it('не показва непубликувани новини', function () {
    TeamNewsItem::factory()->create([
        'title_bg' => 'Скрита новина за спойлера',
        'status' => NewsStatus::Rejected->value,
    ]);

    $response = $this->get('/tarsene?q=спойлера')->assertOk();

    expect(searchTitles($response->viewData('page')['props']['groups'], 'news'))->toBeEmpty();
});

it('намира състезание по име на пистата', function () {
    $season = Season::factory()->create();
    Race::factory()->create([
        'season_id' => $season->id,
        'name' => 'Italian Grand Prix',
        'circuit' => 'Autodromo Nazionale di Monza',
        'jolpica_id' => 'monza',
    ]);

    $response = $this->get('/tarsene?q=Monza')->assertOk();

    expect($response->viewData('page')['props']['total'])->toBeGreaterThan(0);
});

it('намира термин от речника', function () {
    $response = $this->get('/tarsene?q=DRS')->assertOk();

    expect(searchTitles($response->viewData('page')['props']['groups'], 'glossary'))
        ->toContain('DRS');
});

it('крие пистите, когато модулът е изключен', function () {
    config(['features.circuits' => false]);
    $season = Season::factory()->create();
    Race::factory()->create([
        'season_id' => $season->id,
        'circuit' => 'Monza Circuit',
        'jolpica_id' => 'monza',
    ]);

    $response = $this->get('/tarsene?q=Monza')->assertOk();

    expect(searchTitles($response->viewData('page')['props']['groups'], 'circuits'))->toBeEmpty();
});

it('не индексира страницата с резултати', function () {
    $this->get('/tarsene?q=нещо')
        ->assertOk()
        ->assertSee('name="robots" content="noindex, follow"', false);
});

it('отхвърля прекалено дълга заявка', function () {
    $this->get('/tarsene?q='.str_repeat('a', 200))->assertSessionHasErrors('q');
});
