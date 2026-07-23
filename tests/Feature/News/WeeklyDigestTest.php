<?php

declare(strict_types=1);

use App\Mail\WeeklyDigestMail;
use App\Models\NewsletterSubscriber;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->season = Season::factory()->create(['is_current' => true]);
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'race_datetime_utc' => now()->subDay(),
    ]);
    Result::factory()->create(['race_id' => $this->race->id, 'position' => 1]);
});

it('праща дайджеста на потребители И на потвърдени бюлетинни абонати', function () {
    Mail::fake();

    User::factory()->create(['email' => 'igrach@example.bg']);
    NewsletterSubscriber::create([
        'email' => 'abonat@example.bg',
        'confirmation_token' => 'tok-abonat',
        'subscribed_at' => now(),
        'confirmed_at' => now(),
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
        'confirmation_token' => 'tok-dvojnik',
        'subscribed_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('пропуска непотвърдените и отписаните абонати', function () {
    Mail::fake();

    NewsletterSubscriber::create(['email' => 'nepotvurden@example.bg', 'confirmation_token' => 't1', 'subscribed_at' => now()]);
    NewsletterSubscriber::create(['email' => 'otpisan@example.bg', 'confirmation_token' => 't2', 'subscribed_at' => now(), 'confirmed_at' => now(), 'unsubscribed_at' => now()]);

    $this->artisan('f1:weekly-digest')->assertSuccessful();

    Mail::assertNothingQueued();
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
