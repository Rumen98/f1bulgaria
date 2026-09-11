<?php

declare(strict_types=1);

use App\Mail\GameAnnouncementMail;
use App\Models\NewsletterSend;
use App\Models\NewsletterSubscriber;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    // Писмото води към /game — при изключен флаг командата отказва.
    config(['features.game' => true]);
});

it('праща на потребителите и на бюлетинните абонати', function () {
    $user = User::factory()->create();
    $subscriber = NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, 2);
    Mail::assertSent(GameAnnouncementMail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertSent(GameAnnouncementMail::class, fn ($mail) => $mail->hasTo($subscriber->email));
});

it('прескача банати и спрели имейлите потребители', function () {
    User::factory()->create(['banned_at' => now()]);
    User::factory()->create(['email_opt_out_at' => now()]);

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertNothingSent();
});

it('не праща втори път при повторен пуск', function () {
    User::factory()->create();

    $this->artisan('padok:announce-game')->assertSuccessful();
    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, 1);
    expect(NewsletterSend::query()->where('mail_type', 'announcement-2026-09-game')->count())->toBe(1);
});

it('force пуска повторно', function () {
    User::factory()->create();

    $this->artisan('padok:announce-game')->assertSuccessful();
    $this->artisan('padok:announce-game', ['--force' => true])->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, 2);
});

it('dry-run не праща и не маркира', function () {
    User::factory()->create();

    $this->artisan('padok:announce-game', ['--dry-run' => true])
        ->expectsOutputToContain('1 потребители')
        ->assertSuccessful();

    Mail::assertNothingSent();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('отказва да прати при изключена игра', function () {
    User::factory()->create();
    config(['features.game' => false]);

    $this->artisan('padok:announce-game')
        ->expectsOutputToContain('FEATURE_GAME')
        ->assertFailed();

    Mail::assertNothingSent();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('писмото на потребител описва играта, има one-click unsubscribe и не кани към регистрация', function () {
    $user = User::factory()->create();

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, function (GameAnnouncementMail $mail) use ($user) {
        if (! $mail->hasTo($user->email)) {
            return false;
        }

        $html = $mail->render();
        $trackCount = count((array) config('game.tracks'));

        return $mail->trackCount === $trackCount
            && str_contains($mail->envelope()->subject, "{$trackCount} писти")
            && str_contains($html, "Клуб {$trackCount}")
            && str_contains($html, 'Сам на пистата')
            && str_contains($html, 'Състезание')
            && str_contains($html, 'преиграва на сървъра')
            && str_contains($html, 'накланяш телефона')
            && str_contains($html, 'Спри имейлите')
            && ! str_contains($html, 'Регистрирай се и запази времето си')
            && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
    });
});

it('писмото на абонат кани към регистрация и има линк за отписване', function () {
    NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, function (GameAnnouncementMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'Регистрирай се и запази времето си')
            && str_contains($html, 'Отпиши се')
            && str_contains($html, str_repeat('t', 48));
    });
});

it('CTA-то води към пистата на уикенда, когато Ф1 кара тази седмица', function () {
    $season = Season::factory()->create(['is_current' => true]);
    Race::factory()->create([
        'season_id' => $season->id,
        'circuit' => 'monza',
        'race_datetime_utc' => now()->addDays(2),
    ]);
    User::factory()->create();

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, function (GameAnnouncementMail $mail) {
        $html = $mail->render();

        return $mail->weekTrack !== null
            && $mail->weekTrack['name'] === 'Autodromo Nazionale Monza'
            && str_contains($mail->weekTrack['url'], 'track=monza')
            && str_contains($html, 'Карай на Autodromo Nazionale Monza')
            && str_contains($html, 'Тази седмица това е');
    });
});

it('не обявява „тази седмица“ писта, чиято седмица още не е започнала', function () {
    // Лятна пауза: следващото състезание е след три седмици. Резолверът го
    // връща, но писмото не бива да го нарича „тази седмица".
    $season = Season::factory()->create(['is_current' => true]);
    Race::factory()->create([
        'season_id' => $season->id,
        'circuit' => 'monza',
        'race_datetime_utc' => now()->addWeeks(3),
    ]);
    User::factory()->create();

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, fn (GameAnnouncementMail $mail) => $mail->weekTrack === null);
});

it('без състезателен уикенд CTA-то води към каталога', function () {
    User::factory()->create();

    $this->artisan('padok:announce-game')->assertSuccessful();

    Mail::assertSent(GameAnnouncementMail::class, function (GameAnnouncementMail $mail) {
        return $mail->weekTrack === null
            && str_contains($mail->render(), 'Към стартовата решетка');
    });
});
