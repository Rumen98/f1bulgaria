<?php

declare(strict_types=1);

use App\Services\F2\WikitextParser;

function parser(): WikitextParser
{
    return new WikitextParser;
}

function melbourneFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/f2/melbourne-2026-round.wikitext'));
}

it('извлича списъка с кръгове от сезонната страница', function () {
    $season = file_get_contents(base_path('tests/Fixtures/f2/season-2026.wikitext'));

    $rounds = parser()->parseSeasonPage($season)['rounds'];

    expect($rounds)->toHaveCount(14)
        ->and($rounds)->toContain('2026 Melbourne Formula 2 round')
        ->and($rounds)->toContain('2026 Monte Carlo Formula 2 round');
});

it('парсва кръг: номер, спринт и главно състезание', function () {
    $r = parser()->parseRoundPage(melbourneFixture());

    expect($r['round_no'])->toBe(1)
        ->and($r['sprint']['results'])->toHaveCount(22)
        ->and($r['feature']['results'])->toHaveCount(22);
});

it('парсва победителя в спринта (Dürksen, Invicta)', function () {
    $sprint = parser()->parseRoundPage(melbourneFixture())['sprint'];

    $p1 = $sprint['results']->firstWhere('position', 1);
    expect($p1['driver'])->toBe('Joshua Dürksen')
        ->and($p1['driver_flag'])->toBe('PAR')
        ->and($p1['car_number'])->toBe(2)
        ->and($p1['team'])->toBe('Invicta Racing')
        ->and($p1['points'])->toBe(10.0)
        ->and($p1['status'])->toBe('Finished');

    expect($sprint['fastest_driver'])->toBe('Martinius Stenshorne')
        ->and($sprint['fastest_time'])->toBe('1:32.045');
});

it('открива победата на Цолов в главното състезание', function () {
    $feature = parser()->parseRoundPage(melbourneFixture())['feature'];

    $tsolov = $feature['results']->firstWhere('driver', 'Nikola Tsolov');
    expect($tsolov)->not->toBeNull()
        ->and($tsolov['position'])->toBe(1)
        ->and($tsolov['car_number'])->toBe(6)
        ->and($tsolov['driver_flag'])->toBe('BUL')
        ->and($tsolov['team'])->toBe('Campos Racing')
        ->and($tsolov['points'])->toBe(25.0);
});

it('обработва DNF — позиция null, статус от Time/Retired', function () {
    $feature = parser()->parseRoundPage(melbourneFixture())['feature'];

    $dnf = $feature['results']->firstWhere('driver', 'Alex Dunne');
    expect($dnf['position'])->toBeNull()
        ->and($dnf['status'])->toBe('Collision');
});

it('парсва синтетична таблица с празни точки и Ret', function () {
    $table = <<<'WIKI'
=== Sprint race ===
{| class="wikitable"
! Pos !! No !! Driver !! Entrant !! Laps !! Time/Retired !! Grid !! Points
|-
!1
| align="center" |6
|{{Flagicon|BUL}} [[Nikola Tsolov]]
|[[Campos Racing]]
| align="center" |23
|39:00.000
| align="center" |1
| align="center" |10
|-
!Ret
| align="center" |99
|{{Flagicon|FRA}} [[Some Driver]]
|[[Some Team]]
| align="center" |5
|Engine
| align="center" |12
| align="center" |
|}
WIKI;

    $rows = parser()->parseResultsTable($table);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['driver'])->toBe('Nikola Tsolov')
        ->and($rows[0]['points'])->toBe(10.0)
        ->and($rows[1]['position'])->toBeNull()
        ->and($rows[1]['status'])->toBe('Engine')
        ->and($rows[1]['points'])->toBe(0.0);
});

it('връща празна колекция при липсваща таблица', function () {
    expect(parser()->parseResultsTable('no table here'))->toBeEmpty();
});

// --- регресии от adversarial review ---

it('извлича pole от полето Pole_driver_r2 (не Pole_driver)', function () {
    $r = parser()->parseRoundPage(melbourneFixture());

    expect($r['pole_driver'])->toBe('Dino Beganovic');
});

it('извлича датите на сесиите от инфобокса (Date_r1/Date_r2)', function () {
    $r = parser()->parseRoundPage(melbourneFixture());

    expect($r['sprint']['date'])->toBe('7 March')
        ->and($r['feature']['date'])->toBe('8 March');
});

