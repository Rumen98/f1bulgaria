<?php

declare(strict_types=1);

use App\Mail\DailyActivityMail;
use App\Models\NewsletterSend;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Models\Season;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    // Замразяваме времето по обед софийско, за да няма гранични случаи с полунощ.
    $this->travelTo(CarbonImmutable::parse('2026-07-24 12:00:00', 'Europe/Sofia'));

    config([
        'app.admin_report_email' => 'admin@padok.bg',
        // Тестовата среда е на `sync`; здравната секция брои от таблиците `jobs`
        // и `failed_jobs`, тоест иска драйвера, с който върви продукцията.
        'queue.default' => 'database',
    ]);
});

/** Пуска командата и връща изпратеното писмо — здравните числа живеят в него. */
function runDailyReport(): DailyActivityMail
{
    Mail::fake();

    test()->artisan('report:daily-activity')->assertSuccessful();

    $sent = null;

    Mail::assertSent(DailyActivityMail::class, function (DailyActivityMail $mail) use (&$sent): bool {
        $sent = $mail;

        return true;
    });

    return $sent;
}

/** Чакаща задача, готова за изпълнение преди $minutesAgo минути. */
function queueJob(int $minutesAgo): void
{
    $availableAt = now()->subMinutes($minutesAgo)->getTimestamp();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $availableAt,
        'created_at' => $availableAt,
    ]);
}

it('мълчи за опашката, когато worker-ът я изпразва', function () {
    queueJob(minutesAgo: 1);

    $health = runDailyReport()->stats['health'];

    expect($health['queue']['problems'])->toBe([])
        ->and($health['queue']['pending'])->toBe(1)
        ->and($health['queue']['failed_today'])->toBe(0)
        ->and($health['queue']['line'])->toContain('провалени днес: 0');
});

it('хваща спрял worker по възрастта на най-старата чакаща задача', function () {
    queueJob(minutesAgo: 60 * 6);

    $health = runDailyReport()->stats['health'];

    expect($health['queue']['oldest_minutes'])->toBe(360)
        ->and($health['queue']['problems'])->toHaveCount(1)
        ->and($health['queue']['problems'][0]['label'])->toBe('опашката стои')
        ->and($health['queue']['problems'][0]['text'])->toContain('6 ч');
});

it('не брои отложената задача за закъснение', function () {
    // Задача с бъдещо available_at стои в таблицата по проект — по created_at
    // тя би изглеждала като закъснение и би вдигала аларма всеки ден.
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->addHours(2)->getTimestamp(),
        'created_at' => now()->subDay()->getTimestamp(),
    ]);

    $health = runDailyReport()->stats['health'];

    expect($health['queue']['oldest_minutes'])->toBeNull()
        ->and($health['queue']['problems'])->toBe([]);
});

it('отчита днешните провалени задачи', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Resend: daily limit reached',
        'failed_at' => now(),
    ]);

    $health = runDailyReport()->stats['health'];

    expect($health['queue']['failed_today'])->toBe(1)
        ->and($health['queue']['problems'])->toHaveCount(1)
        ->and($health['queue']['problems'][0]['label'])->toBe('провалени задачи: 1');
});

it('казва кои вълни са тръгнали, без да твърди, че писмата са стигнали', function () {
    User::factory()->count(3)->create();

    NewsletterSend::create(['mail_type' => NewsletterSend::TYPE_DIGEST, 'sent_at' => now()]);

    $mail = runDailyReport()->stats['health']['mail'];

    expect($mail['problems'])->toBe([])
        ->and($mail['waves'])->toHaveCount(1)
        ->and($mail['waves'][0]['label'])->toBe('седмичен дайджест')
        ->and($mail['line'])->toContain('Тръгнали днес')
        ->and($mail['line'])->toContain('не доставени писма')
        ->and($mail['line'])->not->toContain('изпратени');
});

it('алармира, когато вълните за деня надхвърлят дневния лимит на пощата', function () {
    User::factory()->count(51)->create();

    NewsletterSend::create(['mail_type' => NewsletterSend::TYPE_DIGEST, 'sent_at' => now()]);
    NewsletterSend::create(['mail_type' => NewsletterSend::TYPE_PREDICTION_REMINDER, 'sent_at' => now()]);

    // Алармата за новините отива до един админски адрес — в обема ѝ няма място.
    NewsletterSend::create(['mail_type' => NewsletterSend::TYPE_PIPELINE_ALERT, 'sent_at' => now()]);

    $mail = runDailyReport()->stats['health']['mail'];

    expect($mail['bulk_waves'])->toBe(2)
        ->and($mail['estimate'])->toBe(102)
        ->and($mail['problems'])->toHaveCount(1)
        ->and($mail['problems'][0]['label'])->toBe('дневният лимит на пощата');
});

it('мълчи за рекапите, когато всеки приключил кръг си има рекап', function () {
    config(['features.data_recap' => true]);

    $season = Season::factory()->current()->create(['year' => 2026]);
    $race = Race::factory()->past()->create(['season_id' => $season->id]);
    RaceDataRecap::factory()->create(['race_id' => $race->id]);

    $recaps = runDailyReport()->stats['health']['recaps'];

    expect($recaps['problems'])->toBe([])
        ->and($recaps['missing_count'])->toBe(0)
        ->and($recaps['line'])->toContain('Всички приключили кръгове');
});

