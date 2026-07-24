<?php

declare(strict_types=1);

use App\Models\F2Driver;
use App\Models\F2Result;
use App\Services\F2\F2WikipediaSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Wikipedia непоследователно вгражда 2-те точки за пол в Points колоната на
 * главното състезание. Синхронът трябва да ги добавя само когато липсват —
 * иначе класирането се разминава с официалното (реален случай: F2 2026 след
 * R08 — Câmara −6, León/Maini −2 спрямо fiaformula2.com).
 */

// Втори кръг: полменът Kush Maini завършва P5 с точно 10 т. (без вграден бонус).
function sakhirRoundWikitext(): string
{
    return <<<'WIKI'
{{Infobox FIA Formula 2 race report
| Round_No                 = 2
| Year                     = 2026
| Date_r1                  = 2 May
| Date_r2                  = 3 May
| Pole_driver_country_r2   = IND
| Pole_driver_r2           = [[Kush Maini]]
| Pole_team_r2             = [[ART Grand Prix]]
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
| 5 || 8 || {{flagicon|IND}} [[Kush Maini]] || [[ART Grand Prix]] || 30 || +10.000 || 1 || '''10'''
|}
WIKI;
}

beforeEach(function () {
    Cache::flush();
    config(['services.wikipedia.rate_limit_ms' => 0]);

    $melbourne = file_get_contents(base_path('tests/Fixtures/f2/melbourne-2026-round.wikitext'));
    $season = '== Race calendar ==
[[2026 Melbourne Formula 2 round]]
[[2026 Sakhir Formula 2 round]]';

    Http::fake(function ($request) use ($season, $melbourne) {
        $url = urldecode($request->url());
        if (str_contains($url, '2026 Formula 2 Championship')) {
            return Http::response(['parse' => ['wikitext' => $season]]);
        }
        if (str_contains($url, '2026 Melbourne Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => $melbourne]]);
        }
        if (str_contains($url, '2026 Sakhir Formula 2 round')) {
            return Http::response(['parse' => ['wikitext' => sakhirRoundWikitext()]]);
        }

        return Http::response(['error' => ['code' => 'missingtitle']]);
    });

    app(F2WikipediaSync::class)->syncYear(2026);
});

it('добавя 2 т. за пол, когато Wikipedia не ги е вградила в резултата', function () {
    $maini = F2Driver::query()->where('slug', 'kush-maini')->first();
    $raced = (float) F2Result::query()->where('f2_driver_id', $maini->id)->sum('points');

    // P5 = 10 т. в таблицата + 2 т. пол от квалификацията.
    expect($raced)->toBe(10.0)
        ->and((float) $maini->points)->toBe(12.0);
});

it('не дублира пол бонуса, когато вече е вграден (Беганович, Мелбърн)', function () {
    // Полменът в Мелбърн отпада (DNF), но Wikipedia му е записала 2 т. в
    // Points колоната → бонусът е вграден и НЕ трябва да се добавя пак.
    $beganovic = F2Driver::query()->where('slug', 'dino-beganovic')->first();
    $raced = (float) F2Result::query()->where('f2_driver_id', $beganovic->id)->sum('points');

    expect((float) $beganovic->points)->toBe($raced);
});

it('не пипа точките на пилоти без пол', function () {
    $tsolov = F2Driver::query()->where('slug', 'nikola-tsolov')->first();
    $raced = (float) F2Result::query()->where('f2_driver_id', $tsolov->id)->sum('points');

    expect((float) $tsolov->points)->toBe($raced);
});
