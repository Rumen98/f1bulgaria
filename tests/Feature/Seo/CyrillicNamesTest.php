<?php

declare(strict_types=1);

use App\Models\ConstructorCanonical;
use App\Models\DriverCanonical;
use App\Support\DriverName;

it('връща българското име на познат пилот', function () {
    expect(DriverName::bg('lewis-hamilton'))->toBe('Люис Хамилтън')
        ->and(DriverName::bg('charles-leclerc'))->toBe('Шарл Льоклер');
});

it('пада към латиницата за непознат пилот', function () {
    expect(DriverName::bg('adolf-brudes'))->toBeNull()
        ->and(DriverName::display('adolf-brudes', 'Adolf Brudes'))->toBe('Adolf Brudes');
});

it('both() дава и двата варианта, но не дублира при липса на превод', function () {
    expect(DriverName::both('lewis-hamilton', 'Lewis Hamilton'))->toBe('Люис Хамилтън (Lewis Hamilton)')
        ->and(DriverName::both('adolf-brudes', 'Adolf Brudes'))->toBe('Adolf Brudes');
});

it('страницата на пилот е с кирилица в h1, title и описанието', function () {
    DriverCanonical::query()->create([
        'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton',
    ]);

    $html = $this->get('/drivers/lewis-hamilton')->assertOk()->getContent();

    expect($html)->toContain('<title>Люис Хамилтън — Падок</title>')
        ->and($html)->toContain('Статистика и кариера на Люис Хамилтън')
        // Латиницата остава — покрива и заявките на латиница.
        ->and($html)->toContain('Lewis Hamilton')
        ->and($html)->toContain('"alternateName":"Lewis Hamilton"');
});

it('подава латинското име отделно, за да се покаже като подзаглавие', function () {
    DriverCanonical::query()->create([
        'first_name' => 'Max', 'last_name' => 'Verstappen', 'slug' => 'max-verstappen',
    ]);

    $this->get('/drivers/max-verstappen')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('driver.name', 'Макс Верстапен')
            ->where('driver.name_latin', 'Max Verstappen'));
});

it('не подава дублирано подзаглавие за непреведен пилот', function () {
    DriverCanonical::query()->create([
        'first_name' => 'Adolf', 'last_name' => 'Brudes', 'slug' => 'adolf-brudes',
    ]);

    $this->get('/drivers/adolf-brudes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('driver.name', 'Adolf Brudes')
            ->where('driver.name_latin', null));
});

it('страницата на отбор е с кирилица в title и Person/SportsTeam schema', function () {
    ConstructorCanonical::query()->create([
        'name' => 'Ferrari', 'slug' => 'ferrari', 'total_races' => 100,
    ]);

    $html = $this->get('/teams/ferrari')->assertOk()->getContent();

    expect($html)->toContain('<title>Ферари — Падок</title>')
        ->and($html)->toContain('Ферари (Ferrari)')
        ->and($html)->toContain('"@type":"SportsTeam"');
});

it('всички български имена са наистина на кирилица', function () {
    /** @var array<string, string> $names */
    $names = config('driver-names-bg');

    foreach ($names as $slug => $bg) {
        expect($bg)->toMatch('/^[\p{Cyrillic}\s\-]+$/u', "„{$bg}“ ({$slug}) съдържа некирилски знаци");
    }
});

it('няма дублирани ключове или празни имена', function () {
    /** @var array<string, string> $names */
    $names = config('driver-names-bg');

    expect($names)->not->toBeEmpty();

    foreach ($names as $slug => $bg) {
        expect(trim($bg))->not->toBe('', "празно име за {$slug}")
            ->and($slug)->toMatch('/^[a-z0-9-]+$/', "невалиден slug: {$slug}");
    }
});
