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
 * Еднократно писмо при по-големи новости по сайта (значки, куиз точки,
 * предстоящи награди). Пуска се ръчно с padok:announce-features — няма
 * график, защото няма редовност: праща се, когато има какво да се каже.
 */
class FeatureAnnouncementMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  array{name:string, url:string, deadline:?string}|null  $nextRace  следващият кръг с отворени прогнози (null скрива CTA-то към него)
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public ?array $nextRace = null,
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Падок — значки, точки от куиза и какво идва',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.feature-announcement',
        );
    }
}
