<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\NewsPipelineAlertMail;
use App\Services\News\NewsPipelineHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
 */
class NewsHealthCheckCommand extends Command
{
    protected $signature = 'news:health-check {--force-alert : Праща писмото дори ако вече е алармирано (за проверка на доставката)}';

    protected $description = 'Проверява дали news pipeline-ът още публикува и алармира по имейл при спиране.';

    /** Ключ със състоянието на текущия инцидент — cache store-ът е database, значи преживява деплой. */
    private const ALERT_KEY = 'news:pipeline:alerted-at';

    public function handle(NewsPipelineHealth $health): int
    {
        $status = $health->check();
        $alreadyAlerted = Cache::get(self::ALERT_KEY);

        if ($status['healthy']) {
            $this->info('Pipeline-ът е здрав. Чакащи: '.$status['pending'].', последна публикация: '.($status['last_published_at'] ?? 'няма'));

            // Възстановяване след инцидент — казваме го веднъж и чистим флага.
            if ($alreadyAlerted !== null) {
                Cache::forget(self::ALERT_KEY);
                $this->send($status, recovered: true, since: (string) $alreadyAlerted);
            }

            return self::SUCCESS;
        }

        $this->error('АВАРИЯ: '.$status['reason']);
        Log::error('News pipeline health check се провали: '.$status['reason'], $status);

        // Един имейл на инцидент, не на всеки час — иначе алармата се
        // превръща в шум и спира да се чете.
        if ($alreadyAlerted !== null && ! $this->option('force-alert')) {
            $this->line('Вече е алармирано на '.$alreadyAlerted.' — пропускам писмото.');

            return self::SUCCESS;
        }

        Cache::forever(self::ALERT_KEY, now()->toDateTimeString());
        $this->send($status, recovered: false, since: null);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function send(array $status, bool $recovered, ?string $since): void
    {
        $email = (string) config('app.admin_alert_email', '');

        if ($email === '') {
            $this->warn('Няма ADMIN_ALERT_EMAIL/ADMIN_REPORT_EMAIL/ADMIN_EMAIL — писмото не е изпратено.');

            return;
        }

        try {
            Mail::to($email)->send(new NewsPipelineAlertMail($status, $recovered, $since));
            $this->info(($recovered ? 'Известие за възстановяване' : 'Аларма').' изпратена до '.$email);
        } catch (Throwable $e) {
            // Провалът на самата аларма не бива да чупи графика — но трябва
            // да остане в лога, иначе мълчим двойно.
            Log::error('Неуспешно изпращане на аларма за news pipeline: '.$e->getMessage());
            $this->error('Писмото не мина: '.$e->getMessage());
        }
    }
}
