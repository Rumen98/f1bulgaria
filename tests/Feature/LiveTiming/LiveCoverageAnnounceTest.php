<?php

declare(strict_types=1);

use App\Mail\LiveCoverageMail;
use App\Models\NewsletterSend;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use App\Services\LiveTiming\OpenF1TokenManager;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['features.live_timing' => true]);

    // Командата отказва без OpenF1 достъп — /live би било празно.
    $this->mock(OpenF1TokenManager::class, fn ($m) => $m->shouldReceive('hasCredentials')->andReturn(true)->byDefault());
});

function raceStartingSoon(int $hoursFromNow = 2): Race
{
    $season = Season::factory()->create(['is_current' => true]);

    return Race::factory()->create([
        'season_id' => $season->id,
        'race_datetime_utc' => now()->addHours($hoursFromNow),
        'qualifying_datetime_utc' => now()->subDay(),
    ]);
}

it('праща веднъж на кръг в прозореца преди старта', function () {
    raceStartingSoon();
    $user = User::factory()->create();

    $this->artisan('f1:live-announce')->assertSuccessful();
    $this->artisan('f1:live-announce')->assertSuccessful();

    Mail::assertQueued(LiveCoverageMail::class, 1);
    Mail::assertQueued(LiveCoverageMail::class, fn ($mail) => $mail->hasTo($user->email));
});

it('мълчи извън прозореца', function () {
    raceStartingSoon(hoursFromNow: 12);
    User::factory()->create();

    $this->artisan('f1:live-announce')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('мълчи при изключен флаг', function () {
    config(['features.live_timing' => false]);
    raceStartingSoon();
    User::factory()->create();

    $this->artisan('f1:live-announce')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('мълчи без OpenF1 креденшъли', function () {
    $this->mock(OpenF1TokenManager::class, fn ($m) => $m->shouldReceive('hasCredentials')->andReturn(false));
    raceStartingSoon();
    User::factory()->create();

    $this->artisan('f1:live-announce')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('dry-run не праща и не маркира', function () {
    raceStartingSoon();
    User::factory()->create();

    $this->artisan('f1:live-announce', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
    expect(NewsletterSend::query()->count())->toBe(0);
});

it('писмото обяснява тайминга и има unsubscribe', function () {
    raceStartingSoon();
    $user = User::factory()->create();

    $this->artisan('f1:live-announce')->assertSuccessful();

    Mail::assertQueued(LiveCoverageMail::class, function (LiveCoverageMail $mail) use ($user) {
        if (! $mail->hasTo($user->email)) {
            return false;
        }

        $html = $mail->render();

        return str_contains($html, 'OpenF1')
            && str_contains($html, 'на всеки 5 секунди')
            && str_contains($html, '/live')
            && str_contains($html, 'Спри имейлите')
            && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
    });
});
