<?php

declare(strict_types=1);

it('връща 404 за /admin без ключ и без бисквитка', function () {
    config()->set('app.admin_access_key', 'secret-gate-key');

    $this->get('/admin')->assertNotFound();
});

it('връща 404 при грешен ключ', function () {
    config()->set('app.admin_access_key', 'secret-gate-key');

    $this->get('/admin?key=wrong-key')->assertNotFound();
});

it('издава бисквитка и маха ключа от URL-а при валиден ключ', function () {
    config()->set('app.admin_access_key', 'secret-gate-key');

    $this->get('/admin?key=secret-gate-key')
        ->assertRedirect(url('/admin'))
        ->assertCookie('padok_admin_gate');
});

it('пуска заявки с валидна бисквитка до login формата', function () {
    config()->set('app.admin_access_key', 'secret-gate-key');

    $cookie = hash_hmac('sha256', 'secret-gate-key', (string) config('app.key'));

    // Гост с валидна бисквитка стига до auth слоя (redirect към login, не 404).
    $this->withCookie('padok_admin_gate', $cookie)
        ->get('/admin')
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('отхвърля бисквитка, издадена за стар ключ', function () {
    config()->set('app.admin_access_key', 'new-gate-key');

    $staleCookie = hash_hmac('sha256', 'old-gate-key', (string) config('app.key'));

    $this->withCookie('padok_admin_gate', $staleCookie)
        ->get('/admin')
        ->assertNotFound();
});

it('пропуска защитата при празен ключ (локална разработка)', function () {
    config()->set('app.admin_access_key', '');

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
});
