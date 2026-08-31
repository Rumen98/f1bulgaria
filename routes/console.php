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

// Синхрон на резултати. На 15 мин, а не на час: каналът публикува от базата и
// час закъснение се вижда. Jolpica прави 3 заявки на пускане при лимит ~500 на
// час, така че честотата не е проблем — Jolpica обаче публикува със собствено
// закъснение и това остава тясното място.
Schedule::command('f1:sync-results')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14);

// Разписание на сесиите + класации от тренировките и спринт квалификацията.
//
// Разписанието е критично, не украса: `race_sessions` се чете от
// NextRaceResolver, за да разбере дали тече уикенд. Докато таблицата беше
// празна, началната страница не показваше нито активен уикенд, нито
// състояние след финала.
Schedule::command('f1:sync-sessions')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Неделен вечерен дайджест в 20:00 софийско време.
//
// onOneServer пази от дублиран cron (напр. и на root, и на www-data):
// withoutOverlapping пуска mutex-а веднага щом командата приключи (секунди),
// а onOneServer държи lock за целия график-минутен слот. Освен това
// `newsletter_sends` маркира състезанието преди пращане — твърда
// идемпотентност дори при повторен ръчен пуск.
Schedule::command('f1:weekly-digest')
    ->weeklyOn(0, '20:00')
    ->timezone('Europe/Sofia')
    ->onOneServer()
    ->withoutOverlapping(120);

// Петъчен preview на състезателния уикенд в 09:00 софийско време.
// Вътрешният guard праща само ако до 7 дни напред има кръг.
Schedule::command('f1:race-preview')
    ->weeklyOn(5, '09:00')
    ->timezone('Europe/Sofia')
    ->onOneServer()
    ->withoutOverlapping(120);

// Подсещане за неподадена прогноза. Проверява на кръгъл час, но вътрешният
// guard (24-часов прозорец преди заключването + `newsletter_sends` по race_id)
// пуска най-много едно писмо на кръг — обикновено петък вечер.
//
// Отделно от preview-то нарочно: preview-то е за всички и е информационно,
// това стига САМО до хората без прогноза и е с една задача.
Schedule::command('f1:prediction-reminder')
    ->hourly()
    ->timezone('Europe/Sofia')
    ->onOneServer()
    ->withoutOverlapping(55);

// „Пулс" през паузите — проверка всяка сряда 18:00 софийско време.
// Седмично, а не месечно: закачен за 1-во число пулсът геометрично не може
// да улучи лятната пауза (1 август/септември винаги опират в guard-овете).
// Вътрешните guard-ове (14 дни тишина назад, 10 дни буфер напред, 21 дни
// между два пулса чрез `newsletter_sends`) го пускат ефективно веднъж на
// 3-4 седмици и само в дълги паузи.
Schedule::command('f1:offseason-pulse')
    ->weeklyOn(3, '18:00')
    ->timezone('Europe/Sofia')
    ->onOneServer()
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

// Най-важните новини към канала. На 15 мин, изместено от news:* слотовете
// (:00/:30 fetch, :05/:35 enrich, :20 publish-pending, :25 generate-articles),
// за да не се блъска с тях.
//
// Само поставя в опашката — изпращането е на channel:post.
Schedule::command('channel:enqueue-news')
    ->cron('8,23,38,53 * * * *')
    ->withoutOverlapping(14)
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
