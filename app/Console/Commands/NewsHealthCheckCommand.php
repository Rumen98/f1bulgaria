<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\NewsPipelineAlertMail;
use App\Models\NewsletterSend;
use App\Services\News\NewsPipelineHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Пази от тих отказ на news pipeline-а — праща имейл при спиране и при
 * възстановяване.
 *
 * Изпращането е СИНХРОННО (Mailable-ите тук не са ShouldQueue). Нарочно:
 * мъртъв queue worker е една от авариите, за които алармата трябва да се
 * обади, а аларма през опашката би умряла заедно с проблема.
 *
 * Състоянието на инцидента живее в `newsletter_sends`, а не в кеша:
 * `deploy.sh` вика `optimize:clear`, което включва `cache:clear` и при
 * cache store `database` трие маркера. Деплой по време на авария иначе
 * алармира повторно и губи завинаги писмото за възстановяване.
 */
class NewsHealthCheckCommand extends Command
{
    protected $signature = 'news:health-check {--force-alert : Праща тестово писмо веднага, независимо от състоянието (проверка на доставката)}';

    protected $description = 'Проверява дали news pipeline-ът още публикува и алармира по имейл при спиране.';

    public function handle(NewsPipelineHealth $health): int
    {
        $status = $health->check();

        // Ръчна проверка на доставката — праща писмо ВЕДНАГА, независимо от
        // състоянието. Без това единственият начин да разбереш дали алармата
        // изобщо стига (и дали не влиза в спам) е да изчакаш истинска авария,
        // тоест научаваш го точно когато не бива.
        if ($this->option('force-alert')) {
            $this->send($status, recovered: false, since: null, test: true);

            return self::SUCCESS;
        }

        $incidentStartedAt = $this->openIncidentStartedAt();

        if ($status['healthy']) {
            $this->info('Pipeline-ът е здрав. Чакащи: '.$status['pending']
                .', най-стара необработена: '.($status['oldest_pending_at'] ?? 'няма'));

            // Възстановяване след инцидент — казваме го веднъж и затваряме го.
            if ($incidentStartedAt !== null) {
                NewsletterSend::create([
                    'mail_type' => NewsletterSend::TYPE_PIPELINE_RECOVERED,
                    'sent_at' => now(),
                ]);

                $this->send($status, recovered: true, since: $incidentStartedAt->toDateTimeString());
            }

            return self::SUCCESS;
        }

        $this->error('АВАРИЯ: '.$status['reason']);
        Log::error('News pipeline health check се провали: '.$status['reason'], $status);

        // Един имейл на инцидент, не на всеки час — иначе алармата се
        // превръща в шум и спира да се чете.
        if ($incidentStartedAt !== null) {
            $this->line('Вече е алармирано на '.$incidentStartedAt->toDateTimeString().' — пропускам писмото.');

            return self::SUCCESS;
        }

        NewsletterSend::create([
            'mail_type' => NewsletterSend::TYPE_PIPELINE_ALERT,
            'sent_at' => now(),
        ]);

        $this->send($status, recovered: false, since: null);

        return self::SUCCESS;
    }

    /**
     * Кога е започнал текущият незатворен инцидент, или null ако няма такъв.
     *
     * Инцидентът е отворен, когато последната аларма е по-нова от последното
     * известие за възстановяване. Журналът се пази цял — историята на
     * авариите е полезна сама по себе си.
     */
    private function openIncidentStartedAt(): ?Carbon
    {
        $alert = NewsletterSend::query()
            ->where('mail_type', NewsletterSend::TYPE_PIPELINE_ALERT)
            ->latest('sent_at')
            ->first();

        if ($alert === null) {
            return null;
        }

        $recovered = NewsletterSend::query()
            ->where('mail_type', NewsletterSend::TYPE_PIPELINE_RECOVERED)
            ->latest('sent_at')
            ->first();

        if ($recovered !== null && $recovered->sent_at->greaterThanOrEqualTo($alert->sent_at)) {
            return null;
        }

        return $alert->sent_at;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function send(array $status, bool $recovered, ?string $since, bool $test = false): void
    {
        $email = (string) config('app.admin_alert_email', '');

        if ($email === '') {
            $this->warn('Няма ADMIN_ALERT_EMAIL/ADMIN_REPORT_EMAIL/ADMIN_EMAIL — писмото не е изпратено.');

            return;
        }

        try {
            Mail::to($email)->send(new NewsPipelineAlertMail($status, $recovered, $since, $test));
            $this->info(match (true) {
                $test => 'Тестово писмо изпратено до '.$email.' — виж и папката за спам.',
                $recovered => 'Известие за възстановяване изпратено до '.$email,
                default => 'Аларма изпратена до '.$email,
            });
        } catch (Throwable $e) {
            // Провалът на самата аларма не бива да чупи графика — но трябва
            // да остане в лога, иначе мълчим двойно.
            Log::error('Неуспешно изпращане на аларма за news pipeline: '.$e->getMessage());
            $this->error('Писмото не мина: '.$e->getMessage());
        }
    }
}
