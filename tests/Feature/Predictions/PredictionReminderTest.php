<?php

declare(strict_types=1);

use App\Mail\PredictionReminderMail;
use App\Models\NewsletterSend;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

/** Кръг, чието заключване е в прозореца (квалификация след 6 часа). */
function reminderRace(): Race
{
    $season = Season::factory()->create(['is_current' => true]);

    return Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addHours(6),
        'race_datetime_utc' => now()->addHours(30),
    ]);
}

it('пише само на потребители без прогноза', function () {
    $race = reminderRace();
    $without = User::factory()->create();
    $with = User::factory()->create();

    Prediction::factory()->create(['user_id' => $with->id, 'race_id' => $race->id]);

    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    Mail::assertSent(PredictionReminderMail::class, 1);
    Mail::assertSent(PredictionReminderMail::class, fn ($mail) => $mail->hasTo($without->email));
});

it('прескача потребители, спрели имейлите', function () {
    reminderRace();
    User::factory()->create(['email_opt_out_at' => now()]);

    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('прескача банати потребители', function () {
    reminderRace();
    User::factory()->create(['banned_at' => now()]);

    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('не праща извън прозореца преди заключването', function () {
    $season = Season::factory()->create(['is_current' => true]);
    Race::factory()->create([
        'season_id' => $season->id,
        'qualifying_datetime_utc' => now()->addDays(5),
        'race_datetime_utc' => now()->addDays(6),
    ]);
    User::factory()->create();

    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('не праща втори път за същия кръг', function () {
    reminderRace();
    User::factory()->create();

    $this->artisan('f1:prediction-reminder')->assertSuccessful();
    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    Mail::assertSent(PredictionReminderMail::class, 1);
});

it('маркира изпращането в журнала', function () {
    $race = reminderRace();
    User::factory()->create();

    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    expect(NewsletterSend::query()
        ->where('mail_type', NewsletterSend::TYPE_PREDICTION_REMINDER)
        ->where('race_id', $race->id)
        ->exists())->toBeTrue();
});

it('не праща нищо, когато всички имат прогноза', function () {
    $race = reminderRace();
    $user = User::factory()->create();
    Prediction::factory()->create(['user_id' => $user->id, 'race_id' => $race->id]);

    $this->artisan('f1:prediction-reminder')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('dry-run не праща и не маркира', function () {
    $race = reminderRace();
    User::factory()->create();

    $this->artisan('f1:prediction-reminder', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
    expect(NewsletterSend::query()->where('race_id', $race->id)->exists())->toBeFalse();
});
