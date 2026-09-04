<?php

declare(strict_types=1);

use App\Mail\NewsPipelineAlertMail;
use App\Models\NewsletterSend;
use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['app.admin_alert_email' => 'padok@example.test']);
});

/** Необработен елемент, който чака от N часа — главата на опашката. */
function awaitingItem(int $hoursAgo): TeamNewsItem
{
    return TeamNewsItem::factory()->create([
        'status' => 'pending',
        'title_bg' => null,
        'classification' => null,
        'created_at' => now()->subHours($hoursAgo),
    ]);
}

it('мълчи, когато опашката се източва нормално', function () {
    awaitingItem(1);
    TeamNewsItem::factory()->create(['status' => 'auto_published', 'title_bg' => 'Прясна новина']);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('мълчи в спокоен ден — празна опашка и скорошно вземане', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Новина',
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('алармира, когато главата на опашката чака часове', function () {
    awaitingItem(6);
    awaitingItem(5);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, function (NewsPipelineAlertMail $mail) {
        return $mail->recovered === false
            && $mail->status['pending'] === 2
            && str_contains((string) $mail->status['reason'], 'Обогатяването е засякло')
            && $mail->hasTo('padok@example.test');
    });
});

it('news:normalize-bg не бива да маскира засякло обогатяване', function () {
    // Регресия: първата версия мереше max(updated_at) на публикуваните.
    // news:normalize-bg пипа точно тези редове БЕЗ да минава през LLM, тоест
    // вдигаше updated_at и pipeline-ът изглеждаше жив, докато е мъртъв.
    awaitingItem(6);

    $published = TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Стара публикувана',
        'created_at' => now()->subDays(2),
    ]);
    $published->forceFill(['updated_at' => now()])->save();

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, fn (NewsPipelineAlertMail $m) => $m->recovered === false);
});

it('алармира, когато вземането е спряло, макар опашката да е празна', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Отдавнашна новина',
        'created_at' => now()->subHours(20),
    ]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, function (NewsPipelineAlertMail $mail) {
        return str_contains((string) $mail->status['reason'], 'Вземането е спряло');
    });
});

it('праща едно писмо на инцидент, не на всяка проверка', function () {
    awaitingItem(6);

    $this->artisan('news:health-check')->assertSuccessful();
    $this->artisan('news:health-check')->assertSuccessful();
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSentCount(1);
});

it('маркерът за инцидент преживява деплой — cache:clear не го трие', function () {
    // Регресия: маркерът стоеше в Cache::forever, а deploy.sh вика
    // optimize:clear (включва cache:clear) при cache store „database".
    // Деплой по време на авария алармираше повторно и губеше завинаги
    // писмото за възстановяване.
    awaitingItem(6);

    $this->artisan('news:health-check')->assertSuccessful();
    Mail::assertSentCount(1);

    Cache::flush();

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSentCount(1);
    expect(NewsletterSend::where('mail_type', NewsletterSend::TYPE_PIPELINE_ALERT)->count())->toBe(1);
});

it('съобщава при възстановяване и се въоръжава наново', function () {
    $stuck = awaitingItem(6);

    $this->artisan('news:health-check')->assertSuccessful();
    Mail::assertSentCount(1);

    // Pipeline-ът тръгва: чакащият елемент е обогатен и публикуван.
    $stuck->update(['status' => 'auto_published', 'title_bg' => 'Вече обработена', 'classification' => 'race']);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, fn (NewsPipelineAlertMail $mail) => $mail->recovered === true);
    Mail::assertSentCount(2);

    // Следващ инцидент трябва пак да алармира — журналът е затворен.
    awaitingItem(7);
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSentCount(3);
});

it('не гърми, когато липсва админ имейл', function () {
    config(['app.admin_alert_email' => '']);
    awaitingItem(6);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('мълчи при празна база — нов инсталационен профил не е авария', function () {
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('--force-alert праща писмо и когато pipeline-ът е ЗДРАВ — иначе доставката е непроверима', function () {
    TeamNewsItem::factory()->create(['status' => 'auto_published', 'title_bg' => 'Прясна новина']);

    $this->artisan('news:health-check --force-alert')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, function (NewsPipelineAlertMail $mail) {
        return $mail->test === true
            && $mail->status['healthy'] === true
            && $mail->hasTo('padok@example.test');
    });
});

it('--force-alert не отваря инцидент в журнала', function () {
    TeamNewsItem::factory()->create(['status' => 'auto_published', 'title_bg' => 'Прясна новина']);

    $this->artisan('news:health-check --force-alert')->assertSuccessful();
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSentCount(1);
    expect(NewsletterSend::where('mail_type', NewsletterSend::TYPE_PIPELINE_ALERT)->count())->toBe(0);
});

it('тестовото писмо не носи заглавието на истинска авария', function () {
    $status = ['healthy' => true, 'reason' => null, 'pending' => 0,
        'oldest_pending_at' => null, 'last_fetched_at' => null, 'stale_hours' => null];

    expect((new NewsPipelineAlertMail($status, test: true))->envelope()->subject)
        ->toBe('Падок — тест на алармата за новините')
        ->and((new NewsPipelineAlertMail([...$status, 'healthy' => false, 'reason' => 'спряло']))->envelope()->subject)
        ->toBe('Падок — ВНИМАНИЕ: новините спряха');
});

it('писмото за авария се рендерира без грешка', function () {
    // Mail::fake() не пипа Blade-а — счупен темплейт минава останалите
    // тестове и гърми чак при истинско изпращане. Затова рендерираме.
    $html = (new NewsPipelineAlertMail([
        'healthy' => false,
        'reason' => 'Обогатяването е засякло: 12 чакащи новини.',
        'pending' => 12,
        'oldest_pending_at' => '2026-09-04 16:00:02',
        'last_fetched_at' => '2026-09-04 19:47:04',
        'stale_hours' => 6,
    ], recovered: false, since: null))->render();

    expect($html)->toContain('Новините спряха')
        ->and($html)->toContain('Обогатяването е засякло')
        ->and($html)->toContain('12');
});

it('писмата за възстановяване и за тест се рендерират без грешка', function () {
    $status = ['healthy' => true, 'reason' => null, 'pending' => 0,
        'oldest_pending_at' => null, 'last_fetched_at' => '2026-09-04 20:05:00', 'stale_hours' => null];

    expect((new NewsPipelineAlertMail($status, recovered: true, since: '2026-09-03 16:00:00'))->render())
        ->toContain('пак се публикуват')
        ->toContain('2026-09-03 16:00:00')
        ->and((new NewsPipelineAlertMail($status, test: true))->render())
        ->toContain('Тест на алармата')
        ->toContain('здраво');
});
