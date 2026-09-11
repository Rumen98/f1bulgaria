<?php

declare(strict_types=1);

use App\Mail\FeatureAnnouncementMail;
use App\Models\NewsletterSend;
use App\Models\NewsletterSubscriber;
use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    // Писмото сочи към двата раздела — при изключен флаг командата отказва.
    config(['features.data_recap' => true, 'features.engineering' => true]);
});

it('праща на потребителите и на бюлетинните абонати', function () {
    $user = User::factory()->create();
    $subscriber = NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, 2);
    Mail::assertSent(FeatureAnnouncementMail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertSent(FeatureAnnouncementMail::class, fn ($mail) => $mail->hasTo($subscriber->email));
});

it('прескача банати и спрели имейлите потребители', function () {
    User::factory()->create(['banned_at' => now()]);
    User::factory()->create(['email_opt_out_at' => now()]);

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('не праща втори път при повторен пуск', function () {
    User::factory()->create();

    $this->artisan('padok:announce-features')->assertSuccessful();
    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, 1);
});

it('force пуска повторно', function () {
    User::factory()->create();

    $this->artisan('padok:announce-features')->assertSuccessful();
    $this->artisan('padok:announce-features', ['--force' => true])->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, 2);
});

it('dry-run не праща и не маркира', function () {
    User::factory()->create();

    $this->artisan('padok:announce-features', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('сочи към следващия кръг с отворени прогнози', function () {
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(4),
        'race_datetime_utc' => now()->addDays(5),
    ]);
    User::factory()->create();

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, function (FeatureAnnouncementMail $mail) use ($race) {
        return $mail->nextRace !== null
            && str_contains($mail->nextRace['url'], (string) $race->id)
            && $mail->nextRace['deadline'] !== null;
    });
});

it('пада към класирането без предстоящ кръг', function () {
    Season::factory()->create(['is_current' => true]);
    User::factory()->create();

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, fn (FeatureAnnouncementMail $mail) => $mail->nextRace === null);
});

it('писмото на потребител рендерира с one-click unsubscribe и без регистрационно CTA', function () {
    $user = User::factory()->create();
    Season::factory()->create(['is_current' => true]);

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, function (FeatureAnnouncementMail $mail) use ($user) {
        if (! $mail->hasTo($user->email)) {
            return false;
        }

        $html = $mail->render();

        return str_contains($html, 'Спри имейлите')
            && str_contains($html, 'Инженерство')
            && ! str_contains($html, 'Включи се в играта')
            && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
    });
});

it('писмото на абонат кани към регистрация', function () {
    NewsletterSubscriber::create(['email' => 'abonat@example.bg', 'unsubscribe_token' => str_repeat('t', 48)]);

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, function (FeatureAnnouncementMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'Включи се в играта')
            && str_contains($html, 'Отпиши се');
    });
});

it('отказва да прати, ако раздел от писмото е изключен', function () {
    User::factory()->create();
    config(['features.engineering' => false]);

    // Разпратено писмо не се връща обратно — по-добре да откаже, отколкото да
    // прати всички към 404.
    $this->artisan('padok:announce-features')
        ->expectsOutputToContain('Инженерство')
        ->assertFailed();

    Mail::assertNothingQueued();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('споменава архива само когато наистина има архив', function () {
    User::factory()->create();
    $season = Season::factory()->create(['year' => 2026]);

    // Състезанията се създават без фабрика: тя тегли УНИКАЛЕН номер на кръг от
    // 1 до 24 и се изчерпва на трийсетия ред. Подаването на `round` не помага —
    // Laravel смята definition() преди да наложи подадените стойности.
    foreach (range(1, 31) as $round) {
        $race = Race::query()->create([
            'season_id' => $season->id,
            'round' => $round,
            'name' => "Test Grand Prix {$round}",
            'circuit' => "Circuit {$round}",
            'country' => 'Testland',
            'race_datetime_utc' => now()->subDays(400 - $round),
        ]);

        RaceDataRecap::factory()->create(['race_id' => $race->id]);
    }

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, function (FeatureAnnouncementMail $mail) {
        return $mail->analysedRaces === 31
            && str_contains($mail->render(), '31 състезания');
    });
});

it('без архив писмото не обещава история', function () {
    User::factory()->create();

    $this->artisan('padok:announce-features')->assertSuccessful();

    Mail::assertSent(FeatureAnnouncementMail::class, function (FeatureAnnouncementMail $mail) {
        return $mail->analysedRaces === 0
            && ! str_contains($mail->render(), 'състезания, така че всяка писта');
    });
});
