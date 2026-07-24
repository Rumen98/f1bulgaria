<?php

declare(strict_types=1);

use App\Models\F2Driver;
use App\Services\F2\F2WikipediaSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * При равни точки FIA нарежда по countback: повече победи, после повече втори
 * места и т.н. (реален случай: Maini и Beganovic по 63 т. след R08 2026 —
 * официално Maini е 6-ти, не по ред на създаване в базата).
 */

function countbackRound1(): string
{
    return <<<'WIKI'
{{Infobox FIA Formula 2 race report
| Round_No                 = 1
| Year                     = 2026
| Date_r1                  = 7 March
| Date_r2                  = 8 March
}}

== Classification ==

=== Sprint race ===
{| class="wikitable"
! Pos. !! No. !! Driver !! Team !! Laps !! Time/Retired !! Grid !! Points
|-
| 1 || 3 || {{flagicon|FRA}} [[Cesar Trois]] || [[Team X]] || 23 || 30:00.000 || 1 || '''10'''
|-
| 2 || 1 || {{flagicon|GBR}} [[Alpha Ace]] || [[Team A]] || 23 || +5.000 || 2 || '''8'''
|-
| 6 || 2 || {{flagicon|GER}} [[Beta Racer]] || [[Team B]] || 23 || +9.000 || 6 || '''1'''
|}

=== Feature race ===
{| class="wikitable"
! Pos. !! No. !! Driver !! Team !! Laps !! Time/Retired !! Grid !! Points
|-
| 1 || 3 || {{flagicon|FRA}} [[Cesar Trois]] || [[Team X]] || 33 || 45:00.000 || 1 || '''25'''
|-
| 2 || 1 || {{flagicon|GBR}} [[Alpha Ace]] || [[Team A]] || 33 || +3.000 || 2 || '''18'''
|-
| 11 || 2 || {{flagicon|GER}} [[Beta Racer]] || [[Team B]] || 33 || +30.000 || 5 || 0
|}
WIKI;
}

function countbackRound2(): string
{
    return <<<'WIKI'
{{Infobox FIA Formula 2 race report
| Round_No                 = 2
| Year                     = 2026
| Date_r1                  = 2 May
| Date_r2                  = 3 May
}}

== Classification ==

=== Sprint race ===
Няма данни.

=== Feature race ===
{| class="wikitable"
! Pos. !! No. !! Driver !! Team !! Laps !! Time/Retired !! Grid !! Points
|-
| 1 || 2 || {{flagicon|GER}} [[Beta Racer]] || [[Team B]] || 30 || 44:00.000 || 3 || '''25'''
|-
| 2 || 3 || {{flagicon|FRA}} [[Cesar Trois]] || [[Team X]] || 30 || +2.000 || 1 || '''18'''
|-
| 11 || 1 || {{flagicon|GBR}} [[Alpha Ace]] || [[Team A]] || 30 || +40.000 || 2 || 0
|}
WIKI;
}

it('при равни точки нарежда по countback (победа > две втори места)', function () {
    Cache::flush();
    config(['services.wikipedia.rate_limit_ms' => 0]);

    $season = "== Race calendar ==\n[[2026 Melbourne Formula 2 round]]\n[[2026 Sakhir Formula 2 round]]";

    Http::fake(function ($request) use ($season) {
        $url = urldecode($request->url());
        if (str_contains($url, '2026 Formula 2 Championship')) {
            return Http::response(['parse' => ['wikitext' => $season]]);
        }
        if (str_contains($url, '2026 Melbourne Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => countbackRound1()]]);
        }
        if (str_contains($url, '2026 Sakhir Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => countbackRound2()]]);
        }

        return Http::response(['error' => ['code' => 'missingtitle']]);
    });

    app(F2WikipediaSync::class)->syncYear(2026);

    // Alpha (2-ри + 2-ри спринт = 26 т.) и Beta (победа + 1 т. = 26 т.) са
    // равни, но победата на Beta го нарежда напред — както в официалното.
    $alpha = F2Driver::query()->where('slug', 'alpha-ace')->first();
    $beta = F2Driver::query()->where('slug', 'beta-racer')->first();
    $cesar = F2Driver::query()->where('slug', 'cesar-trois')->first();

    expect((float) $alpha->points)->toBe(26.0)
        ->and((float) $beta->points)->toBe(26.0)
        ->and($cesar->position)->toBe(1)
        ->and($beta->position)->toBe(2)
        ->and($alpha->position)->toBe(3);
});
