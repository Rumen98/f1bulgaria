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
 * Понеделнишкото писмо: новият седмичен куиз + „Знаеш ли, че…“ с по една
 * функция на сайта. Две задачи в едно писмо нарочно — отделно
 * „запознавателно“ писмо в случаен ден би преляло пощата.
 */
class QuizWeeklyMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  int  $week  ISO номерът на седмицата
     * @param  array{title:string, text:string, url:string}|null  $spotlight  функцията на седмицата
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public int $week,
        public ?array $spotlight = null,
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок — новите въпроси на седмица {$this->week} са тук",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quiz-weekly',
        );
    }
}
