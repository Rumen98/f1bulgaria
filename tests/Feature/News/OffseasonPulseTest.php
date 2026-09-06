<?php

declare(strict_types=1);

use App\Mail\OffseasonPulseMail;
use App\Models\NewsletterSend;
use App\Models\NewsletterSubscriber;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->season = Season::factory()->create(['is_current' => true]);
});

it('праща пулса през пауза с новини и отброяване', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->subDays(20),
        'qualifying_datetime_utc' => now()->subDays(21),
    ]);
    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(15),
        'qualifying_datetime_utc' => now()->addDays(14),
    ]);
    TeamNewsItem::factory()->approved()->create([
        'published_at' => now()->subDays(3),
        'title_bg' => 'Лятна сага',
    ]);

    User::factory()->create(['email' => 'igrach@example.bg']);
    NewsletterSubscriber::create([
        'email' => 'abonat@example.bg',
        'unsubscribe_token' => 'tok-pulse',
        'subscribed_at' => now(),
    ]);

    $this->artisan('f1:offseason-pulse')->assertSuccessful();

    Mail::assertQueued(OffseasonPulseMail::class, fn ($mail) => $mail->hasTo('igrach@example.bg')
        && $mail->countdown !== null
        && $mail->countdown['days'] === 15
        && count($mail->news) === 1
        && $mail->news[0]['title'] === 'Лятна сага');
    Mail::assertQueued(OffseasonPulseMail::class, fn ($mail) => $mail->hasTo('abonat@example.bg')
        && $mail->unsubscribeToken === 'tok-pulse');
});

it('пропуска при състезание през последните 14 дни', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->subDays(3),
        'qualifying_datetime_utc' => now()->subDays(4),
    ]);
    TeamNewsItem::factory()->approved()->create(['published_at' => now()->subDay()]);
    User::factory()->create();

    $this->artisan('f1:offseason-pulse')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('пропуска при предстоящ кръг до 10 дни (петъчното preview поема)', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(9),
        'qualifying_datetime_utc' => now()->addDays(8),
    ]);
    TeamNewsItem::factory()->approved()->create(['published_at' => now()->subDay()]);
    User::factory()->create();

    $this->artisan('f1:offseason-pulse')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('не праща втори пулс в рамките на 21 дни', function () {
    Mail::fake();

    NewsletterSend::create(['mail_type' => NewsletterSend::TYPE_PULSE, 'sent_at' => now()->subDays(10)]);
    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(15),
        'qualifying_datetime_utc' => now()->addDays(14),
    ]);
    TeamNewsItem::factory()->approved()->create(['published_at' => now()->subDay()]);
    User::factory()->create();

    $this->artisan('f1:offseason-pulse')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('пропуска, когато няма нито новини, нито следващ кръг', function () {
    Mail::fake();

    User::factory()->create();

    $this->artisan('f1:offseason-pulse')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('опцията --force заобикаля guard-овете', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->subDays(3),
        'qualifying_datetime_utc' => now()->subDays(4),
    ]);
    TeamNewsItem::factory()->approved()->create(['published_at' => now()->subDay()]);
    User::factory()->create();

    $this->artisan('f1:offseason-pulse', ['--force' => true])->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('рендерира отброяването, новините и отписването', function () {
    $mail = new OffseasonPulseMail(
        [['title' => 'Лятна сага', 'url' => 'https://padok.bg/news/lyatna-saga']],
        countdown: ['race' => 'Гран при на Нидерландия', 'when' => 'Нд, 23.08 — 16:00 ч.', 'days' => 14],
        standings: [['position' => 1, 'driver' => 'Ландо Норис', 'points' => 250.0]],
        unsubscribeToken: 'tok-pr',
    );

    $html = $mail->render();

    expect($html)->toContain('14 дни')
        ->and($html)->toContain('Лятна сага')
        ->and($html)->toContain('Ландо Норис')
        ->and($html)->toContain('newsletter/unsubscribe/tok-pr');
});
