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
     * @param  bool  $test  ръчна проверка на доставката (news:health-check --force-alert)
     */
    public function __construct(
        public array $status,
        public bool $recovered = false,
        public ?string $since = null,
        public bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match (true) {
                // Тестът НЕ бива да носи заглавието на истинска авария —
                // иначе първото писмо в кутията те учи да го игнорираш.
                $this->test => 'Падок — тест на алармата за новините',
                $this->recovered => 'Падок — новините пак се публикуват',
                default => 'Падок — ВНИМАНИЕ: новините спряха',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.news-pipeline-alert',
        );
    }
}
