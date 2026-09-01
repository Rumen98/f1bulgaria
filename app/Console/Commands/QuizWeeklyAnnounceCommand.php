<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\QuizWeeklyMail;
use App\Models\NewsletterSend;
use App\Models\QuizQuestion;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Понеделнишкият анонс на куиза: писмо до всички + пост в Telegram канала.
 *
 * Писмото носи и „Знаеш ли, че…" — по една функция на сайта, избрана
 * детерминистично по седмицата. Двете задачи са в едно писмо нарочно:
 * отделно „запознавателно" писмо в случаен ден би преляло пощата.
 */
class QuizWeeklyAnnounceCommand extends Command
{
    protected $signature = 'padok:quiz-monday
        {--dry-run : Само отчита кой би получил писмо}
        {--force : Праща дори ако тази седмица вече е обявена}';

    protected $description = 'Обявява новия седмичен куиз: имейл до всички + пост в Telegram канала.';

    /**
     * Функциите за „Знаеш ли, че…". Ротацията е по ISO седмицата, така че
     * всяка изкарва своя ред; нова функция → нов ред тук.
     *
     * @var array<int, array{title:string, text:string, path:string, feature?:string}>
     */
    private const SPOTLIGHTS = [
        ['title' => 'Търсачката', 'text' => 'Пилоти, отбори, състезания, новини и речникът — на едно място, на кирилица и латиница.', 'path' => '/tarsene'],
        ['title' => 'Календарът в телефона ти', 'text' => 'Абонирай се за .ics календара и всички сесии влизат в телефона ти, в софийско време, с едно докосване.', 'path' => '/calendar'],
        ['title' => 'Разбивката на прогнозата', 'text' => 'След всяко състезание виждаш кое точно си познал — позиция по позиция, бонус по бонус.', 'path' => '/leaderboard'],
        ['title' => 'Чуждите прогнози', 'text' => 'След заключването на кръга виждаш какво са заложили останалите — и кой е рискувал.', 'path' => '/calendar'],
        ['title' => 'Значките', 'text' => 'Профилът показва всички значки и как се печели всяка — включително тези, които още гониш.', 'path' => '/leaderboard'],
        ['title' => 'Историята на пистата', 'text' => 'Страницата на всеки предстоящ кръг показва последните победители и рекордите на пистата.', 'path' => '/calendar'],
        ['title' => 'Речникът', 'text' => 'Всички термини на Формула 1, обяснени на български — от апекс до porpoising.', 'path' => '/terminologiya'],
        ['title' => 'Таймингът на живо', 'text' => 'По време на сесия виждаш позициите и интервалите в реално време, направо в сайта.', 'path' => '/live', 'feature' => 'live_timing'],
    ];

    public function handle(NewsletterAudience $audience, TelegramClient $telegram): int
    {
        if (! config('features.quiz')) {
            $this->info('FEATURE_QUIZ е изключен — пропускаме.');

            return self::SUCCESS;
        }

        if (! QuizQuestion::query()->active()->exists()) {
            $this->warn('Няма активни въпроси — няма какво да обявяваме.');

            return self::SUCCESS;
        }

        $week = (int) Carbon::now('Europe/Sofia')->isoWeek();
        $mailType = 'quiz-monday-'.Carbon::now('Europe/Sofia')->isoFormat('GGGG-[W]WW');

        if (! $this->option('force') && NewsletterSend::query()->where('mail_type', $mailType)->exists()) {
            $this->info('Тази седмица вече е обявена — пропускаме. (--force за повторно)');

            return self::SUCCESS;
        }

        $recipients = $audience->users();
        $subscribers = $audience->subscribersWithoutAccount($recipients);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Биха получили писмо: {$recipients->count()} потребители + {$subscribers->count()} абонати. Функция на седмицата: „{$this->spotlight($week)['title']}“.");

            return self::SUCCESS;
        }

        NewsletterSend::create(['mail_type' => $mailType, 'sent_at' => now()]);

        $spotlight = $this->spotlight($week);

        foreach ($recipients as $user) {
            Mail::to($user)->queue(new QuizWeeklyMail(
                week: $week,
                spotlight: $spotlight,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        foreach ($subscribers as $subscriber) {
            Mail::to($subscriber->email)->queue(new QuizWeeklyMail(
                week: $week,
                spotlight: $spotlight,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->postToChannel($telegram, $week);

        $this->info("Обявено: {$recipients->count()} потребители + {$subscribers->count()} абонати + каналът.");

        return self::SUCCESS;
    }

    /**
     * Функцията на седмицата — детерминистично по ISO номера, само от
     * включените модули.
     *
     * @return array{title:string, text:string, url:string}
     */
    private function spotlight(int $week): array
    {
        $available = array_values(array_filter(
            self::SPOTLIGHTS,
            fn (array $item) => ! isset($item['feature']) || config("features.{$item['feature']}"),
        ));

        $chosen = $available[$week % count($available)];

        return [
            'title' => $chosen['title'],
            'text' => $chosen['text'],
            'url' => url($chosen['path']),
        ];
    }

    /**
     * Краткият пост в канала. Без него понеделник минава тихо — а каналът е
     * безплатният мегафон. Грешка тук не проваля писмата: те вече са в
     * опашката.
     */
    private function postToChannel(TelegramClient $telegram, int $week): void
    {
        if (! $telegram->hasCredentials()) {
            $this->warn('Без Telegram креденшъли — постът в канала е пропуснат.');

            return;
        }

        $url = url('/quiz');

        try {
            $telegram->send(
                "🧠 <b>Куизът на седмица {$week} е тук!</b>\n\n"
                ."10 нови въпроса, еднакви за всички. Точка при първия верен отговор — втори опит няма.\n\n"
                ."<a href=\"{$url}\">Реши ги в Падок</a>",
            );
        } catch (\Throwable $e) {
            $this->warn("Постът в канала се провали: {$e->getMessage()}");
        }
    }
}
