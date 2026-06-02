<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Models\Constructor;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\AnthropicClient;
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
 * Връзва mock AnthropicClient, който връща подадения tool input (както при
 * forced tool use — вече структуриран, без JSON parsing).
 *
 * @param  array<string, mixed>  $input
 */
function mockTool(array $input): void
{
    test()->mock(AnthropicClient::class, function ($mock) use ($input) {
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
