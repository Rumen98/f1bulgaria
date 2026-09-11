<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\NewsClassificationResult;
use App\Services\News\Llm\NewsClassifier;
use App\Services\News\NewsEnricher;
use App\Services\News\SourceArticleFetcher;

beforeEach(function () {
    config()->set('news.enrich_sleep_ms', 0);
    test()->mock(SourceArticleFetcher::class, fn ($m) => $m->shouldReceive('fetch')->andReturn(null)->byDefault());
});

function offTopicResult(bool $isF1Related): NewsClassificationResult
{
    return new NewsClassificationResult(
        titleBg: 'Българско заглавие',
        summaryBg: 'Българско резюме.',
        classification: NewsClassification::Other,
        constructorId: null,
        importanceScore: 2,
        rawResponse: '{}',
        tokenUsage: ['input_tokens' => 10, 'output_tokens' => 20],
        isF1Related: $isF1Related,
    );
}

it('отхвърля новина извън темата, без да я публикува', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->once()->andReturn(offTopicResult(isF1Related: false));
        // Пълна статия НЕ бива да се пише за отхвърлена новина — това е и спестен LLM разход.
        $m->shouldNotReceive('generateFullArticle');
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($item->fresh()->status)->toBe(NewsStatus::Rejected)
        ->and($stats['off_topic'])->toBe(1)
        ->and($stats['success'])->toBe(0);
});

it('публикува нормално, когато новината е за Ф1', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->once()->andReturn(offTopicResult(isF1Related: true));
        $m->shouldReceive('generateFullArticle')->andThrow(new RuntimeException('без статия в теста'));
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($item->fresh()->status)->toBe(NewsStatus::AutoPublished)
        ->and($stats['off_topic'])->toBe(0);
});

it('чисти вече публикувани новини извън темата', function () {
    $motogp = TeamNewsItem::factory()->create([
        'title_original' => 'Bezzecchi leads Ducati riders in MotoGP practice',
        'title_bg' => 'Бецеки води в тренировките',
        'summary_bg' => 'Тренировка в Арагон.',
        'status' => NewsStatus::AutoPublished->value,
    ]);

    $f1 = TeamNewsItem::factory()->create([
        'title_original' => 'Hamilton warns Ferrari before Monza',
        'title_bg' => 'Хамилтън предупреждава Ферари',
        'summary_bg' => 'Преди Гран при на Италия.',
        'status' => NewsStatus::AutoPublished->value,
    ]);

    $this->artisan('news:purge-off-topic')->assertSuccessful();

    expect($motogp->fresh()->status)->toBe(NewsStatus::Rejected)
        ->and($f1->fresh()->status)->toBe(NewsStatus::AutoPublished);
});

it('пази новина, която споменава Ф1, дори при съвпадение по друга серия', function () {
    $item = TeamNewsItem::factory()->create([
        'title_original' => 'F1 driver tests a MotoGP bike in the off-season',
        'title_bg' => 'Пилот от Формула 1 тества мотор',
        'summary_bg' => 'Гран при почивка.',
        'status' => NewsStatus::AutoPublished->value,
    ]);

    $this->artisan('news:purge-off-topic')->assertSuccessful();

    expect($item->fresh()->status)->toBe(NewsStatus::AutoPublished);
});

it('dry-run не променя нищо', function () {
    $item = TeamNewsItem::factory()->create([
        'title_original' => 'NASCAR season finale recap',
        'title_bg' => 'Финал на сезона',
        'summary_bg' => 'Обзор.',
        'status' => NewsStatus::AutoPublished->value,
    ]);

    $this->artisan('news:purge-off-topic', ['--dry-run' => true])->assertSuccessful();

    expect($item->fresh()->status)->toBe(NewsStatus::AutoPublished);
});

it('не пипа вече отхвърлени новини', function () {
    $item = TeamNewsItem::factory()->create([
        'title_original' => 'MotoGP race report',
        'status' => NewsStatus::Rejected->value,
    ]);

    $this->artisan('news:purge-off-topic')->assertSuccessful();

    expect($item->fresh()->status)->toBe(NewsStatus::Rejected);
});
