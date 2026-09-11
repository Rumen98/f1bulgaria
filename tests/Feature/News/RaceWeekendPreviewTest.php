<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Mail\RaceWeekendPreviewMail;
use App\Models\NewsletterSubscriber;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->season = Season::factory()->create(['is_current' => true]);
});

it('праща preview на потребители и абонати при кръг до 7 дни', function () {
    Mail::fake();

    $race = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(2),
        'qualifying_datetime_utc' => now()->addDay(),
    ]);
    RaceSession::factory()->create([
        'race_id' => $race->id,
        'type' => SessionType::Qualifying,
        'scheduled_at_utc' => now()->addDay(),
    ]);
    RaceSession::factory()->create([
        'race_id' => $race->id,
        'type' => SessionType::Race,
        'scheduled_at_utc' => now()->addDays(2),
    ]);

    User::factory()->create(['email' => 'igrach@example.bg']);
    NewsletterSubscriber::create([
        'email' => 'abonat@example.bg',
        'unsubscribe_token' => 'tok-prev',
        'subscribed_at' => now(),
    ]);

    $this->artisan('f1:race-preview')->assertSuccessful();

    Mail::assertSent(RaceWeekendPreviewMail::class, fn ($mail) => $mail->hasTo('igrach@example.bg')
        && $mail->unsubscribeToken === null
        && count($mail->program) === 2
        && $mail->program[0]['label'] === 'Квалификация'
        && $mail->deadline !== null);
    Mail::assertSent(RaceWeekendPreviewMail::class, fn ($mail) => $mail->hasTo('abonat@example.bg')
        && $mail->unsubscribeToken === 'tok-prev');
});

it('не праща нищо в седмица без предстоящ кръг', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(20),
        'qualifying_datetime_utc' => now()->addDays(19),
    ]);
    User::factory()->create();

    $this->artisan('f1:race-preview')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('пада към часовете на състезанието без синхронизирани сесии', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(2),
        'qualifying_datetime_utc' => now()->addDay(),
    ]);
    User::factory()->create();

    $this->artisan('f1:race-preview')->assertSuccessful();

    Mail::assertSent(RaceWeekendPreviewMail::class, fn ($mail) => count($mail->program) === 2
        && $mail->program[0]['label'] === 'Квалификация'
        && $mail->program[1]['label'] === 'Състезание');
});

it('пропуска вече минали сесии в програмата', function () {
    Mail::fake();

    $race = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(2),
        'qualifying_datetime_utc' => now()->addDay(),
    ]);
    RaceSession::factory()->create([
        'race_id' => $race->id,
        'type' => SessionType::FP1,
        'scheduled_at_utc' => now()->subHours(3),
    ]);
    RaceSession::factory()->create([
        'race_id' => $race->id,
        'type' => SessionType::Race,
        'scheduled_at_utc' => now()->addDays(2),
    ]);
    User::factory()->create();

    $this->artisan('f1:race-preview')->assertSuccessful();

    Mail::assertSent(RaceWeekendPreviewMail::class, fn ($mail) => count($mail->program) === 1
        && $mail->program[0]['label'] === 'Състезание');
});

it('fallback програмата включва спринта при спринтов уикенд', function () {
    Mail::fake();

    Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(2),
        'qualifying_datetime_utc' => now()->addDay(),
        'sprint_datetime_utc' => now()->addDay()->addHours(6),
        'has_sprint' => true,
    ]);
    User::factory()->create();

    $this->artisan('f1:race-preview')->assertSuccessful();

    Mail::assertSent(RaceWeekendPreviewMail::class, fn ($mail) => count($mail->program) === 3
        && $mail->program[1]['label'] === 'Спринт');
});

it('опцията --race заобикаля 7-дневния прозорец', function () {
    Mail::fake();

    $race = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->addDays(20),
        'qualifying_datetime_utc' => now()->addDays(19),
    ]);
    User::factory()->create();

    $this->artisan('f1:race-preview', ['--race' => $race->id])->assertSuccessful();

    Mail::assertSentCount(1);
});

it('рендерира програмата и CTA за прогноза (потребителска версия)', function () {
    $race = Race::factory()->create(['season_id' => $this->season->id]);

    $mail = new RaceWeekendPreviewMail(
        $race,
        [['label' => 'Състезание', 'when' => 'Нд, 23.08 — 16:00 ч.']],
        stake: 'Ландо Норис води в шампионата с 18 т. аванс пред Макс Верстапен.',
        deadline: 'Сб, 22.08 — 17:55 ч.',
        userUnsubscribeUrl: 'https://padok.bg/newsletter/email-stop/1?signature=abc',
    );

    $html = $mail->render();

    expect($html)->toContain('Програма')
        ->and($html)->toContain('16:00')
        ->and($html)->toContain('Подай прогноза')
        ->and($html)->toContain('17:55')
        ->and($html)->toContain('Спри имейлите')
        ->and($html)->not->toContain('Отпиши се');
});

it('абонатската версия има отписване и покана за регистрация', function () {
    $race = Race::factory()->create(['season_id' => $this->season->id]);

    $mail = new RaceWeekendPreviewMail(
        $race,
        [['label' => 'Състезание', 'when' => 'Нд, 23.08 — 16:00 ч.']],
        unsubscribeToken: 'tok-r',
    );

    $html = $mail->render();

    expect($html)->toContain('newsletter/unsubscribe/tok-r')
        ->and($html)->toContain('Включи се в prediction league');
});
