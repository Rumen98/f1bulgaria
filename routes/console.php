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
// withoutOverlapping е задължително тук: изпраща имейли до всички абонати,
// а дублиран cron (напр. и на root, и на www-data) би го пуснал два пъти.
Schedule::command('f1:weekly-digest')
    ->weeklyOn(0, '20:00')
    ->timezone('Europe/Sofia')
    ->withoutOverlapping(120);

// News pipeline — новини през целия ден: вземане на всеки 30 мин, LLM
// превод + автоматична публикация 5 мин по-късно. LLM разходът зависи от
// броя нови елементи, не от честотата — по-честият цикъл не струва повече.
Schedule::command('news:fetch')
    ->cron('0,30 * * * *')
    ->withoutOverlapping(25)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Lock-ът изтича след 40 мин: при runInBackground убит процес (OOM, deploy,
// reboot) не освобождава mutex-а и default-ните 24h биха спрели цикъла за ден.
Schedule::command('news:enrich --limit=25')
    ->cron('5,35 * * * *')
    ->withoutOverlapping(40)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Осигурителна мрежа: обогатени, но незавършили публикация елементи (напр.
// грешка при featured_image) се публикуват тук, вместо да висят pending.
Schedule::command('news:publish-pending')
    ->hourlyAt(20)
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// F2 синхрон от официалния API. На 15 мин, защото резултатите излизат минути
// след сесията, а каналът публикува от базата.
//
// Честотата не е скъпа: F2ApiSync дърпа класация само за сесия без резултати
// или за състезание, което още не е `Final`. В спокоен ден това са две
// заявки — календарът и класирането.
//
// f2:sync-wikipedia вече НЕ е в разписанието — API-то е основният източник,
// а Wikipedia остава ръчна, само за сезоните преди 2026 (`--historical`).
Schedule::command('f2:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Свеж sitemap всеки час — публикуваните през деня статии влизат в индекса
// на Google без ръчна намеса.
Schedule::command('sitemap:generate')
    ->hourlyAt(45)
    ->withoutOverlapping();

// Дописва пълни статии там, където inline генерацията в news:enrich се е
// провалила, плюс наваксване на стария backlog — най-новите първо.
Schedule::command('news:generate-articles --limit=10')
    ->hourlyAt(25)
    ->withoutOverlapping(50)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Изпразва изходящата опашка към Telegram канала. На 5 мин: синхроните само
// пълнят опашката, а тя трябва да тръгва бързо след сесия.
//
// withoutOverlapping е задължително — две едновременни пускания биха взели
// един и същи ред и биха го публикували два пъти, преди първото да е
// маркирало `sent_at`.
Schedule::command('channel:post')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Дневен отчет за активността към админ имейла — накрая на деня (софийско).
Schedule::command('report:daily-activity')
    ->dailyAt('23:55')
    ->timezone('Europe/Sofia')
    ->withoutOverlapping();
