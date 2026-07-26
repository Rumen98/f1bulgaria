<?php

declare(strict_types=1);

use App\Enums\F2SessionType;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Services\F2\Api\F2ApiClient;
use App\Services\F2\Api\F2ApiException;
use App\Services\F2\Api\F2ApiSync;
use Illuminate\Support\Facades\Http;

/**
 * Полезният товар в страницата на fiaformula2.com е backslash-escape-нат.
 * Наивният шаблон `"public":"…"` не хваща нищо — точно затова тестът пази
 * реалната форма.
 */
function f2KeyPage(): string
{
    return '<script>self.__next_f.push([1,"{\\"key\\":{\\"public\\":\\"TESTKEY1234567890ABC\\"}}"])</script>';
}

/**
 * @param  array<int, array<string, mixed>>  $sessions
 * @return array<string, mixed>
 */
function f2Meeting(array $sessions): array
{
    return [
        'meetings' => [[
            'meetingKey' => '1291',
            'meetingName' => 'Hungarian Grand Prix',
            'meetingLocation' => 'Budapest',
            'meetingCountryName' => 'Hungary',
            'roundText' => 'ROUND 9',
            'gmtOffset' => '+02:00',
            'isTestEvent' => false,
            'meetingSessions' => $sessions,
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function f2Session(string $code, string $state = 'completed'): array
{
    return [
        'session' => $code,
        'startTime' => '2026-07-26T12:30:00',
        'endTime' => '2026-07-26T13:30:00',
        'gmtOffset' => '+02:00',
        'state' => $state,
    ];
}

beforeEach(function () {
    Http::fake([
        'www.fiaformula2.com/*' => Http::response(f2KeyPage()),

        'api.formula1.com/*/meetings*' => Http::response(f2Meeting([
            f2Session('p'),
            f2Session('q'),
            f2Session('r'),
        ])),

        'api.formula1.com/*/practice*' => Http::response(['sessionResults' => ['results' => [
            [
                'positionNumber' => '1', 'driverFirstName' => 'Noel', 'driverLastName' => 'Leon',
                'driverReference' => 'NOELEO01', 'driverTLA' => 'LEO', 'racingNumber' => '5',
                'teamName' => 'Campos Racing', 'teamKey' => '12', 'teamColourCode' => 'FF0000',
                'classifiedTime' => '1:30.720', 'gapToLeader' => 0, 'lapsCompleted' => 14,
            ],
        ]]]),

        'api.formula1.com/*/qualifying*' => Http::response(['sessionResults' => ['results' => [
            [
                'positionNumber' => '1', 'driverFirstName' => 'Kush', 'driverLastName' => 'Maini',
                'driverReference' => 'KUSMAI01', 'driverTLA' => 'MAI', 'racingNumber' => '7',
                'teamName' => 'ART Grand Prix', 'classifiedTime' => '1:28.896', 'lapsCompleted' => 9,
                'completionStatusCode' => 'OK',
            ],
        ]]]),

        'api.formula1.com/*/race*' => Http::response(['sessionResults' => ['results' => [
            [
                'positionNumber' => '1', 'driverFirstName' => 'Noel', 'driverLastName' => 'Leon',
                'driverReference' => 'NOELEO01', 'driverTLA' => 'LEO', 'teamName' => 'Campos Racing',
                'displayTime' => '55:04.742', 'gapToLeader' => 0, 'racePoints' => 25,
                'lapsCompleted' => 37, 'completionStatusCode' => 'OK', 'version' => 'Final',
            ],
            [
                'positionNumber' => '666', 'displayPosition' => 'NC',
                'driverFirstName' => 'Nikola', 'driverLastName' => 'Tsolov',
                'driverReference' => 'NIKTSO01', 'driverTLA' => 'TSO', 'teamName' => 'Campos Racing',
                'racePoints' => 0, 'lapsCompleted' => 12,
                'completionStatusCode' => 'DNF', 'version' => 'Final',
            ],
        ]]]),

        'api.formula1.com/*/driver-standings-breakdown*' => Http::response(['standings' => [
            ['driverReference' => 'NIKTSO01', 'driverFirstName' => 'Nikola', 'driverLastName' => 'Tsolov', 'championshipPoints' => 167],
            ['driverReference' => 'NOELEO01', 'driverFirstName' => 'Noel', 'driverLastName' => 'Leon', 'championshipPoints' => 94],
        ]]),
    ]);
});

it('вади ключа от escape-натия payload на страницата', function () {
    app(F2ApiClient::class)->meetings(2026);

    Http::assertSent(fn ($request) => $request->hasHeader('apikey', 'TESTKEY1234567890ABC'));
});

it('се проваля разбираемо, когато ключът липсва в страницата', function () {
    Http::fake(['www.fiaformula2.com/*' => Http::response('<html>нищо</html>')]);

    expect(fn () => app(F2ApiClient::class)->meetings(2026))
        ->toThrow(F2ApiException::class);
});

it('създава кръг, сесии и резултати от календара', function () {
    $stats = app(F2ApiSync::class)->syncSeason(2026);

    expect($stats['rounds'])->toBe(1)
        ->and($stats['sessions'])->toBe(3);

    $race = F2Race::query()->first();

    expect($race->round)->toBe(9)
        ->and($race->meeting_key)->toBe('1291')
        ->and($race->country_name)->toBe('Hungary')
        ->and($race->location_name)->toBe('Budapest');

    expect(F2RaceSession::query()->pluck('session_type')->map(fn ($t) => $t->value)->sort()->values()->all())
        ->toBe(['feature_race', 'practice', 'qualifying']);
});

it('превръща местното време на сесията в UTC', function () {
    app(F2ApiSync::class)->syncSeason(2026);

    // 12:30 при отместване +02:00 е 10:30 UTC.
    expect(F2RaceSession::query()->first()->scheduled_at_utc->toDateTimeString())
        ->toBe('2026-07-26 10:30:00');
});

it('пази абсолютната обиколка за тренировка и квалификация', function () {
    app(F2ApiSync::class)->syncSeason(2026);

    $practice = F2RaceSession::query()->where('session_type', F2SessionType::Practice->value)->first();

    expect($practice->results()->first()->time_or_gap)->toBe('1:30.720');
});

it('превръща сентинела 666 в липсваща позиция, а не го записва', function () {
    app(F2ApiSync::class)->syncSeason(2026);

    $tsolov = F2Driver::query()->where('driver_reference', 'NIKTSO01')->first();
    $result = F2Result::query()->where('f2_driver_id', $tsolov->id)->first();

    expect($result->position)->toBeNull()
        ->and($result->status)->toBe('DNF');
});

it('взима класирането наготово от API-то, без да го смята', function () {
    app(F2ApiSync::class)->syncSeason(2026);

    $tsolov = F2Driver::query()->where('driver_reference', 'NIKTSO01')->first();

    // 167 точки включват бонусите за пол позиция, които racePoints изключва —
    // затова класирането идва от отделния endpoint.
    expect((float) $tsolov->points)->toBe(167.0)
        ->and($tsolov->position)->toBe(1);
});

it('записва pole върху квалификацията и го пренася върху главното състезание', function () {
    app(F2ApiSync::class)->syncSeason(2026);

    $feature = F2RaceSession::query()->where('session_type', F2SessionType::FeatureRace->value)->first();
    $maini = F2Driver::query()->where('driver_reference', 'KUSMAI01')->first();

    expect($feature->pole_position_driver_id)->toBe($maini->id)
        ->and($feature->pole_position_time)->toBe('1:28.896');
});

it('не дърпа наново класация на вече свалена тренировка', function () {
    app(F2ApiSync::class)->syncSeason(2026);
    $first = count(Http::recorded());

    app(F2ApiSync::class)->syncSeason(2026);
    $second = count(Http::recorded()) - $first;

    // При второто пускане трябват само календарът и класирането — резултатите
    // на приключилите сесии вече са в базата.
    expect($second)->toBeLessThan($first);
});

it('не създава дубликати при повторен синхрон', function () {
    app(F2ApiSync::class)->syncSeason(2026);
    app(F2ApiSync::class)->syncSeason(2026);

    expect(F2Race::query()->count())->toBe(1)
        ->and(F2RaceSession::query()->count())->toBe(3)
        ->and(F2Result::query()->count())->toBe(4);
});

it('не се дави, когато API-то няма данни за сезона', function () {
    Http::fake([
        'www.fiaformula2.com/*' => Http::response(f2KeyPage()),
        'api.formula1.com/*' => Http::response(['meetings' => []]),
    ]);

    $stats = app(F2ApiSync::class)->syncSeason(2019);

    expect($stats['rounds'])->toBe(0)
        ->and($stats['errors'])->not->toBeEmpty()
        ->and(F2Race::query()->count())->toBe(0);
});

it('презарежда ключа при 401 и повтаря веднъж', function () {
    Http::fake([
        'www.fiaformula2.com/*' => Http::response(f2KeyPage()),
        'api.formula1.com/*' => Http::sequence()
            ->push(['detail' => 'unauthorized'], 401)
            ->push(f2Meeting([]), 200),
    ]);

    expect(app(F2ApiClient::class)->meetings(2026))->toBe([]);
});

it('пропуска тестовите събития, за да не разместят номерата на кръговете', function () {
    Http::fake([
        'www.fiaformula2.com/*' => Http::response(f2KeyPage()),
        'api.formula1.com/*/meetings*' => Http::response(['meetings' => [[
            'meetingKey' => '1200',
            'meetingName' => 'Pre-Season Testing',
            'roundText' => 'ROUND 1',
            'isTestEvent' => true,
            'meetingSessions' => [f2Session('p')],
        ]]]),
        'api.formula1.com/*' => Http::response(['standings' => []]),
    ]);

    app(F2ApiSync::class)->syncSeason(2026);

    expect(F2Race::query()->count())->toBe(0);
});
