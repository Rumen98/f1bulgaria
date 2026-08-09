<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\LlmClient;
use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\NewsClassifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

/*
|--------------------------------------------------------------------------
| Contract тестове през реалния Mistral драйвер
|--------------------------------------------------------------------------
| Реалните схеми на класификатора минават през MistralClient::normalizeSchema
| — тук се заковава какво реално тръгва по жицата, за да не може регресия в
| нормализацията или в схемите да мине незабелязано (Http::fake не валидира).
*/

function useMistralDriver(): void
{
    config()->set('news.llm_driver', 'mistral');
    config()->set('services.mistral.key', 'test-key');
    config()->set('services.mistral.model', 'mistral-large-latest');
    config()->set('services.mistral.base_url', 'https://api.mistral.ai/v1');
}

/**
 * @param  array<string, mixed>  $input
 */
function fakeMistralToolResponse(array $input): void
{
    Http::fake(['https://api.mistral.ai/*' => Http::response([
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => json_encode($input, JSON_UNESCAPED_UNICODE),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
    ], 200)]);
}

it('праща реалната схема за класификация нормализирана през Mistral драйвера', function () {
    useMistralDriver();

    $driver = Driver::factory()->create([
        'season_id' => $this->season->id,
        'constructor_id' => $this->constructor->id,
    ]);

    fakeMistralToolResponse([
        'title_bg' => 'Верстапен триумфира',
        'summary_bg' => 'Макс Верстапен спечели. Победата е важна.',
        'classification' => 'race',
        'constructor_id' => null,
        'importance_score' => 3,
        'duplicate_of_id' => null,
    ]);

    $result = app(NewsClassifier::class)->classify($this->item);

    expect($result->classification)->toBe(NewsClassification::Race);

    Http::assertSent(function (Request $request) use ($driver) {
        $format = data_get($request->data(), 'response_format');
        $schema = $format['json_schema']['schema'];

        return $request->url() === 'https://api.mistral.ai/v1/chat/completions'
            && $format['json_schema']['name'] === 'classify_f1_news'
            && $format['json_schema']['strict'] === true
            && $schema['additionalProperties'] === false
            // Union полетата (['integer','null']) са разгънати до anyOf.
            && isset($schema['properties']['constructor_id']['anyOf'])
            && isset($schema['properties']['duplicate_of_id']['anyOf'])
            && ! isset($schema['properties']['constructor_id']['type'])
            && $schema['properties']['classification']['enum'] === ['race', 'driver', 'technical', 'rumor', 'business', 'other']
            // Анти-халюцинация защитата: списъкът пилот→отбор е в промпта.
            && str_contains((string) data_get($request->data(), 'messages.1.content'), $driver->fullName());
    });
});

it('праща реалната схема за статия през Mistral с непокътната items рекурсия', function () {
    useMistralDriver();

    fakeMistralToolResponse([
        'full_article_bg' => "Първи параграф.\n\nВтори параграф.",
        'key_facts' => ['Факт 1', 'Факт 2', 'Факт 3'],
        'our_analysis_bg' => 'Анализ.',
    ]);

    $content = app(NewsClassifier::class)->generateFullArticle($this->item);

    expect($content->keyFacts)->toBe(['Факт 1', 'Факт 2', 'Факт 3']);

    Http::assertSent(function (Request $request) {
        $format = data_get($request->data(), 'response_format');
        $schema = $format['json_schema']['schema'];

        return $format['json_schema']['name'] === 'write_f1_article'
            && $format['json_schema']['strict'] === true
            && $schema['additionalProperties'] === false
            && $schema['properties']['key_facts']['items']['type'] === 'string';
    });
});
