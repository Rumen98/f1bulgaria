<?php

declare(strict_types=1);

use App\Models\Prediction;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Банерът е отговор на измерена дупка (12.08.2026): 9 души се връщаха редовно,
 * но само 4 прогнозираха — началната страница не ги канеше никъде и трябваше
 * сами да се сетят, че лигата съществува.
 *
 * Затова е важно и кога НЕ се показва: подсещане за нещо вече свършено или за
 * заключен кръг е шум, който обучава хората да го игнорират.
 */
beforeEach(function () {
    $this->season = Season::factory()->current()->create();
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(5),
        'qualifying_datetime_utc' => now()->addDays(4),
    ]);
});

it('подсеща влезнал потребител без прогноза за предстоящия кръг', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('predictionCta')
            ->where('predictionCta.race', $this->race->name_bg)
            ->where('predictionCta.url', route('races.show', $this->race))
            ->where('predictionCta.guest', false));
});

it('кани госта към регистрация, не към формата за прогноза', function () {
    // Формата и без това изисква вход — линк към нея би дал login redirect
    // вместо обяснение защо си струва.
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('predictionCta.race', $this->race->name_bg)
            ->where('predictionCta.url', route('register'))
            ->where('predictionCta.guest', true));
});

it('мълчи, когато човекът вече е прогнозирал', function () {
    $user = User::factory()->create();

    Prediction::factory()->create([
        'user_id' => $user->id,
        'race_id' => $this->race->id,
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('predictionCta', null));
});

it('мълчи, когато прогнозите вече са заключени', function () {
    // Квалификацията е започнала — подсещане би водило към форма, която не приема.
    $this->race->update([
        'qualifying_datetime_utc' => now()->subHour(),
        'race_datetime_utc' => now()->addHours(20),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('predictionCta', null));
});

it('мълчи пред госта също, когато кръгът е заключен', function () {
    $this->race->update([
        'qualifying_datetime_utc' => now()->subHour(),
        'race_datetime_utc' => now()->addHours(20),
    ]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('predictionCta', null));
});
