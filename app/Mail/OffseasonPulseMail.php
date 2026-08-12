<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasUnsubscribeHeaders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OffseasonPulseMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  array<int, array{title:string, url:string}>  $news  топ новини от периода
     * @param  array{race:string, when:string, days:int}|null  $countdown  следващият кръг
     * @param  array<int, array{position:int, driver:string, points:float}>  $standings  върхът на класирането
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public array $news,
        public ?array $countdown = null,
        public array $standings = [],
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Падок — какво ново през паузата',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.offseason-pulse',
        );
    }
}
