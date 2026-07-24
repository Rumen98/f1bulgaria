<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Заключва прогнозите 5 мин преди квалификацията — върви всяка минута.
Schedule::command('f1:lock-predictions')
    ->everyMinute()
    ->withoutOverlapping();

// Синхрон на резултати — на всеки час (състезанията са в неделя следобед).
Schedule::command('f1:sync-results')
    ->hourly()
    ->withoutOverlapping();

// Неделен вечерен дайджест в 20:00 софийско време.
Schedule::command('f1:weekly-digest')
    ->weeklyOn(0, '20:00')
    ->timezone('Europe/Sofia');

// News pipeline — вземане сутрин, после LLM обогатяване.
Schedule::command('news:fetch')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('news:enrich --limit=50')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// F2 синхрон от Wikipedia (текущите сезони) — дневно, преди sitemap-а,
// защото той включва F2 слъговете. Кешът на WikipediaClient е 24h, така
// че по-често пускане няма да донесе по-свежи данни.
Schedule::command('f2:sync-wikipedia')
    ->dailyAt('06:45')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Свеж sitemap всеки ден след новинарския цикъл — новите статии влизат
// в индекса на Google без ръчна намеса.
Schedule::command('sitemap:generate')
    ->dailyAt('07:00')
    ->withoutOverlapping();
