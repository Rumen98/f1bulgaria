<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Race;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Последно подсещане към потребител БЕЗ подадена прогноза за предстоящия кръг.
 * Пращаме само на хора с акаунт — бюлетинните абонати не могат да прогнозират.
 */
class PredictionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $deadline  краен срок за прогноза (софийско време)
     * @param  string|null  $hoursLeft  „след 6 часа“ — човешки прочит на оставащото време
     * @param  string  $userUnsubscribeUrl  signed линк за спиране на имейлите
     */
    public function __construct(
        public Race $race,
        public string $deadline,
        public ?string $hoursLeft,
        public string $userUnsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок — не забрави прогнозата за {$this->race->name_bg}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.prediction-reminder',
        );
    }
}
