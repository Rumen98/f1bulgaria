<?php

declare(strict_types=1);

use App\Services\News\Llm\AnthropicClient;
use App\Services\News\Llm\LlmException;
use Illuminate\Support\Facades\Http;

const ANTHROPIC_URL = 'https://api.anthropic.com/*';
const TEST_API_KEY = 'sk-ant-test-SECRET-DO-NOT-LEAK';

beforeEach(function () {
    config()->set('services.anthropic.key', TEST_API_KEY);
    config()->set('services.anthropic.model', 'claude-haiku-4-5');
    config()->set('services.anthropic.base_url', 'https://api.anthropic.com/v1');
});

function claudeBody(string $text, int $in = 12, int $out = 34): array
{
    return [
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
    ];
}

it('връща съдържание и брой токени при успешен отговор', function () {
    Http::fake([ANTHROPIC_URL => Http::response(claudeBody('Здравей', 12, 34), 200)]);

    $result = app(AnthropicClient::class)->complete('система', 'въпрос');

    expect($result['content'])->toBe('Здравей')
        ->and($result['input_tokens'])->toBe(12)
        ->and($result['output_tokens'])->toBe(34);
});

it('повтаря при 500 и успява на втория опит', function () {
    Http::fake([
        ANTHROPIC_URL => Http::sequence()
            ->push('', 500)
            ->push(claudeBody('ок'), 200),
    ]);

    $result = app(AnthropicClient::class)->complete('s', 'u');

    expect($result['content'])->toBe('ок');
    Http::assertSentCount(2);
});

it('хвърля LlmException след 3 неуспешни опита (5xx)', function () {
    Http::fake([ANTHROPIC_URL => Http::response('', 500)]);

    expect(fn () => app(AnthropicClient::class)->complete('s', 'u'))
        ->toThrow(LlmException::class);

    Http::assertSentCount(3);
});

it('повтаря при 429 (rate limit)', function () {
    Http::fake([
        ANTHROPIC_URL => Http::sequence()
            ->push('', 429)
            ->push(claudeBody('ок'), 200),
    ]);

    $result = app(AnthropicClient::class)->complete('s', 'u');

    expect($result['content'])->toBe('ок');
    Http::assertSentCount(2);
});

it('не повтаря при 401 (auth грешка) и хвърля веднага', function () {
    Http::fake([ANTHROPIC_URL => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

    expect(fn () => app(AnthropicClient::class)->complete('s', 'u'))
        ->toThrow(LlmException::class);

    Http::assertSentCount(1);
});

it('не включва API ключа в съобщението за грешка', function () {
    Http::fake([ANTHROPIC_URL => Http::response('', 401)]);

    try {
        app(AnthropicClient::class)->complete('s', 'u');
        $this->fail('Очаквахме LlmException');
    } catch (LlmException $e) {
        expect($e->getMessage())->not->toContain(TEST_API_KEY);
    }
});

it('completeWithTool връща input от tool_use блока', function () {
    Http::fake([ANTHROPIC_URL => Http::response([
        'content' => [
            ['type' => 'text', 'text' => 'Ще ползвам инструмента.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'classify_f1_news', 'input' => [
                'title_bg' => 'Заглавие',
                'classification' => 'race',
                'importance_score' => 4,
            ]],
        ],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 25],
    ], 200)]);

    $result = app(AnthropicClient::class)->completeWithTool('система', 'въпрос', 'classify_f1_news', ['type' => 'object']);

    expect($result['input'])->toBe([
        'title_bg' => 'Заглавие',
        'classification' => 'race',
        'importance_score' => 4,
    ])
        ->and($result['input_tokens'])->toBe(50)
        ->and($result['output_tokens'])->toBe(25);
});

it('completeWithTool хвърля LlmException ако няма tool_use блок', function () {
    Http::fake([ANTHROPIC_URL => Http::response([
        'content' => [['type' => 'text', 'text' => 'Само текст, без tool_use.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ], 200)]);

    expect(fn () => app(AnthropicClient::class)->completeWithTool('s', 'u', 'classify_f1_news', ['type' => 'object']))
        ->toThrow(LlmException::class);
});
