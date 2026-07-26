<?php

declare(strict_types=1);

beforeEach(function () {
    config()->set('app.url', 'https://padok.bg');
});

it('пренасочва www към каноничния хост с 301', function () {
    $this->get('https://www.padok.bg/news')
        ->assertStatus(301)
        ->assertRedirect('https://padok.bg/news');
});

it('запазва пътя и query параметрите при пренасочване', function () {
    $this->get('https://www.padok.bg/news?cat=race&page=2')
        ->assertStatus(301)
        ->assertRedirect('https://padok.bg/news?cat=race&page=2');
});

it('не пипа заявките към каноничния хост', function () {
    $this->get('https://padok.bg/news')->assertOk();
});

it('не пипа локални и служебни хостове', function () {
    $this->get('http://localhost/news')->assertOk();
});
