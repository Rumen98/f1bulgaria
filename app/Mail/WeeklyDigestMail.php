<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Race;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $recap  подиум + ключови факти
     * @param  array<int, array<string, mixed>>  $leaderboard  топ от класирането
     * @param  array<string, mixed>  $userStats  статистика на получателя
     */
    public function __construct(
        public Race $race,
        public array $recap,
        public array $leaderboard,
        public array $userStats,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок — рекап: {$this->race->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.weekly-digest',
        );
    }
}
