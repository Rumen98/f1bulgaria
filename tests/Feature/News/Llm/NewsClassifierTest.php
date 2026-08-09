<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Models\Constructor;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\LlmClient;
use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\NewsClassifier;

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
    $this->constructor = Constructor::factory()->create(['season_id' => $this->season->id]);
    $this->item = TeamNewsItem::factory()->create([
        'title_original' => 'Verstappen wins in Brazil',
        'content_snippet' => 'Max Verstappen took a dominant win.',
    ]);
});

/**
 * Връзва mock LlmClient, който връща подадения tool input (както при
 * forced tool use — вече структуриран, без JSON parsing).
 *
 * @param  array<string, mixed>  $input
 */
function mockTool(array $input): void
{
    test()->mock(LlmClient::class, function ($mock) use ($input) {
        $mock->shouldReceive('completeWithTool')
            ->once()
            ->andReturn(['input' => $input, 'input_tokens' => 15, 'output_tokens' => 40]);
    });
}

it('връща структуриран резултат от tool input', function () {
    mockTool([
        'title_bg' => 'Верстапен триумфира в Бразилия',
        'summary_bg' => 'Макс Верстапен спечели състезанието в Бразилия. Победата го доближава до титлата.',
        'classification' => 'race',
        'constructor_id' => $this->constructor->id,
        'importance_score' => 4,
    ]);

    $result = app(NewsClassifier::class)->classify($this->item);

    expect($result->titleBg)->toBe('Верстапен триумфира в Бразилия')
        ->and($result->classification)->toBe(NewsClassification::Race)
        ->and($result->constructorId)->toBe($this->constructor->id)
        ->and($result->importanceScore)->toBe(4)
        ->and($result->tokenUsage)->toBe(['input_tokens' => 15, 'output_tokens' => 40]);
});

it('приема constructor_id = null (обща новина)', function () {
    mockTool([
        'title_bg' => 'Промени в правилника за 2026',
        'summary_bg' => 'FIA обяви нови технически регулации. Те засягат всички отбори.',
        'classification' => 'technical',
        'constructor_id' => null,
        'importance_score' => 3,
    ]);

    $result = app(NewsClassifier::class)->classify($this->item);

    expect($result->constructorId)->toBeNull()
        ->and($result->classification)->toBe(NewsClassification::Technical);
});

it('хвърля LlmException при невалидна класификация', function () {
    mockTool([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'gossip',
        'constructor_id' => null,
        'importance_score' => 3,
    ]);

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при несъществуващ constructor_id', function () {
    mockTool([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'constructor_id' => 999999,
        'importance_score' => 3,
    ]);

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при importance извън 1-5', function () {
    mockTool([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'constructor_id' => null,
        'importance_score' => 9,
    ]);

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при празно заглавие или резюме', function () {
    mockTool([
        'title_bg' => '',
        'summary_bg' => '',
        'classification' => 'race',
        'constructor_id' => null,
        'importance_score' => 3,
    ]);

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('генерира разширена статия от tool input', function () {
    test()->mock(LlmClient::class, function ($mock) {
        $mock->shouldReceive('completeWithTool')->once()->andReturn([
            'input' => [
                'full_article_bg' => "Първи параграф на статията.\n\nВтори параграф с детайли.",
                'key_facts' => ['Победа за Ферари', 'Първа от 2 години', ''],
                'our_analysis_bg' => 'Нашият анализ за случилото се.',
            ],
            'input_tokens' => 120,
            'output_tokens' => 850,
        ]);
    });

    $content = app(NewsClassifier::class)->generateFullArticle($this->item);

    expect($content->fullArticleBg)->toContain('Първи параграф')
        ->and($content->keyFacts)->toBe(['Победа за Ферари', 'Първа от 2 години']) // празните се махат
        ->and($content->analysisBg)->toBe('Нашият анализ за случилото се.')
        ->and($content->tokenUsage)->toBe(['input_tokens' => 120, 'output_tokens' => 850]);
});

it('хвърля LlmException при празна разширена статия', function () {
    test()->mock(LlmClient::class, function ($mock) {
        $mock->shouldReceive('completeWithTool')->once()->andReturn([
            'input' => ['full_article_bg' => '   ', 'key_facts' => [], 'our_analysis_bg' => ''],
            'input_tokens' => 10,
            'output_tokens' => 5,
        ]);
    });

    expect(fn () => app(NewsClassifier::class)->generateFullArticle($this->item))
        ->toThrow(LlmException::class);
});
