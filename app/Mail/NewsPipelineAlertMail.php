<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Оперативна аларма към админа — НЕ е бюлетин.
 *
 * Умишлено не имплементира ShouldQueue: мъртъв queue worker е една от
 * авариите, за които това писмо трябва да се обади.
 */
class NewsPipelineAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $status  резултатът от NewsPipelineHealth::check()
     * @param  bool  $recovered  true = pipeline-ът пак работи
     * @param  string|null  $since  кога е започнал инцидентът (само при възстановяване)
     */
    public function __construct(
        public array $status,
        public bool $recovered = false,
        public ?string $since = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->recovered
                ? 'Падок — новините пак се публикуват'
                : 'Падок — ВНИМАНИЕ: новините спряха',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.news-pipeline-alert',
        );
    }
}
