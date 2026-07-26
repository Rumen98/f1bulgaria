<?php

declare(strict_types=1);

use App\Mail\WeeklyDigestMail;
use App\Models\Race;
use App\Models\Season;

function digestHtml(?array $userStats = null): string
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);

    $race = Race::factory()->create([
        'season_id' => $season->id,
        'jolpica_id' => 'hungaroring',
        'name' => 'Hungarian Grand Prix',
        'round' => 11,
    ]);

    return (new WeeklyDigestMail(
        race: $race,
        recap: [
            ['position' => 1, 'driver' => 'Ландо Норис', 'fastest_lap' => false],
        ],
        leaderboard: [
            ['position' => 1, 'user' => (object) ['name' => 'Румен Койчев'], 'points' => 10],
        ],
        userStats: $userStats,
    ))->render();
}

it('пише името на Гран При-то на български', function () {
    $html = digestHtml();

    // Сайтът е само на български, а имейлът пращаше „Hungarian Grand Prix".
    expect($html)->toContain('Гран При на Унгария')
        ->and($html)->not->toContain('Hungarian Grand Prix');
});

it('запазва интервалите между думите в изходния HTML', function () {
    $html = strip_tags(digestHtml([
        'points' => 10, 'predictions' => 1, 'best' => 10, 'average' => 10.0,
    ]));

    // Изядените интервали в клиента („последнотосъстезаниеи") се дължат на
    // рендирането там, не на нас — този тест заключва, че HTML-ът, който
    // изпращаме, ги съдържа.
    expect($html)->toContain('последното състезание и къде си')
        ->and($html)->toContain('класирането на прогнозите')
        ->and($html)->toContain('Твоята статистика този сезон')
        ->and($html)->toContain('Класиране (топ 10)');
});

it('изписва заглавието на имейла на български', function () {
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'jolpica_id' => 'hungaroring',
        'name' => 'Hungarian Grand Prix',
    ]);

    $mail = new WeeklyDigestMail($race, [], []);

    expect($mail->envelope()->subject)->toBe('Падок — рекап: Гран При на Унгария');
});
