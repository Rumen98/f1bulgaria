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
 * Еднократно писмо при по-големи новости по сайта. Пуска се ръчно с padok:announce-features — няма
 * график, защото няма редовност: праща се, когато има какво да се каже.
 */
class FeatureAnnouncementMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  array{name:string, url:string, deadline:?string}|null  $nextRace  следващият кръг с отворени прогнози (null скрива CTA-то към него)
     * @param  int  $analysedRaces  брой анализирани състезания — изречението за архива се показва само ако наистина има архив
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public ?array $nextRace = null,
        public int $analysedRaces = 0,
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Падок — данните зад всяко състезание и нова рубрика за техниката',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.feature-announcement',
        );
    }
}
