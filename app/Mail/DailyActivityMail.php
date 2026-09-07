<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyActivityMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Повече етикети в темата не се събират в списъка на пощата — остатъкът се брои. */
    private const SUBJECT_LABELS = 2;

    /**
     * @param  array<string, mixed>  $stats  обобщение за деня (виж DailyActivityReportCommand)
     */
    public function __construct(public array $stats) {}

    /**
     * Темата носи проблемите.
     *
     * ЗАЩО: отчет с еднаква тема всеки ден се отваря все по-рядко, а точно
     * денят с авария е денят, в който писмото няма да бъде отворено. Темата е
     * единственото, което се вижда без отваряне — затова тя, а не тялото,
     * казва дали има какво да се оправя.
     */
    public function envelope(): Envelope
    {
        $problems = $this->stats['health']['problems'] ?? [];

        if ($problems === []) {
            return new Envelope(
                subject: "Падок — дневен отчет · {$this->stats['date']}",
            );
        }

        $labels = array_column($problems, 'label');
        $head = implode(', ', array_slice($labels, 0, self::SUBJECT_LABELS));

        if (count($labels) > self::SUBJECT_LABELS) {
            $head .= ' +'.(count($labels) - self::SUBJECT_LABELS);
        }

        return new Envelope(
            subject: "Падок — ⚠️ {$head} · {$this->stats['date']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.daily-activity',
        );
    }
}