it('хваща приключилите кръгове, за които наваксването не е пускано', function () {
    config(['features.data_recap' => true]);

    $season = Season::factory()->current()->create(['year' => 2026]);
    Race::factory()->past()->create(['season_id' => $season->id, 'round' => 1]);
    Race::factory()->past()->create(['season_id' => $season->id, 'round' => 2]);

    // Току-що финиширалият кръг още е в прозореца, в който почасовият крон сам
    // наваксва — той не бива да брои за липсващ.
    Race::factory()->create([
        'season_id' => $season->id,
        'round' => 3,
        'race_datetime_utc' => now()->subHours(5),
        'qualifying_datetime_utc' => now()->subHours(29),
    ]);

    $recaps = runDailyReport()->stats['health']['recaps'];

    expect($recaps['missing_count'])->toBe(2)
        ->and($recaps['problems'])->toHaveCount(1)
        ->and($recaps['problems'][0]['label'])->toBe('без рекап: 2')
        ->and($recaps['problems'][0]['text'])->toContain('--season=2026');
});

it('изважда отделно рекапите, които са се отказали след пет опита', function () {
    config(['features.data_recap' => true]);

    $season = Season::factory()->current()->create(['year' => 2026]);
    $race = Race::factory()->past()->create(['season_id' => $season->id, 'round' => 1]);

    RaceDataRecap::factory()->failed()->create([
        'race_id' => $race->id,
        'attempts' => 5,
        'last_error' => 'OpenF1 върна празна сесия.',
    ]);

    // Кръг, минал през много опити и накрая проработил, не е изоставен.
    $ok = Race::factory()->past()->create(['season_id' => $season->id, 'round' => 2]);
    RaceDataRecap::factory()->create(['race_id' => $ok->id, 'attempts' => 7]);

    $recaps = runDailyReport()->stats['health']['recaps'];

    expect($recaps['abandoned_count'])->toBe(1)
        ->and($recaps['abandoned'][0]['error'])->toBe('OpenF1 върна празна сесия.')
        ->and($recaps['problems'])->toHaveCount(2)
        ->and($recaps['problems'][1]['label'])->toBe('отказали се рекапи: 1');
});

it('брои прогнозите за следващия незаключен кръг', function () {
    $race = Race::factory()->create([
        'race_datetime_utc' => now()->addDays(10),
        'qualifying_datetime_utc' => now()->addDays(9),
    ]);

    Prediction::factory()->count(4)->create(['race_id' => $race->id]);

    $league = runDailyReport()->stats['health']['league'];

    expect($league['problems'])->toBe([])
        ->and($league['predictions'])->toBe(4)
        ->and($league['race'])->toBe($race->name_bg);
});

it('алармира при нула прогнози в навечерието на заключването', function () {
    Race::factory()->create([
        'race_datetime_utc' => now()->addDay(),
        'qualifying_datetime_utc' => now()->addHours(10),
    ]);

    $league = runDailyReport()->stats['health']['league'];

    expect($league['predictions'])->toBe(0)
        ->and($league['hours_left'])->toBeLessThan(48)
        ->and($league['problems'])->toHaveCount(1)
        ->and($league['problems'][0]['label'])->toBe('нула прогнози');
});

it('не се вълнува от нула прогнози, докато кръгът е далеч', function () {
    Race::factory()->create([
        'race_datetime_utc' => now()->addDays(10),
        'qualifying_datetime_utc' => now()->addDays(9),
    ]);

    $league = runDailyReport()->stats['health']['league'];

    expect($league['predictions'])->toBe(0)
        ->and($league['problems'])->toBe([]);
});

it('оставя темата спокойна, когато няма нищо счупено', function () {
    expect(runDailyReport()->envelope()->subject)
        ->toBe('Падок — дневен отчет · 24.07.2026');
});

it('изнася проблемите в темата на писмото', function () {
    queueJob(minutesAgo: 60 * 6);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    Race::factory()->create([
        'race_datetime_utc' => now()->addDay(),
        'qualifying_datetime_utc' => now()->addHours(10),
    ]);

    $subject = runDailyReport()->envelope()->subject;

    // Първите два етикета се изписват, останалите се броят — иначе темата се
    // отрязва точно там, където става интересна.
    expect($subject)->toStartWith('Падок — ⚠️ опашката стои, провалени задачи: 1 +1')
        ->and($subject)->toEndWith('24.07.2026');
});

it('свива здравите секции до един ред в тялото на писмото', function () {
    $body = (string) runDailyReport()->render();

    expect($body)->toContain('Нищо не е счупено')
        ->and($body)->toContain('Празна, провалени днес: 0.')
        ->and($body)->not->toContain('Кръгове без рекап')
        ->and($body)->not->toContain('За оправяне');
});

it('изнася проблемните кръгове в списък под състоянието', function () {
    config(['features.data_recap' => true]);

    $season = Season::factory()->current()->create(['year' => 2026]);
    $race = Race::factory()->past()->create(['season_id' => $season->id, 'name' => 'Belgian Grand Prix']);

    $body = (string) runDailyReport()->render();

    expect($body)->toContain('За оправяне')
        ->and($body)->toContain('Кръгове без рекап')
        ->and($body)->toContain($race->name_bg);
});

it('пази --preview режима: таблица в конзолата и нула писма', function () {
    queueJob(minutesAgo: 60 * 6);
    Mail::fake();

    $this->artisan('report:daily-activity --preview')
        ->expectsOutputToContain('Общо потребители')
        ->expectsOutputToContain('опашката стои')
        ->expectsOutputToContain('Опашка')
        ->assertSuccessful();

    Mail::assertNothingSent();
});
