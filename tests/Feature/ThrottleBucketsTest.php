<?php

declare(strict_types=1);

use App\Models\TeamNewsItem;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
 * Регресия: голите 'throttle:X,Y' делят една кофа на потребител (ключът е само
 * sha1(user id), съотв. sha1(domain|ip) за гост). Всеки rate-limited рут трябва
 * да е с изричен префикс ('throttle:X,Y,prefix'), иначе бърст по единия рут
 * връща 429 на останалите.
 */

beforeEach(function () {
    config(['features.quiz' => true]);
});

it('бърст по коментарите не изчерпва throttle кофите на куиза и бюлетина', function () {
    $user = User::factory()->create();
    $item = TeamNewsItem::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post(route('news.comments.store', $item->slug), ['body' => "Коментар {$i}"]);
    }

    $this->actingAs($user)
        ->post(route('news.comments.store', $item->slug), ['body' => 'Шестият'])
        ->assertTooManyRequests();

    $this->actingAs($user)
        ->post(route('quiz.score'), ['answers' => [['id' => 999999, 'choice' => 1]]])
        ->assertRedirect(route('quiz'));

    $this->actingAs($user)
        ->post(route('newsletter.subscribe'), ['email' => 'fan@example.bg'])
        ->assertRedirect();
});

it('бърст по куиза от гост не изчерпва throttle кофата на бюлетина', function () {
    foreach (range(1, 10) as $i) {
        $this->post(route('quiz.score'), ['answers' => [['id' => 999999 + $i, 'choice' => 1]]]);
    }

    $this->post(route('quiz.score'), ['answers' => [['id' => 999999, 'choice' => 1]]])
        ->assertTooManyRequests();

    $this->post(route('newsletter.subscribe'), ['email' => 'fan@example.bg'])
        ->assertRedirect();
});

it('всеки числов throttle middleware има изричен префикс на кофата', function () {
    // Кръстосаните тестове по-долу хващат само два едновременно голи рута —
    // гола кофа се сблъсква само с друга гола кофа. Този тест хваща и единична
    // регресия/нов рут: 'throttle:N,M' без трети сегмент е забранен.
    $bare = collect(Route::getRoutes())
        ->flatMap(fn ($route) => collect($route->middleware())
            ->filter(fn ($middleware) => is_string($middleware)
                && preg_match('/^throttle:[^,]+,[^,]+$/', $middleware) === 1)
            ->map(fn ($middleware) => $route->uri()." → {$middleware}"))
        ->values()
        ->all();

    expect($bare)->toBe([]);
});

it('бърст по забравена парола не изчерпва throttle кофата на регистрацията', function () {
    Notification::fake();

    foreach (range(1, 6) as $i) {
        $this->post(route('password.email'), ['email' => "nyama-takava-{$i}@example.bg"]);
    }

    $this->post(route('password.email'), ['email' => 'sedmi@example.bg'])
        ->assertTooManyRequests();

    $this->post('/register', [
        'name' => 'Нов Фен',
        'email' => 'nov-fen@example.bg',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

it('бърст по повторното изпращане на verification мейл не блокира самата верификация', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->post(route('verification.send'));
    }

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertTooManyRequests();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('ограничава подаването на нова парола, без да изчерпва кофата на забравена парола', function () {
    foreach (range(1, 6) as $i) {
        $this->post(route('password.store'), [
            'token' => 'nevaliden-token',
            'email' => "fen-{$i}@example.bg",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    $this->post(route('password.store'), [
        'token' => 'nevaliden-token',
        'email' => 'sedmi@example.bg',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertTooManyRequests();

    $this->post(route('password.email'), ['email' => 'fen@example.bg'])
        ->assertRedirect();
});

it('ограничава потвърждаването на парола по потребител, без да блокира други потребители', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->post('/confirm-password', ['password' => 'greshna-parola']);
    }

    $this->actingAs($user)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertTooManyRequests();

    $other = User::factory()->create();

    $this->actingAs($other)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});