it('връща дисплей-текста на piped wiki-връзки за пилот и отбор', function () {
    $table = <<<'WIKI'
{| class="wikitable"
! Pos !! No !! Driver !! Entrant !! Laps !! Time/Retired !! Grid !! Points
|-
!1
| align="center" |11
|{{Flagicon|GBR}} [[John Bennett (racing driver)|John Bennett]]
|[[DAMS|DAMS Lucas Oil]]
| align="center" |40
|1:00:00.000
| align="center" |1
| align="center" |25
|-
!2
| align="center" |21
|{{Flagicon|GBR}} [[Some Driver]]
|[[Trident Motorsport|Trident]]
| align="center" |40
|+2.139
| align="center" |2
| align="center" |18
|}
WIKI;

    $rows = parser()->parseResultsTable($table);

    expect($rows[0]['driver'])->toBe('John Bennett')   // не „John Bennett (racing driver)“
        ->and($rows[0]['team'])->toBe('DAMS Lucas Oil') // не „DAMS“
        ->and($rows[1]['team'])->toBe('Trident');       // не „Trident Motorsport“
});

it('не изпуска редове, чиято gap-клетка започва с + (+5 Laps, +9.519)', function () {
    $table = <<<'WIKI'
{| class="wikitable"
! Pos !! No !! Driver !! Entrant !! Laps !! Time/Retired !! Grid !! Points
|-
!1
| align="center" |6
|{{Flagicon|BUL}} [[Nikola Tsolov]]
|[[Campos Racing]]
| align="center" |40
|1:00:00.000
| align="center" |1
| align="center" |25
|-
!2
| align="center" |9
|{{Flagicon|GBR}} [[Driver Two]]
|[[Team Two]]
| align="center" |40
|+9.519
| align="center" |2
| align="center" |18
|-
!18
| align="center" |20
|{{Flagicon|FRA}} [[Driver Three]]
|[[Team Three]]
| align="center" |39
|+5 Laps
| align="center" |20
| align="center" |
|}
WIKI;

    $rows = parser()->parseResultsTable($table);

    expect($rows)->toHaveCount(3)
        ->and($rows[1]['time_or_gap'])->toBe('+9.519')
        ->and($rows[2]['position'])->toBe(18)
        ->and($rows[2]['time_or_gap'])->toBe('+5 Laps');
});

it('сумира адитивни точки 15+1 (бонус за най-бърза обиколка) → 16.0', function () {
    $table = <<<'WIKI'
{| class="wikitable"
! Pos !! No !! Driver !! Entrant !! Laps !! Time/Retired !! Grid !! Points
|-
!1
| align="center" |6
|{{Flagicon|BUL}} [[Nikola Tsolov]]
|[[Campos Racing]]
| align="center" |40
|1:00:00.000
| align="center" |1
| align="center" |15+1
|}
WIKI;

    $rows = parser()->parseResultsTable($table);

    expect($rows[0]['points'])->toBe(16.0);
});

it('чете pole и дати от едноредов (inline) инфобокс', function () {
    // Повечето реални страници ползват инфобокс на един ред (за разлика от Melbourne fixture).
    $wikitext = <<<'WIKI'
{{Infobox FIA Formula 2 race report|Country=Italy|Name=Imola|Round_No=4|Type_r1=Sprint Race|Date_r1=17 May|First_team_r1=[[DAMS|DAMS Lucas Oil]]|Type_r2=Feature Race|Date_r2=18 May|Pole_driver_country_r2=SWE|Pole_driver_r2=[[Dino Beganovic]]|Pole_team_r2=[[Hitech Grand Prix|Hitech]]|Pole_Time_r2=1:27.418}}

=== Feature race ===
{| class="wikitable"
! Pos !! No !! Driver !! Entrant !! Laps !! Time/Retired !! Grid !! Points
|-
!1
| align="center" |6
|{{Flagicon|BUL}} [[Nikola Tsolov]]
|[[Campos Racing]]
| align="center" |35
|1:00:00.000
| align="center" |1
| align="center" |25
|}
WIKI;

    $r = parser()->parseRoundPage($wikitext);

    expect($r['round_no'])->toBe(4)
        ->and($r['pole_driver'])->toBe('Dino Beganovic')
        ->and($r['sprint']['date'])->toBe('17 May')
        ->and($r['feature']['date'])->toBe('18 May');
});

it('извлича секция, дори когато е последна на страницата', function () {
    $wikitext = <<<'WIKI'
{{Infobox}}
== Report ==
Описание.

=== Feature race ===
{| class="wikitable"
! Pos !! No !! Driver !! Entrant !! Laps !! Time/Retired !! Grid !! Points
|-
!1
| align="center" |6
|{{Flagicon|BUL}} [[Nikola Tsolov]]
|[[Campos Racing]]
| align="center" |40
|1:00:00.000
| align="center" |1
| align="center" |25
|}
WIKI;

    $feature = parser()->parseRoundPage($wikitext)['feature'];

    expect($feature['results'])->toHaveCount(1)
        ->and($feature['results'][0]['driver'])->toBe('Nikola Tsolov');
});
