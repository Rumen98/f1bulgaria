<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

/**
 * padok.bg няма MX запис на главното ниво (`send.padok.bg` обслужва само
 * bounce-овете на Resend), затова отговор до подателя се губи безшумно.
 * Глобалният Reply-To в AppServiceProvider е единственото, което пази
 * отговорите на хората — регресия тук не се вижда, докато някой не се оплаче.
 */

/**
 * @return Collection<int, SentMessage>
 */
function sentMessages(): Collection
{
    return Mail::mailer()->getSymfonyTransport()->messages();
}

it('слага Reply-To на изходящите писма', function () {
    Mail::raw('Тяло на писмото', function ($message) {
        $message->to('fen@example.com')->subject('Тест');
    });

    $replyTo = sentMessages()->first()->getOriginalMessage()->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe('padokbg@gmail.com');
});

it('запазва подателя на домейна, отделно от адреса за отговор', function () {
    Mail::raw('Тяло на писмото', function ($message) {
        $message->to('fen@example.com')->subject('Тест');
    });

    $from = sentMessages()->first()->getOriginalMessage()->getFrom();

    expect($from[0]->getAddress())->toBe('novini@padok.bg');
});

it('слага Reply-To и на нотификациите извън нашите Mailable класове', function () {
    // Нулирането на парола е Breeze нотификация — не минава през app/Mail,
    // затова глобалният Reply-To е единственото, което я покрива.
    User::factory()->create()->sendPasswordResetNotification('token-123');

    $replyTo = sentMessages()->first()->getOriginalMessage()->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe('padokbg@gmail.com');
});
