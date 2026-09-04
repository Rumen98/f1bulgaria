<?php

declare(strict_types=1);

use App\Mail\NewsPipelineAlertMail;
use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['app.admin_alert_email' => 'padok@example.test']);
});

it('мълчи, когато pipeline-ът публикува нормално', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Прясна новина',
        'updated_at' => now()->subMinutes(20),
    ]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('мълчи в спокоен ден — няма чакащи, значи няма авария', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Стара новина',
        'updated_at' => now()->subHours(8),
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('алармира, когато чакащите се трупат, а нищо не се публикува', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Последната публикувана',
        'updated_at' => now()->subHours(6),
    ]);

    TeamNewsItem::factory()->count(3)->create([
        'status' => 'pending',
        'title_bg' => null,
        'classification' => null,
    ]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, function (NewsPipelineAlertMail $mail) {
        return $mail->recovered === false
            && $mail->status['pending'] === 3
            && $mail->hasTo('padok@example.test');
    });
});

it('алармира, когато вземането е спряло, макар да няма чакащи', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Отдавнашна новина',
        'created_at' => now()->subHours(20),
        'updated_at' => now()->subHours(20),
    ]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, function (NewsPipelineAlertMail $mail) {
        return str_contains((string) $mail->status['reason'], 'Вземането е спряло');
    });
});

it('праща едно писмо на инцидент, не на всяка проверка', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Последната публикувана',
        'updated_at' => now()->subHours(6),
    ]);
    TeamNewsItem::factory()->create(['status' => 'pending', 'title_bg' => null, 'classification' => null]);

    $this->artisan('news:health-check')->assertSuccessful();
    $this->artisan('news:health-check')->assertSuccessful();
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSentCount(1);
});

it('съобщава при възстановяване и се въоръжава наново', function () {
    $published = TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Последната публикувана',
        'updated_at' => now()->subHours(6),
    ]);
    $pending = TeamNewsItem::factory()->create(['status' => 'pending', 'title_bg' => null, 'classification' => null]);

    $this->artisan('news:health-check')->assertSuccessful();
    Mail::assertSentCount(1);

    // Pipeline-ът тръгва: чакащият елемент е обогатен и публикуван.
    $pending->update(['status' => 'auto_published', 'title_bg' => 'Вече обработена', 'classification' => 'race']);
    $published->update(['updated_at' => now()]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, fn (NewsPipelineAlertMail $mail) => $mail->recovered === true);
    Mail::assertSentCount(2);
});

it('не гърми, когато липсва админ имейл', function () {
    config(['app.admin_alert_email' => '']);

    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Последната публикувана',
        'updated_at' => now()->subHours(6),
    ]);
    TeamNewsItem::factory()->create(['status' => 'pending', 'title_bg' => null, 'classification' => null]);

    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('мълчи при празна база — нов инсталационен профил не е авария', function () {
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('писмото за авария се рендерира без грешка', function () {
    // Mail::fake() не пипа Blade-а — счупен темплейт минава останалите
    // тестове и гърми чак при истинско изпращане. Затова рендерираме.
    $html = (new NewsPipelineAlertMail([
        'healthy' => false,
        'reason' => 'Обогатяването е спряло: 12 чакащи новини.',
        'pending' => 12,
        'last_published_at' => '2026-09-04 16:00:02',
        'last_fetched_at' => '2026-09-04 19:47:04',
        'stale_hours' => 6,
    ], recovered: false, since: null))->render();

    expect($html)->toContain('Новините спряха')
        ->and($html)->toContain('Обогатяването е спряло')
        ->and($html)->toContain('12');
});

it('писмото за възстановяване се рендерира без грешка', function () {
    $html = (new NewsPipelineAlertMail([
        'healthy' => true,
        'reason' => null,
        'pending' => 0,
        'last_published_at' => '2026-09-04 20:10:00',
        'last_fetched_at' => '2026-09-04 20:05:00',
        'stale_hours' => null,
    ], recovered: true, since: '2026-09-03 16:00:00'))->render();

    expect($html)->toContain('пак се публикуват')
        ->and($html)->toContain('2026-09-03 16:00:00');
});

it('--force-alert праща писмо и когато pipeline-ът е ЗДРАВ — иначе доставката е непроверима', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Прясна новина',
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('news:health-check --force-alert')->assertSuccessful();

    Mail::assertSent(NewsPipelineAlertMail::class, function (NewsPipelineAlertMail $mail) {
        return $mail->test === true
            && $mail->status['healthy'] === true
            && $mail->hasTo('padok@example.test');
    });
});

it('тестовото писмо не носи заглавието на истинска авария', function () {
    $test = new NewsPipelineAlertMail(['healthy' => true, 'reason' => null, 'pending' => 0,
        'last_published_at' => null, 'last_fetched_at' => null, 'stale_hours' => null], test: true);
    $real = new NewsPipelineAlertMail(['healthy' => false, 'reason' => 'спряло', 'pending' => 5,
        'last_published_at' => null, 'last_fetched_at' => null, 'stale_hours' => 6]);

    expect($test->envelope()->subject)->toBe('Падок — тест на алармата за новините')
        ->and($real->envelope()->subject)->toBe('Падок — ВНИМАНИЕ: новините спряха');
});

it('тестовото писмо се рендерира без грешка', function () {
    $html = (new NewsPipelineAlertMail([
        'healthy' => true,
        'reason' => null,
        'pending' => 1,
        'last_published_at' => '2026-09-04 20:25:55',
        'last_fetched_at' => '2026-09-04 20:30:00',
        'stale_hours' => null,
    ], test: true))->render();

    expect($html)->toContain('Тест на алармата')
        ->and($html)->toContain('здраво');
});

it('--force-alert не вдига флага за инцидент', function () {
    TeamNewsItem::factory()->create([
        'status' => 'auto_published',
        'title_bg' => 'Прясна новина',
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('news:health-check --force-alert')->assertSuccessful();

    // Второто пускане без флага трябва да мълчи — тестът не бива да оставя
    // системата да мисли, че тече инцидент.
    $this->artisan('news:health-check')->assertSuccessful();

    Mail::assertSentCount(1);
});
