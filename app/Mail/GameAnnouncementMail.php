<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasUnsubscribeHeaders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Еднократното писмо за симулатора. Пуска се ръчно с padok:announce-game —
 * като писмото за новостите (FeatureAnnouncementMail), без график.
 */
class GameAnnouncementMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  int  $trackCount  брой писти в играта (config game.tracks)
     * @param  array{name: string, url: string}|null  $weekTrack  пистата на уикенда — където Ф1 кара тази седмица (null извън състезателен уикенд)
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public int $trackCount = 24,
        public ?array $weekTrack = null,
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок има симулатор: {$this->trackCount} писти от света на Формула 1, направо в браузъра 🏁",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.game-announcement',
        );
    }
}
