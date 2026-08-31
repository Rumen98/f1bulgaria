<?php

declare(strict_types=1);

use App\Mail\OffseasonPulseMail;

/**
 * CTA-то на пулса зависи от получателя и лесно се обръща: потребител с акаунт
 * идва без `unsubscribeToken`, абонат без акаунт — с токен. Сгреши ли се
 * условието, хората, които вече имат профил, получават покана да се
 * регистрират, а тези без профил — линк към страница, която изисква вход.
 */
function pulseCountdown(): array
{
    return ['race' => 'Гран При на Нидерландия', 'when' => '23.08, 16:00', 'days' => 11];
}

it('води потребител с акаунт към прогнозата за следващия кръг', function () {
    $html = (new OffseasonPulseMail(
        news: [],
        countdown: pulseCountdown(),
        userUnsubscribeUrl: 'https://padok.bg/newsletter/email-stop/1?signature=abc',
    ))->render();

    expect($html)->toContain(route('predictions.index'))
        ->and($html)->toContain('Гран При на Нидерландия')
        ->and($html)->not->toContain(url('/register'));
});

it('пада към календара, когато няма обявен следващ кръг', function () {
    $html = (new OffseasonPulseMail(
        news: [['title' => 'Лятна сага', 'url' => 'https://padok.bg/news/lyatna-saga']],
        countdown: null,
        userUnsubscribeUrl: 'https://padok.bg/newsletter/email-stop/1?signature=abc',
    ))->render();

    expect($html)->toContain(route('calendar'))
        ->and($html)->not->toContain(route('predictions.index'));
});

it('кани абоната без акаунт да се регистрира', function () {
    $html = (new OffseasonPulseMail(
        news: [],
        countdown: pulseCountdown(),
        unsubscribeToken: 'tok-pulse',
    ))->render();

    // Прогнозите изискват акаунт — за него единственото възможно действие е регистрация.
    expect($html)->toContain(url('/register'))
        ->and($html)->not->toContain(route('predictions.index'));
});
