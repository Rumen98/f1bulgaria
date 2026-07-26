<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\Http;

/**
 * Проверява, че SSR наистина слага съдържание в първоначалния HTML.
 *
 * Изисква жив демон (`php artisan inertia:start-ssr`) — иначе се пропуска,
 * за да не вали CI. Пусни ръчно след `npm run build`, когато пипаш SSR.
 */
beforeEach(function () {
    config()->set('inertia.ssr.enabled', true);

    $url = (string) config('inertia.ssr.url');

    try {
        $healthy = Http::timeout(2)->get($url.'/health')->successful();
    } catch (Throwable) {
        $healthy = false;
    }

    if (! $healthy) {
        test()->markTestSkipped('SSR демонът не върви — пусни `php artisan inertia:start-ssr`.');
    }
});

it('рендерира текста на статията в първоначалния HTML', function () {
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Норис спечели квалификацията в Унгария',
        'summary_bg' => 'Ландо Норис записа пол позиция.',
        'full_article_bg' => "Първи параграф с фактите.\n\nВтори параграф.",
        'our_analysis_bg' => 'Нашият анализ на случилото се.',
        'key_facts' => ['Пол позиция номер шест'],
        'classification' => 'race',
        'published_at' => now()->subHour(),
    ]);

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    expect($html)->toContain('Първи параграф с фактите')
        ->and($html)->toContain('Нашият анализ на случилото се')
        ->and($html)->toContain('Пол позиция номер шест')
        // Заглавието трябва да е в <h1>, не само в мета таг.
        ->and($html)->toContain('Норис спечели квалификацията в Унгария');
});

it('рендерира навигацията — hasRoute() работи и в Node', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // Ако globalThis.route липсва в ssr.js, hasRoute() връща false за всичко
    // и целият навигационен блок изчезва от SSR HTML-а.
    expect($html)->toContain('href="'.route('news.index').'"')
        ->and($html)->toContain('href="'.route('calendar').'"')
        ->and($html)->toContain('href="'.route('standings').'"');
});

it('рендерира линкове към отделните статии в списъка', function () {
    TeamNewsItem::factory()->count(3)->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Новина за теста',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'published_at' => now()->subHour(),
    ]);

    $html = $this->get('/news')->assertOk()->getContent();

    $slugs = TeamNewsItem::query()->pluck('slug');

    // Вътрешните линкове са crawlable само ако са в първоначалния HTML.
    foreach ($slugs as $slug) {
        expect($html)->toContain("/news/{$slug}");
    }
});

it('запазва сървърните мета тагове при включен SSR', function () {
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Заглавие за мета проверка',
        'summary_bg' => 'Резюме за мета проверка.',
        'classification' => 'race',
        'published_at' => now()->subHour(),
    ]);

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    // SSR добавя head тагове през @inertiaHead — не бива да дублира
    // или да изтрие сървърните og тагове от app.blade.php.
    expect(substr_count($html, 'property="og:title"'))->toBe(1)
        ->and($html)->toContain('property="og:title" content="Заглавие за мета проверка"')
        ->and(substr_count($html, '<title'))->toBe(1);
});
