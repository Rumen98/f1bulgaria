<?php

declare(strict_types=1);

use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;
use Illuminate\Support\Facades\Http;

it('върви и показва статистики', function () {
    TeamNewsSource::factory()->create(['name' => 'Autosport', 'feed_url' => 'https://a.test/rss']);
    Http::fake(['https://a.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200)]);

    $this->artisan('news:fetch')
        ->assertSuccessful()
        ->expectsOutputToContain('Autosport');

    expect(TeamNewsItem::count())->toBe(3);
});

it('обхожда конкретен източник през --source', function () {
    $target = TeamNewsSource::factory()->create(['feed_url' => 'https://a.test/rss']);
    TeamNewsSource::factory()->create(['feed_url' => 'https://other.test/rss']);

    Http::fake([
        'https://a.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200),
        'https://other.test/rss' => Http::response(newsFixture('atom_sample.xml'), 200),
    ]);

    $this->artisan('news:fetch', ['--source' => $target->id])->assertSuccessful();

    // Само таргетираният източник е обходен (3 елемента, не 5).
    expect(TeamNewsItem::count())->toBe(3)
        ->and(TeamNewsItem::where('source_id', $target->id)->count())->toBe(3);
});

it('връща грешка за несъществуващ източник', function () {
    $this->artisan('news:fetch', ['--source' => 9999])->assertFailed();
});

it('минава гладко без активни източници', function () {
    TeamNewsSource::factory()->inactive()->create();

    $this->artisan('news:fetch')->assertSuccessful();

    expect(TeamNewsItem::count())->toBe(0);
});
