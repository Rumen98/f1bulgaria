<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Hero-то показваше „Уикендът тече" и бутон „Подай прогноза" ЧАС СЛЕД
 * финала, защото и двете висяха на наличието на победител — а победителят
 * идва от Jolpica, която публикува със закъснение.
 *
 * Часовникът знае, че състезанието е започнало, в мига в който започне.
 * Тези тестове държат състоянието вързано за него, а не за синхрона.
 */
function raceWeekend(string $raceAt): Race
{
    $season = Season::factory()->create(['is_current' => true]);

    $race = Race::factory()->create([
        'season_id' => $season->id,
        'race_datetime_utc' => $raceAt,
        'qualifying_datetime_utc' => CarbonImmutable::parse($raceAt)->subDay(),
    ]);

    RaceSession::factory()->create([
        'race_id' => $race->id,
        'type' => 'race',
        'scheduled_at_utc' => $raceAt,
    ]);

    return $race;
}

it('след старта на състезанието не кани към прогноза, дори без резултати', function () {
    // Точно дупката от продъкшън: изкарано състезание, Jolpica още мълчи.
    raceWeekend(now()->subHours(2)->toDateTimeString());

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hero.race_started', true)
            ->where('hero.predictions_locked', true)
            ->where('hero.winner', null));
});

it('преди уикенда още кани към прогноза', function () {
    raceWeekend(now()->addDay()->toDateTimeString());

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hero.race_started', false));
});

it('заключването изпреварва старта — между квалификацията и състезанието вече е късно', function () {
    // Прогнозите се заключват 5 мин преди квалификацията, тоест има цял
    // прозорец, в който състезанието не е тръгнало, но формата не приема.
    $race = raceWeekend(now()->addHours(20)->toDateTimeString());
    $race->update(['qualifying_datetime_utc' => now()->subHour()]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hero.race_started', false)
            ->where('hero.predictions_locked', true));
});

it('три часа след старта състезанието е приключило, дори без резултати', function () {
    // Точно случаят от продъкшън: изкарано отдавна, Jolpica мълчи, а hero-то
    // упорито твърдеше „Състезанието тече". Регламентът дава максимум 3 часа
    // общо, значи след тях няма съмнение.
    raceWeekend(now()->subHours(4)->toDateTimeString());

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hero.race_started', true)
            ->where('hero.race_finished', true));
});

it('час след старта състезанието още тече — не бързаме да го обявим за свършило', function () {
    // Обратната грешка е също толкова лоша: „приключи" по средата на кръга.
    raceWeekend(now()->subHour()->toDateTimeString());

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hero.race_started', true)
            ->where('hero.race_finished', false));
});

it('без OpenF1 креденшъли hero-то оцелява — часовникът поема', function () {
    // Победителят е бонус, не изискване. Ако OpenF1 е недостъпен, надписът
    // пак е верен, само без име.
    config(['features.live_timing' => false]);
    raceWeekend(now()->subHours(4)->toDateTimeString());

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hero.race_finished', true)
            ->where('hero.winner', null));
});
