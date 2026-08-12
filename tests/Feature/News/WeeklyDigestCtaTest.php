<?php

declare(strict_types=1);

use App\Mail\WeeklyDigestMail;
use App\Models\Race;

/**
 * Дайджестът излиза неделя 20:00 — върхът на вниманието за седмицата. Какво
 * пита в този момент решава дали човекът ще играе следващия кръг, затова и
 * блокът с личната статистика, и бутонът се сменят според получателя.
 */
function digestMail(?array $userStats, ?array $nextRace = null, ?string $token = null): string
{
    // name_bg е accessor върху `name` — не се задава от фабриката.
    return (new WeeklyDigestMail(
        Race::factory()->create(),
        recap: [],
        leaderboard: [],
        userStats: $userStats,
        unsubscribeToken: $token,
        nextRace: $nextRace,
    ))->render();
}

function nextRaceData(): array
{
    return [
        'name' => 'Гран При на Нидерландия',
        'url' => 'https://padok.bg/races/gp-na-nidrlandiya',
        'deadline' => 'Сб, 22.08 — 15:55 ч.',
    ];
}

it('кани към прогноза вместо да показва таблица от нули', function () {
    $html = digestMail(
        ['points' => 0, 'predictions' => 0, 'best' => 0, 'average' => 0.0],
        nextRaceData(),
    );

    expect($html)->toContain('Още не си играл този сезон')
        ->and($html)->not->toContain('Твоята статистика този сезон')
        ->and($html)->toContain('Дай прогноза за Гран При на Нидерландия');
});

it('показва статистиката на човек, който вече е прогнозирал', function () {
    $html = digestMail(
        ['points' => 42, 'predictions' => 3, 'best' => 20, 'average' => 14.0, 'rank' => 2, 'players' => 9],
        nextRaceData(),
    );

    expect($html)->toContain('Твоята статистика този сезон')
        ->and($html)->toContain('42')
        ->and($html)->not->toContain('Още не си играл този сезон');
});

it('обявява следващия кръг и срока за заключване', function () {
    $html = digestMail(['points' => 0, 'predictions' => 0, 'best' => 0, 'average' => 0.0], nextRaceData());

    expect($html)->toContain('Следващ кръг: Гран При на Нидерландия')
        ->and($html)->toContain('Сб, 22.08 — 15:55 ч.');
});

it('пада към класирането, когато сезонът е свършил', function () {
    $html = digestMail(['points' => 42, 'predictions' => 3, 'best' => 20, 'average' => 14.0], nextRace: null);

    expect($html)->toContain(url('/leaderboard'))
        ->and($html)->not->toContain('Следващ кръг');
});

it('води абоната без акаунт към регистрация, а не към прогноза', function () {
    $html = digestMail(null, nextRaceData(), token: 'tok-digest');

    expect($html)->toContain(url('/register'))
        ->and($html)->not->toContain('Дай прогноза за');
});

it('скрива Телеграм блока, докато няма зададен линк', function () {
    config(['services.telegram.community_url' => null]);

    expect(digestMail(null, nextRaceData(), token: 'tok'))->not->toContain('Телеграм');
});

it('кани към Телеграм, когато линкът е зададен', function () {
    config(['services.telegram.community_url' => 'https://t.me/padokbg']);

    $html = digestMail(null, nextRaceData(), token: 'tok');

    expect($html)->toContain('https://t.me/padokbg')
        ->and($html)->toContain('Коментираме уикенда');
});
