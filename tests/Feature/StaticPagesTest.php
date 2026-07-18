<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('политиката за поверителност има реално съдържание', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Static/Page')
            ->where('title', 'Политика за поверителност')
            ->has('sections', 8));
});

it('условията за ползване имат реално съдържание', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Static/Page')
            ->where('title', 'Условия за ползване')
            ->has('sections', 7));
});

it('контактът показва имейла от config, когато е зададен', function () {
    config()->set('app.contact_email', 'padokbg@gmail.com');

    $this->get(route('contact'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Static/Page')
            ->where('sections.0.paragraphs.1', 'Имейл: padokbg@gmail.com'));
});

it('контактът работи и без зададен имейл', function () {
    config()->set('app.contact_email', '');

    $this->get(route('contact'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Static/Page')
            ->has('sections'));
});
