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
 * Връзва mock AnthropicClient, който отговаря със зададения суров текст.
 */
function mockClaude(string $content): void
{
    test()->mock(AnthropicClient::class, function ($mock) use ($content) {
        $mock->shouldReceive('complete')
            ->once()
            ->andReturn(['content' => $content, 'input_tokens' => 15, 'output_tokens' => 40]);
    });
}

it('парсва валиден JSON отговор', function () {
    mockClaude(json_encode([
        'title_bg' => 'Верстапен триумфира в Бразилия',
        'summary_bg' => 'Макс Верстапен спечели състезанието в Бразилия. Победата го доближава до титлата.',
        'classification' => 'race',
        'constructor_id' => $this->constructor->id,
        'importance_score' => 4,
    ]));

    $result = app(NewsClassifier::class)->classify($this->item);

    expect($result->titleBg)->toBe('Верстапен триумфира в Бразилия')
        ->and($result->classification)->toBe(NewsClassification::Race)
        ->and($result->constructorId)->toBe($this->constructor->id)
        ->and($result->importanceScore)->toBe(4)
        ->and($result->tokenUsage)->toBe(['input_tokens' => 15, 'output_tokens' => 40]);
});

it('strip-ва code fences и парсва', function () {
    $json = json_encode([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Кратко резюме на новината тук.',
        'classification' => 'technical',
        'constructor_id' => null,
        'importance_score' => 2,
    ]);
    mockClaude("```json\n{$json}\n```");

    $result = app(NewsClassifier::class)->classify($this->item);

    expect($result->classification)->toBe(NewsClassification::Technical)
        ->and($result->constructorId)->toBeNull();
});

it('хвърля LlmException при невалиден JSON', function () {
    mockClaude('това определено не е json');

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при невалидна класификация', function () {
    mockClaude(json_encode([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'gossip',
        'constructor_id' => null,
        'importance_score' => 3,
    ]));

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при несъществуващ constructor_id', function () {
    mockClaude(json_encode([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'constructor_id' => 999999,
        'importance_score' => 3,
    ]));

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при importance извън 1-5', function () {
    mockClaude(json_encode([
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'constructor_id' => null,
        'importance_score' => 9,
    ]));

    expect(fn () => app(NewsClassifier::class)->classify($this->item))
        ->toThrow(LlmException::class);
});
