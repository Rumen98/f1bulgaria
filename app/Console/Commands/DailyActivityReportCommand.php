<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\DailyActivityMail;
use App\Models\AuthEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DailyActivityReportCommand extends Command
{
    protected $signature = 'report:daily-activity
        {--date= : Ден в Y-m-d (софийско време); по подразбиране днес}
        {--preview : Отпечатай отчета в конзолата вместо да пращаш имейл}';

    protected $description = 'Дневен отчет за активността (регистрации, влизания, неуспешни опити) — праща имейл на админа.';

    public function handle(): int
    {
        $tz = 'Europe/Sofia';
        $day = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), $tz)
            : CarbonImmutable::now($tz);

        // Базата пази времена в UTC — конвертираме границите на софийския ден.
        $events = AuthEvent::whereBetween('created_at', [
            $day->startOfDay()->utc(),
            $day->endOfDay()->utc(),
        ])->get();

        $logins = $events->where('type', AuthEvent::TYPE_LOGIN);

        $stats = [
            'date' => $day->format('d.m.Y'),
            'registrations' => $events->where('type', AuthEvent::TYPE_REGISTERED)->count(),
            'logins' => $logins->count(),
            'unique_logins' => $logins->whereNotNull('user_id')->pluck('user_id')->unique()->count(),
            'logouts' => $events->where('type', AuthEvent::TYPE_LOGOUT)->count(),
            'failed' => $events->where('type', AuthEvent::TYPE_FAILED)->count(),
            'total_users' => User::count(),
            'new_emails' => $events->where('type', AuthEvent::TYPE_REGISTERED)
                ->pluck('email')->filter()->values()->all(),
        ];

        if ($this->option('preview')) {
            $this->table(['Показател', 'Стойност'], [
                ['Дата', $stats['date']],
                ['Нови регистрации', $stats['registrations']],
                ['Влизания', $stats['logins']." (уникални: {$stats['unique_logins']})"],
                ['Изходи', $stats['logouts']],
                ['Неуспешни опити', $stats['failed']],
                ['Общо потребители', $stats['total_users']],
            ]);

            return self::SUCCESS;
        }

        $email = (string) config('app.admin_report_email', '');

        if ($email === '') {
            $this->error('Няма ADMIN_REPORT_EMAIL (нито ADMIN_EMAIL) в .env — задай го и презареди config кеша.');

            return self::FAILURE;
        }

        Mail::to($email)->send(new DailyActivityMail($stats));
        $this->info("Дневният отчет за {$stats['date']} е изпратен на {$email}.");

        return self::SUCCESS;
    }
}
