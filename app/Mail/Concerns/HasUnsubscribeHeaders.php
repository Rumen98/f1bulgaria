<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Headers;

/**
 * List-Unsubscribe / List-Unsubscribe-Post (RFC 8058).
 *
 * Gmail изисква one-click отписване от масовите изпращачи от февруари 2024 г.
 * Линкът във футъра на писмото НЕ се брои — филтърът чете хедъра. Без него
 * писмата попадат в спам дори при чисти SPF/DKIM/DMARC.
 *
 * Хедърът сочи същия URI като линка във футъра: доставчикът праща POST
 * (one-click), човек, който кликне, идва с GET.
 *
 * Изисква класът да декларира `$userUnsubscribeUrl` и `$unsubscribeToken`.
 */
trait HasUnsubscribeHeaders
{
    public function headers(): Headers
    {
        $url = $this->unsubscribeUrl();

        // Писмо без адресат за отписване (напр. вътрешен отчет) не бива да
        // обявява one-click — доставчикът би POST-нал в нищото.
        if ($url === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => "<{$url}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * Потребител с акаунт спира всички имейли; абонат без акаунт се отписва
     * от бюлетина. Потребителският линк е с приоритет — дедупликацията в
     * NewsletterAudience му дава личната версия на писмото.
     */
    private function unsubscribeUrl(): ?string
    {
        if ($this->userUnsubscribeUrl !== null) {
            return $this->userUnsubscribeUrl;
        }

        if ($this->unsubscribeToken !== null) {
            return route('newsletter.unsubscribe', ['token' => $this->unsubscribeToken]);
        }

        return null;
    }
}
