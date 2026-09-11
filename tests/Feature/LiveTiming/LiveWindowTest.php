<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use App\Services\LiveTiming\LiveWindowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.live_timing' => true]);
    Cache::flush(); // прозорецът се кешира за минута
});

function sessionAt(Carbon|Illuminate\Support\Carbon $at, string $type = 'race'): RaceSession
{
    $season = Season::factory()->create(['is_current' => true]);
    $race = Race::factory()->create(['season_id' => $season->id]);

    return RaceSession::create([
        'race_id' => $race->id,
        'type' => $type,
        'scheduled_at_utc' => $at,
    ]);
}

it('брои сесия за на живо по време на прозореца ѝ', function () {
    sessionAt(now()->subMinutes(30));

    expect(app(LiveWindowService::class)->isLiveNow())->toBeTrue();
});

it('брои и малкия буфер преди старта', function () {
    sessionAt(now()->addMinutes(5), 'qualifying');

    expect(app(LiveWindowService::class)->isLiveNow())->toBeTrue();
});

it('не брои сесия далеч в бъдещето или отдавна минала', function () {
    sessionAt(now()->addHours(6));

    expect(app(LiveWindowService::class)->isLiveNow())->toBeFalse();

    Cache::flush();
    RaceSession::query()->update(['scheduled_at_utc' => now()->subHours(6)]);

    expect(app(LiveWindowService::class)->isLiveNow())->toBeFalse();
});

it('тренировката изтича след 90 минути, състезанието трае 3 часа', function () {
    sessionAt(now()->subMinutes(120), 'fp1');
    expect(app(LiveWindowService::class)->isLiveNow())->toBeFalse();

    Cache::flush();
    RaceSession::query()->update(['type' => 'race']);
    expect(app(LiveWindowService::class)->isLiveNow())->toBeTrue();
});

it('подава liveNow=true към навигацията само по време на сесия', function () {
    sessionAt(now()->subMinutes(15));

    $this->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->where('liveNow', true));
});

it('liveNow е false извън сесия и при изключен флаг', function () {
    $this->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->where('liveNow', false));

    Cache::flush();
    sessionAt(now()->subMinutes(15));
    config(['features.live_timing' => false]);

    $this->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->where('liveNow', false));
});
