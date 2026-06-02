<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\NewsClassificationResult;
use App\Services\News\Llm\NewsClassifier;
use App\Services\News\NewsEnricher;

beforeEach(function () {
    // Без реална пауза между заявките в тестовете.
    config()->set('news.enrich_sleep_ms', 0);
});

function classResult(int $in = 10, int $out = 20): NewsClassificationResult
{
    return new NewsClassificationResult(
        titleBg: 'Българско заглавие',
        summaryBg: 'Българско резюме на новината.',
        classification: NewsClassification::Race,
        constructorId: null,
        importanceScore: 3,
        rawResponse: '{}',
        tokenUsage: ['input_tokens' => $in, 'output_tokens' => $out],
    );
}

it('попълва полетата на pending items и запазва статуса pending', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult()));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats)->toMatchArray(['processed' => 2, 'success' => 2, 'failed' => 0]);

    $item = TeamNewsItem::first();
    expect($item->title_bg)->toBe('Българско заглавие')
        ->and($item->classification)->toBe(NewsClassification::Race)
        ->and($item->importance_score)->toBe(3)
        ->and($item->status)->toBe(NewsStatus::Pending); // статусът остава pending
});

it('грешка в един item не спира batch-а', function () {
    $items = TeamNewsItem::factory()->count(3)->create(['title_bg' => null, 'classification' => null]);
    $badId = $items[1]->id;

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')
        ->andReturnUsing(function (TeamNewsItem $item) use ($badId) {
            if ($item->id === $badId) {
                throw new LlmException('boom');
            }

            return classResult();
        }));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['processed'])->toBe(3)
        ->and($stats['success'])->toBe(2)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['errors'])->toHaveCount(1)
        ->and(TeamNewsItem::find($badId)->title_bg)->toBeNull();
});

it('сумира token usage', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult(10, 20)));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['input_tokens'])->toBe(20)
        ->and($stats['output_tokens'])->toBe(40);
});

it('не пипа items, които вече са обогатени', function () {
    $enriched = TeamNewsItem::factory()->create([
        'title_bg' => 'Вече преведено',
        'classification' => NewsClassification::Driver->value,
    ]);
    TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->once()->andReturn(classResult()));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['processed'])->toBe(1) // само неенрихнатият
        ->and($enriched->fresh()->title_bg)->toBe('Вече преведено');
});

it('спазва --limit', function () {
    TeamNewsItem::factory()->count(5)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult()));

    $stats = app(NewsEnricher::class)->enrichPending(2);

    expect($stats['processed'])->toBe(2);
});
