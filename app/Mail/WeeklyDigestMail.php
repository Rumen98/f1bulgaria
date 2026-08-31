<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasUnsubscribeHeaders;
use App\Models\Race;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyDigestMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $recap  подиум + ключови факти
     * @param  array<int, array<string, mixed>>  $leaderboard  топ от класирането
     * @param  array<string, mixed>|null  $userStats  статистика на получателя (null за бюлетинен абонат без акаунт)
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  array<string, mixed>|null  $f2  Ф2 уикендът на Цолов (null скрива секцията)
     * @param  array<int, array{title:string, url:string}>  $news  топ новини от седмицата
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     * @param  array{name:string, url:string, deadline:string|null}|null  $nextRace  следващият кръг (null извън сезона)
     * @param  array{points:int, available:int, remaining:int}|null  $quiz  прогрес в куиза (null скрива секцията)
     */
    public function __construct(
        public Race $race,
        public array $recap,
        public array $leaderboard,
        public ?array $userStats = null,
        public ?string $unsubscribeToken = null,
        public ?array $f2 = null,
        public array $news = [],
        public ?string $userUnsubscribeUrl = null,
        public ?array $nextRace = null,
        public ?array $quiz = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок — рекап: {$this->race->name_bg}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.weekly-digest',
        );
    }
}
