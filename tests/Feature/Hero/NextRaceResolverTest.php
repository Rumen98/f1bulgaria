<?php

declare(strict_types=1);

use App\Enums\HeroState;
use App\Enums\SessionType;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use App\Services\Hero\HeroRaceContext;
use App\Services\Hero\NextRaceResolver;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

function heroContext(): HeroRaceContext
{
    return app(NextRaceResolver::class)->resolve();
}

function raceWithSessions(array $sessions, string $raceAt = '2024-06-09 13:00:00'): Race
{
    $season = Season::factory()->current()->create();
    $race = Race::factory()->create(['season_id' => $season->id, 'race_datetime_utc' => $raceAt]);

    foreach ($sessions as $type => $at) {
        RaceSession::factory()->create([
            'race_id' => $race->id,
            'type' => $type,
            'scheduled_at_utc' => $at,
        ]);
    }

    return $race;
}

it('връща active по време на състезателен уикенд', function () {
    Carbon::setTestNow('2024-06-07 09:00:00');
    $race = raceWithSessions([
        SessionType::FP1->value => '2024-06-07 11:30:00',
        SessionType::Qualifying->value => '2024-06-08 14:00:00',
        SessionType::Race->value => '2024-06-09 13:00:00',
    ]);

    $ctx = heroContext();

    expect($ctx->state)->toBe(HeroState::Active)
        ->and($ctx->race->id)->toBe($race->id)
        ->and($ctx->circuitSlug)->toBe($race->jolpica_id);
});

it('връща upcoming извън уикенда', function () {
    Carbon::setTestNow('2024-06-01 09:00:00');
    $race = raceWithSessions([
        SessionType::FP1->value => '2024-06-28 11:30:00',
        SessionType::Race->value => '2024-06-30 13:00:00',
    ], raceAt: '2024-06-30 13:00:00');

    $ctx = heroContext();

    expect($ctx->state)->toBe(HeroState::Upcoming)
        ->and($ctx->race->id)->toBe($race->id)
        ->and($ctx->countdownLabel)->toBe('До състезанието');
});

it('връща off_season при липса на бъдещи състезания', function () {
    Carbon::setTestNow('2024-12-31 09:00:00');
    raceWithSessions([
        SessionType::Race->value => '2024-03-01 13:00:00',
    ], raceAt: '2024-03-01 13:00:00');

    expect(heroContext()->state)->toBe(HeroState::OffSeason);
});

it('next_session избира първата бъдеща сесия', function () {
    Carbon::setTestNow('2024-06-08 12:00:00'); // FP1 е минала, квалификацията предстои
    raceWithSessions([
        SessionType::FP1->value => '2024-06-07 11:30:00',
        SessionType::Qualifying->value => '2024-06-08 14:00:00',
        SessionType::Race->value => '2024-06-09 13:00:00',
    ]);

    $ctx = heroContext();

    expect($ctx->nextSession->type)->toBe(SessionType::Qualifying)
        ->and($ctx->sessions)->toHaveCount(2); // quali + race (FP1 е минала)
});

it('countdown_label е според следващата сесия', function () {
    Carbon::setTestNow('2024-06-07 09:00:00');
    raceWithSessions([
        SessionType::FP1->value => '2024-06-07 11:30:00',
        SessionType::Race->value => '2024-06-09 13:00:00',
    ]);

    expect(heroContext()->countdownLabel)->toBe('До FP1');
});

it('показва "До квалификацията" когато няма FP сесии и квалификацията е следваща', function () {
    Carbon::setTestNow('2024-06-07 09:00:00');
    raceWithSessions([
        // само quali + race (без FP timings — какъвто е случаят при някои источници)
        SessionType::Qualifying->value => '2024-06-08 14:00:00',
        SessionType::Race->value => '2024-06-09 13:00:00',
    ]);

    $ctx = heroContext();

    expect($ctx->countdownLabel)->toBe('До квалификацията')
        ->and($ctx->nextSession->type)->toBe(SessionType::Qualifying)
        ->and($ctx->countdownTo->toIso8601String())->toContain('2024-06-08T14:00');
});

it('показва "До състезанието" когато квалификацията е минала', function () {
    Carbon::setTestNow('2024-06-08 18:00:00'); // след квалификацията
    raceWithSessions([
        SessionType::Qualifying->value => '2024-06-08 14:00:00',
        SessionType::Race->value => '2024-06-09 13:00:00',
    ]);

    $ctx = heroContext();

    expect($ctx->countdownLabel)->toBe('До състезанието')
        ->and($ctx->nextSession->type)->toBe(SessionType::Race)
        ->and($ctx->countdownTo->toIso8601String())->toContain('2024-06-09T13:00');
});
