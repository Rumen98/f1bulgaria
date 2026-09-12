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
 * Кратко лично писмо до пробвалите играта: обновихме я, изиграй едно
 * състезание и кажи какво мислиш. Пуска се ръчно с padok:announce-game-update.
 */
class GameUpdateMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  string  $name  името на играча (обръщение)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите
     * @param  string|null  $unsubscribeToken  не се ползва (само регистрирани), но HasUnsubscribeHeaders го чете
     */
    public function __construct(
        public string $name,
        public ?string $userUnsubscribeUrl = null,
        public ?string $unsubscribeToken = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Обновихме симулатора — изиграй едно състезание и ми кажи какво мислиш 🏁',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.game-update',
        );
    }
}
