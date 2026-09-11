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

/**
 * „Днес сме на живо“ — праща се веднъж на кръг, малко преди старта на
 * състезанието, и обяснява какво е live таймингът, защото повечето хора
 * не знаят, че той изобщо съществува на сайта.
 */
class LiveCoverageMail extends Mailable
{
    use HasUnsubscribeHeaders, Queueable, SerializesModels;

    /**
     * @param  string  $raceName  българското име на кръга
     * @param  string  $startAtSofia  стартът в софийско време
     * @param  string|null  $unsubscribeToken  токен за отписване (само за бюлетинни абонати)
     * @param  string|null  $userUnsubscribeUrl  signed линк за спиране на имейлите (само за потребители с акаунт)
     */
    public function __construct(
        public Race $race,
        public string $raceName,
        public string $startAtSofia,
        public ?string $unsubscribeToken = null,
        public ?string $userUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Падок е на живо днес — {$this->raceName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.live-coverage',
        );
    }
}
