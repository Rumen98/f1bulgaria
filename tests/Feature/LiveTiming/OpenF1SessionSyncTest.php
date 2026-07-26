<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use App\Models\SessionResult;
use App\Services\LiveTiming\OpenF1SessionSync;
use Illuminate\Support\Facades\Http;

function openF1Session(string $name, string $key, string $start, string $end): array
{
    return [
        'session_key' => $key,
        'meeting_key' => '1250',
        'session_name' => $name,
        'date_start' => $start,
        'date_end' => $end,
        'is_cancelled' => false,
    ];
}

function fakeOpenF1(array $sessions, array $result = []): void
{
    Http::fake([
        'api.openf1.org/v1/sessions*' => Http::response($sessions),
        'api.openf1.org/v1/session_result*' => Http::response($result),
        'api.openf1.org/v1/drivers*' => Http::response([
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max VERSTAPPEN', 'team_name' => 'Red Bull'],
        ]),
    ]);
}

function seedF1Weekend(): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);

    Driver::factory()->create([
        'season_id' => $season->id,
        'first_name' => 'Max',
        'last_name' => 'Verstappen',
        'slug' => 'max-verstappen',
        'permanent_number' => 1,
    ]);

    return Race::factory()->create([
        'season_id' => $season->id,
        'jolpica_id' => 'hungaroring',
        'round' => 11,
        'race_datetime_utc' => now()->subHours(6),
    ]);
}

it('пълни race_sessions с разписанието на уикенда', function () {
    $race = seedF1Weekend();

    fakeOpenF1([
        openF1Session('Practice 1', '100', now()->subDays(2)->toIso8601String(), now()->subDays(2)->addHour()->toIso8601String()),
        openF1Session('Qualifying', '101', now()->subDay()->toIso8601String(), now()->subDay()->addHour()->toIso8601String()),
        openF1Session('Race', '102', now()->subHours(6)->toIso8601String(), now()->subHours(4)->toIso8601String()),
    ]);

    app(OpenF1SessionSync::class)->syncSeason(2026);

    // Таблицата стоеше празна от създаването си, а NextRaceResolver я чете, за
    // да разбере дали тече уикенд — оттам „предстоящо" след финала.
    expect(RaceSession::query()->where('race_id', $race->id)->pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all())
        ->toBe(['fp1', 'qualifying', 'race']);
});

it('взима класацията от тренировка и форматира времето', function () {
    seedF1Weekend();

    fakeOpenF1(
        [openF1Session('Practice 2', '200', now()->subDays(2)->toIso8601String(), now()->subDays(2)->addHour()->toIso8601String())],
        [['driver_number' => 1, 'position' => 1, 'duration' => 83.456, 'gap_to_leader' => 0, 'number_of_laps' => 22]],
    );

    app(OpenF1SessionSync::class)->syncSeason(2026);

    $result = SessionResult::query()->where('session_type', SessionType::FP2->value)->first();

    // OpenF1 дава секунди с плаваща точка, не „1:23.456".
    expect($result->best_time)->toBe('1:23.456')
        ->and($result->position)->toBe(1)
        ->and($result->source)->toBe('openf1');
});

it('взима най-добрата отсечка при спринт квалификация', function () {
    seedF1Weekend();

    fakeOpenF1(
        [openF1Session('Sprint Qualifying', '300', now()->subDays(1)->toIso8601String(), now()->subDays(1)->addHour()->toIso8601String())],
        // При квалификация duration е масив [Q1, Q2, Q3] — документацията описва
        // само скалар, затова кодът трябва да понесе и двете.
        [['driver_number' => 1, 'position' => 1, 'duration' => [84.1, 83.9, 83.456], 'gap_to_leader' => [0.2, 0.1, 0]]],
    );

    app(OpenF1SessionSync::class)->syncSeason(2026);

    expect(SessionResult::query()->where('session_type', SessionType::SprintQuali->value)->first()->best_time)
        ->toBe('1:23.456');
});

it('не дублира квалификацията — тя пристига навреме от Jolpica', function () {
    seedF1Weekend();

    fakeOpenF1(
        [openF1Session('Qualifying', '400', now()->subDay()->toIso8601String(), now()->subDay()->addHour()->toIso8601String())],
        [['driver_number' => 1, 'position' => 1, 'duration' => 83.456]],
    );

    app(OpenF1SessionSync::class)->syncSeason(2026);

    // Jolpica публикува квалификацията бързо и няма лицензно ограничение —
    // няма причина да я дърпаме и от източник с некомерсиален лиценз.
    expect(SessionResult::query()->count())->toBe(0);
});

it('взима класацията от състезанието за бързо показване', function () {
    seedF1Weekend();

    fakeOpenF1(
        [openF1Session('Race', '401', now()->subHours(6)->toIso8601String(), now()->subHours(4)->toIso8601String())],
        [['driver_number' => 1, 'position' => 1, 'duration' => 5504.742, 'gap_to_leader' => 0, 'dnf' => false]],
    );

    app(OpenF1SessionSync::class)->syncSeason(2026);

    $result = SessionResult::query()->where('session_type', SessionType::Race->value)->first();

    expect($result)->not->toBeNull()
        ->and($result->position)->toBe(1)
        // В състезание `duration` е ОБЩОТО време, не обиколка — форматирано
        // като време на обиколка би било безсмислица.
        ->and($result->best_time)->toBeNull()
        ->and($result->source)->toBe('openf1');
});

it('не взима класация от сесия, която още не е приключила', function () {
    seedF1Weekend();

    fakeOpenF1(
        [openF1Session('Practice 1', '500', now()->addHour()->toIso8601String(), now()->addHours(2)->toIso8601String())],
        [['driver_number' => 1, 'position' => 1, 'duration' => 83.456]],
    );

    app(OpenF1SessionSync::class)->syncSeason(2026);

    expect(SessionResult::query()->count())->toBe(0)
        // Разписанието обаче се записва — то е нужно ПРЕДИ сесията.
        ->and(RaceSession::query()->count())->toBe(1);
});

it('не се дави, когато OpenF1 върне празно', function () {
    seedF1Weekend();
    fakeOpenF1([]);

    $stats = app(OpenF1SessionSync::class)->syncSeason(2026);

    // Клиентът връща празно и при 401 (заключен достъп по време на сесия) —
    // затова това е „още не", а не грешка в данните.
    expect($stats['sessions'])->toBe(0)
        ->and($stats['errors'])->not->toBeEmpty();
});
