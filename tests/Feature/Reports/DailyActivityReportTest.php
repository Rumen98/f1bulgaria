<?php

declare(strict_types=1);

use App\Mail\DailyActivityMail;
use App\Models\AuthEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Замразяваме времето по обед софийско, за да няма гранични случаи с полунощ.
    $this->travelTo(CarbonImmutable::parse('2026-07-24 12:00:00', 'Europe/Sofia'));
});

it('праща дневния отчет на админа с верните бройки', function () {
    config(['app.admin_report_email' => 'admin@padok.bg']);
    Mail::fake();

    $user = User::factory()->create();

    AuthEvent::create(['type' => AuthEvent::TYPE_REGISTERED, 'email' => 'new@padok.bg']);
    AuthEvent::create(['type' => AuthEvent::TYPE_LOGIN, 'user_id' => $user->id, 'email' => $user->email]);
    AuthEvent::create(['type' => AuthEvent::TYPE_LOGIN, 'user_id' => $user->id, 'email' => $user->email]);
    AuthEvent::create(['type' => AuthEvent::TYPE_FAILED, 'email' => 'x@padok.bg']);

    $this->artisan('report:daily-activity')->assertSuccessful();

    Mail::assertSent(DailyActivityMail::class, function (DailyActivityMail $mail) {
        return $mail->hasTo('admin@padok.bg')
            && $mail->stats['registrations'] === 1
            && $mail->stats['logins'] === 2
            && $mail->stats['unique_logins'] === 1
            && $mail->stats['failed'] === 1;
    });
});

it('праща отчета на ADMIN_REPORT_EMAIL, без да пипа логин имейла на админа', function () {
    // Реален случай: логин акаунтът остава padok@..., отчетът отива на padokbg@...
    config(['app.admin_email' => 'login@padok.bg', 'app.admin_report_email' => 'reports@padok.bg']);
    Mail::fake();

    $this->artisan('report:daily-activity')->assertSuccessful();

    Mail::assertSent(DailyActivityMail::class, fn (DailyActivityMail $mail) => $mail->hasTo('reports@padok.bg'));
});

it('гърми при липсващ получател', function () {
    config(['app.admin_report_email' => '']);

    $this->artisan('report:daily-activity')->assertFailed();
});
