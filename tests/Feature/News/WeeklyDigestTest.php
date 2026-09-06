<?php

declare(strict_types=1);

use App\Mail\WeeklyDigestMail;
use App\Models\Badge;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Models\NewsletterSend;
use App\Models\NewsletterSubscriber;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->season = Season::factory()->create(['is_current' => true]);
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->subDay(),
    ]);
    Result::factory()->create(['race_id' => $this->race->id, 'position' => 1]);
});

it('праща дайджеста на потребители И на бюлетинни абонати', function () {
    Mail::fake();

    User::factory()->create(['email' => 'igrach@example.bg']);
    NewsletterSubscriber::create([
        'email' => 'abonat@example.bg',
        'unsubscribe_token' => 'tok-abonat',
        'subscribed_at' => now(),
    ]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo('igrach@example.bg') && $mail->userStats !== null);
    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo('abonat@example.bg')
        && $mail->userStats === null
        && $mail->unsubscribeToken === 'tok-abonat');
});

it('не дублира имейл, който е и потребител, и абонат', function () {
    Mail::fake();

    User::factory()->create(['email' => 'dvojnik@example.bg']);
    NewsletterSubscriber::create([
        'email' => 'dvojnik@example.bg',
        'unsubscribe_token' => 'tok-dvojnik',
        'subscribed_at' => now(),
    ]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('не праща нищо, ако последното състезание е по-старо от 14 дни (пауза/междусезоние)', function () {
    Mail::fake();

    $this->race->update(['race_datetime_utc' => now()->subDays(20)]);
    User::factory()->create(['email' => 'igrach@example.bg']);
    NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => 't3', 'subscribed_at' => now()]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('наваксва неизпратен кръг до 14 дни назад (късен старт или бавни резултати)', function () {
    Mail::fake();

    $this->race->update(['race_datetime_utc' => now()->subDays(10)]);
    User::factory()->create(['email' => 'igrach@example.bg']);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo('igrach@example.bg'));
});

it('не праща втори път за същия кръг (send-tracking)', function () {
    Mail::fake();

    User::factory()->create(['email' => 'igrach@example.bg']);

    $this->artisan('f1:weekly-digest')->assertSuccessful();
    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('пропуска кръг само със спринт резултати (неделното състезание още тече)', function () {
    Mail::fake();

    // Старият кръг е вече изпратен; новият спринтов има само съботни редове.
    NewsletterSend::create(['mail_type' => NewsletterSend::TYPE_DIGEST, 'race_id' => $this->race->id, 'sent_at' => now()->subWeek()]);
    $sprintRace = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->subHours(2),
        'has_sprint' => true,
    ]);
    Result::factory()->sprint()->create(['race_id' => $sprintRace->id, 'position' => 1]);
    User::factory()->create();

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('--race отказва състезание от друг сезон (би смесил данни)', function () {
    Mail::fake();

    $oldSeason = Season::factory()->create(['is_current' => false, 'year' => 2020]);
    $oldRace = Race::factory()->create(['season_id' => $oldSeason->id, 'race_datetime_utc' => now()->subYears(2)]);
    Result::factory()->create(['race_id' => $oldRace->id, 'position' => 1]);
    User::factory()->create();

    $this->artisan('f1:weekly-digest', ['--race' => $oldRace->id])->assertSuccessful();

    Mail::assertNothingQueued();
});

it('не праща на потребител със спрени имейли (опт-аут)', function () {
    Mail::fake();

    User::factory()->create(['email_opt_out_at' => now()]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('потребителската версия носи signed линк за спиране на имейлите', function () {
    Mail::fake();

    User::factory()->create(['email' => 'igrach@example.bg']);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->userUnsubscribeUrl !== null
        && str_contains($mail->userUnsubscribeUrl, '/newsletter/email-stop/'));
});

it('опцията --race заобикаля 7-дневния прозорец (ръчен re-send)', function () {
    Mail::fake();

    $this->race->update(['race_datetime_utc' => now()->subDays(30)]);
    User::factory()->create(['email' => 'igrach@example.bg']);

    $this->artisan('f1:weekly-digest', ['--race' => $this->race->id])->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('пропуска отписаните абонати', function () {
    Mail::fake();

    NewsletterSubscriber::create(['email' => 'otpisan@example.bg', 'unsubscribe_token' => 't2', 'subscribed_at' => now(), 'unsubscribed_at' => now()]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('включва Ф2 секцията при кръг на Цолов през седмицата', function () {
    Mail::fake();
    User::factory()->create();

    $f2Season = F2Season::create(['year' => 2026, 'is_current' => true]);
    $tsolov = F2Driver::create([
        'f2_season_id' => $f2Season->id,
        'first_name' => 'Nikola',
        'last_name' => 'Tsolov',
        'slug' => 'nikola-tsolov',
        'position' => 2,
        'points' => 113,
    ]);
    $f2Race = F2Race::create([
        'f2_season_id' => $f2Season->id,
        'round' => 10,
        'location_name' => 'Будапеща',
        'country_name' => 'Унгария',
        'race_datetime_utc' => now()->subDay(),
        'slug' => '2026-budapest',
    ]);
    $session = F2RaceSession::create([
        'f2_race_id' => $f2Race->id,
        'session_type' => 'feature_race',
        'scheduled_at_utc' => now()->subDay(),
        'state' => 'completed',
    ]);
    F2Result::create([
        'f2_race_session_id' => $session->id,
        'f2_driver_id' => $tsolov->id,
        'position' => 3,
        'points' => 15,
        'status' => 'Finished',
    ]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->f2 !== null
        && $mail->f2['race'] === 'Будапеща, Унгария'
        && $mail->f2['standings_position'] === 2
        && $mail->f2['results'] === [['session' => 'Главно състезание', 'position' => 3, 'status' => 'Finished']]);
});

it('пропуска Ф2 секцията без кръг през последната седмица', function () {
    Mail::fake();
    User::factory()->create();

    $f2Season = F2Season::create(['year' => 2026, 'is_current' => true]);
    F2Driver::create([
        'f2_season_id' => $f2Season->id,
        'first_name' => 'Nikola',
        'last_name' => 'Tsolov',
        'slug' => 'nikola-tsolov',
        'position' => 2,
        'points' => 113,
    ]);
    F2Race::create([
        'f2_season_id' => $f2Season->id,
        'round' => 9,
        'location_name' => 'Спа',
        'race_datetime_utc' => now()->subMonth(),
        'slug' => '2026-spa',
    ]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->f2 === null);
});

it('включва топ новини само от последната седмица и само публични', function () {
    Mail::fake();
    User::factory()->create();

    TeamNewsItem::factory()->approved()->create([
        'published_at' => now()->subDay(),
        'importance_score' => 9,
        'title_bg' => 'Голям трансфер',
    ]);
    TeamNewsItem::factory()->approved()->create(['published_at' => now()->subMonth()]);
    TeamNewsItem::factory()->create(['published_at' => now()->subDay()]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => count($mail->news) === 1
        && $mail->news[0]['title'] === 'Голям трансфер');
});

it('личната статистика включва позиция в лигата и новите значки', function () {
    Mail::fake();

    $user = User::factory()->create();
    Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $this->race->id]);

    $fresh = Badge::factory()->create(['name' => 'Точен мерник']);
    $user->badges()->attach($fresh, ['awarded_at' => now()->subDay()]);
    $old = Badge::factory()->create(['name' => 'Ветеран']);
    $user->badges()->attach($old, ['awarded_at' => now()->subMonth()]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => ($mail->userStats['rank'] ?? null) === 1
        && $mail->userStats['players'] === 1
        && $mail->userStats['new_badges'] === ['Точен мерник']);
});

it('рендерира Ф2 и новинарските секции в шаблона', function () {
    $mail = new WeeklyDigestMail(
        $this->race,
        [['position' => 1, 'driver' => 'Макс Верстапен', 'fastest_lap' => false]],
        [],
        userStats: null,
        unsubscribeToken: 'tok-render-2',
        f2: [
            'race' => 'Будапеща, Унгария',
            'results' => [['session' => 'Главно състезание', 'position' => 3, 'status' => 'Finished']],
            'standings_position' => 2,
            'points' => 113.0,
        ],
        news: [['title' => 'Голям трансфер', 'url' => 'https://padok.bg/news/golyam-transfer']],
    );

    $html = $mail->render();

    expect($html)->toContain('Никола Цолов')
        ->and($html)->toContain('P3')
        ->and($html)->toContain('Голям трансфер');
});

it('абонатската версия рендерира без лична статистика и с линк за отписване', function () {
    $mail = new WeeklyDigestMail(
        $this->race,
        [['position' => 1, 'driver' => 'Макс Верстапен', 'fastest_lap' => false]],
        [],
        userStats: null,
        unsubscribeToken: 'tok-render',
    );

    $html = $mail->render();

    expect($html)->toContain('Отпиши се')
        ->and($html)->toContain('newsletter/unsubscribe/tok-render')
        ->and($html)->not->toContain('Твоята статистика');
});

describe('пращане в мига, в който резултатите дойдат', function () {
    it('върви ежечасно, а не в един фиксиран неделен час', function () {
        // Фиксираният час беше единствен изстрел: Jolpica закъснее ли, рекапът
        // се пропуска, а следващият кръг го изяжда (подредба по най-нов).
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'f1:weekly-digest'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 * * * *')
            ->and($event->timezone)->toBe('Europe/Sofia');
    });

    it('мълчи посред нощ, за да не буди хората', function () {
        $this->travelTo(Carbon\Carbon::parse('2026-09-07 03:00', 'Europe/Sofia'));

        $this->artisan('f1:weekly-digest')
            ->expectsOutputToContain('Извън приличния часови прозорец')
            ->assertSuccessful();
    });

    it('--any-hour заобикаля прозореца за ръчно пускане', function () {
        $this->travelTo(Carbon\Carbon::parse('2026-09-07 03:00', 'Europe/Sofia'));

        // Стига до нормалните гардове, вместо да излезе на часа.
        $this->artisan('f1:weekly-digest --any-hour')
            ->doesntExpectOutputToContain('Извън приличния часови прозорец')
            ->assertSuccessful();
    });
});
