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

    /**
     * @param  array<string, mixed>  $stats  обобщение за деня (виж DailyActivityReportCommand)
     */
    public function __construct(public array $stats) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок — дневен отчет · {$this->stats['date']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.daily-activity',
        );
    }
}
