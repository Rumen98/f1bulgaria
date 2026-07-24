<?php

declare(strict_types=1);

use App\Models\F2Race;
use App\Models\F2Result;
use App\Services\F2\F2WikipediaSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Регресия: при промяна в реда на кръговете (напр. поправена номерация от
 * сезонната страница) updateOrCreate по (season, round) пренасочва кръга към
 * друго събитие, но старите сесии/резултати оставаха закачени и точките се
 * брояха двойно (реален случай: Спа 2026 се дублира под „Будапеща" R9).
 */

function relocationRoundWikitext(): string
{
    return <<<'WIKI'
{{Infobox FIA Formula 2 race report
| Round_No                 = 1
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
| 1 || 10 || {{flagicon|ITA}} [[Gabriele Minì]] || [[MP Motorsport]] || 30 || 45:00.000 || 2 || '''25'''
|-
| 2 || 8 || {{flagicon|IND}} [[Kush Maini]] || [[ART Grand Prix]] || 30 || +10.000 || 1 || '''18'''
|}
WIKI;
}

it('пренареден кръг не оставя стари резултати (без двойно броене)', function () {
    Cache::flush();
    config(['services.wikipedia.rate_limit_ms' => 0]);

    $melbourne = file_get_contents(base_path('tests/Fixtures/f2/melbourne-2026-round.wikitext'));
    $state = new stdClass;
    $state->season = "== Race calendar ==\n[[2026 Sakhir Formula 2 round]]\n[[2026 Melbourne Formula 2 round]]";

    Http::fake(function ($request) use ($state, $melbourne) {
        $url = urldecode($request->url());
        if (str_contains($url, '2026 Formula 2 Championship')) {
            return Http::response(['parse' => ['wikitext' => $state->season]]);
        }
        if (str_contains($url, '2026 Melbourne Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => $melbourne]]);
        }
        if (str_contains($url, '2026 Sakhir Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => relocationRoundWikitext()]]);
        }

        return Http::response(['error' => ['code' => 'missingtitle']]);
    });

    // Първи синхрон: R1 = Sakhir, R2 = Melbourne.
    app(F2WikipediaSync::class)->syncYear(2026);
    $resultsAfterFirst = F2Result::count();

    // Коригиран ред: R1 = Melbourne, R2 = Sakhir → нов синхрон.
    $state->season = "== Race calendar ==\n[[2026 Melbourne Formula 2 round]]\n[[2026 Sakhir Formula 2 round]]";
    Cache::flush();
    app(F2WikipediaSync::class)->syncYear(2026);

    $r1 = F2Race::query()->where('round', 1)->first();
    $r2 = F2Race::query()->where('round', 2)->first();

    expect($r1->location_name)->toBe('Melbourne')
        ->and($r2->location_name)->toBe('Sakhir')
        // Sakhir има само главно състезание (2 реда) — старите Melbourne
        // сесии под R2 трябва да са изтрити, не насложени.
        ->and($r2->sessions()->count())->toBe(1)
        ->and(F2Result::count())->toBe($resultsAfterFirst);
});
