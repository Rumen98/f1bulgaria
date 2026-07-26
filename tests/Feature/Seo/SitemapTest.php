<?php

declare(strict_types=1);

use App\Console\Commands\GenerateSitemapCommand;
use App\Enums\NewsStatus;
use App\Models\DriverCanonical;
use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\File;

afterEach(function () {
    // Артефактите не остават между тестовете.
    foreach (['sitemap.xml', 'sitemap-news.xml', 'sitemap-content.xml'] as $file) {
        File::delete(public_path($file));
    }
});

it('urls() включва статични и динамични страници', function () {
    DriverCanonical::query()->create(['first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton']);

    $urls = app(GenerateSitemapCommand::class)->urls();

    expect($urls)->toContain(route('home'))
        ->and($urls)->toContain(route('tsolov'))
        ->and($urls)->toContain(route('f2'))
        ->and($urls)->toContain(route('drivers.show', 'lewis-hamilton'))
        ->and($urls->count())->toBe($urls->unique()->count()); // без дубликати
});

it('командата записва sitemap index + под-sitemap-и', function () {
    $this->artisan('sitemap:generate')->assertSuccessful();

    $index = File::get(public_path('sitemap.xml'));

    expect($index)->toContain('<sitemapindex')
        ->and($index)->toContain('sitemap-news.xml')
        ->and($index)->toContain('sitemap-content.xml')
        ->and(simplexml_load_string($index))->not->toBeFalse()
        ->and(File::exists(public_path('sitemap-news.xml')))->toBeTrue()
        ->and(File::exists(public_path('sitemap-content.xml')))->toBeTrue();
});

it('новинарският sitemap съдържа lastmod за всяка статия', function () {
    TeamNewsItem::factory()->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Заглавие',
        'published_at' => now()->subHour(),
    ]);

    $this->artisan('sitemap:generate')->assertSuccessful();

    $xml = File::get(public_path('sitemap-news.xml'));

    expect($xml)->toContain('<urlset')
        ->and($xml)->toContain('<lastmod>')
        ->and(substr_count($xml, '<loc>'))->toBe(substr_count($xml, '<lastmod>')) // всяка новина с дата
        ->and(simplexml_load_string($xml))->not->toBeFalse();
});

it('не включва непубликувани новини', function () {
    TeamNewsItem::factory()->create(['status' => NewsStatus::Pending->value, 'slug' => 'chakashta-novina']);

    $this->artisan('sitemap:generate')->assertSuccessful();

    expect(File::get(public_path('sitemap-news.xml')))->not->toContain('chakashta-novina');
});
