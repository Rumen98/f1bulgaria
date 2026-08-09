<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Race;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RaceWeekendPreviewMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{label:string, when:string}>  $program  програма на уикенда в софийско време
     * @param  string|null  $stake  едноредов залог в класирането
     * @param  string|null  $deadline  краен срок за прогнози (софийско време)
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public Race $race,
        public array $program,
        public ?string $stake = null,
        public ?string $deadline = null,
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок — този уикенд: {$this->race->name_bg}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.race-preview',
        );
    }
}
