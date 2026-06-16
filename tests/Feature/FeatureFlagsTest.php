<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

dataset('v2 routes', [
    'live' => ['/live', 'live_timing'],
    'circuits' => ['/circuits', 'circuits'],
    'compare' => ['/compare', 'compare'],
    'tsolov' => ['/tsolov', 'tsolov'],
    'f2' => ['/f2', 'f2'],
    'history' => ['/istoria', 'history'],
    'rivalries' => ['/rivalries', 'rivalries'],
]);

it('връща 404 за V2 рут при изключен флаг', function (string $url, string $flag) {
    config(["features.{$flag}" => false]);

    $this->get($url)->assertNotFound();
})->with('v2 routes');

it('пропуска V2 рут при включен флаг (не е 404)', function (string $url, string $flag) {
    config(["features.{$flag}" => true]);

    expect($this->get($url)->getStatusCode())->not->toBe(404);
})->with('v2 routes');

it('споделя feature флаговете към всички Inertia изгледи', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('features.f2')->has('features.circuits'));
});

it('статичните страници (поверителност/условия/контакт) се зареждат', function (string $url) {
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Static/Page')->has('title'));
})->with(['/poveritelnost', '/usloviya', '/kontakt']);
