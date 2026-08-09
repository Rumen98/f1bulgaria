<?php

declare(strict_types=1);

use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\MistralClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const MISTRAL_URL = 'https://api.mistral.ai/*';
const MISTRAL_TEST_KEY = 'mistral-test-SECRET-DO-NOT-LEAK';

beforeEach(function () {
    config()->set('services.mistral.key', MISTRAL_TEST_KEY);
    config()->set('services.mistral.model', 'mistral-small-latest');
    config()->set('services.mistral.base_url', 'https://api.mistral.ai/v1');
});

function mistralBody(string $content, int $in = 12, int $out = 34): array
{
    return [
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
    ];
}

it('връща съдържание и брой токени при успешен отговор', function () {
    Http::fake([MISTRAL_URL => Http::response(mistralBody('Здравей', 12, 34), 200)]);

    $result = app(MistralClient::class)->complete('система', 'въпрос');

    expect($result['content'])->toBe('Здравей')
        ->and($result['input_tokens'])->toBe(12)
        ->and($result['output_tokens'])->toBe(34);
});

it('праща системния промпт като system съобщение', function () {
    Http::fake([MISTRAL_URL => Http::response(mistralBody('ок'), 200)]);

    app(MistralClient::class)->complete('система', 'въпрос');

    Http::assertSent(function (Request $request) {
        $messages = $request->data()['messages'];

        return $messages[0] === ['role' => 'system', 'content' => 'система']
            && $messages[1] === ['role' => 'user', 'content' => 'въпрос']
            && $request->data()['model'] === 'mistral-small-latest';
    });
});

it('повтаря при 500 и успява на втория опит', function () {
    Http::fake([
        MISTRAL_URL => Http::sequence()
            ->push('', 500)
            ->push(mistralBody('ок'), 200),
    ]);

    $result = app(MistralClient::class)->complete('s', 'u');

    expect($result['content'])->toBe('ок');
    Http::assertSentCount(2);
});

it('хвърля LlmException след 3 неуспешни опита (5xx)', function () {
    Http::fake([MISTRAL_URL => Http::response('', 500)]);

    expect(fn () => app(MistralClient::class)->complete('s', 'u'))
        ->toThrow(LlmException::class);

    Http::assertSentCount(3);
});

it('повтаря при 429 (rate limit)', function () {
    Http::fake([
        MISTRAL_URL => Http::sequence()
            ->push('', 429)
            ->push(mistralBody('ок'), 200),
    ]);

    $result = app(MistralClient::class)->complete('s', 'u');

    expect($result['content'])->toBe('ок');
    Http::assertSentCount(2);
});

it('не повтаря при 401 (auth грешка) и хвърля веднага', function () {
    Http::fake([MISTRAL_URL => Http::response(['message' => 'Unauthorized'], 401)]);

    expect(fn () => app(MistralClient::class)->complete('s', 'u'))
        ->toThrow(LlmException::class);

    Http::assertSentCount(1);
});

it('не включва API ключа в съобщението за грешка', function () {
    Http::fake([MISTRAL_URL => Http::response('', 401)]);

    try {
        app(MistralClient::class)->complete('s', 'u');
        $this->fail('Очаквахме LlmException');
    } catch (LlmException $e) {
        expect($e->getMessage())->not->toContain(MISTRAL_TEST_KEY);
    }
});

it('хвърля LlmException при липсващ API ключ, без да праща заявка', function () {
    config()->set('services.mistral.key', null);
    Http::fake();

    expect(fn () => app(MistralClient::class)->complete('s', 'u'))
        ->toThrow(LlmException::class);

    Http::assertNothingSent();
});

it('completeWithTool декодира JSON от message.content', function () {
    $json = json_encode([
        'title_bg' => 'Заглавие',
        'classification' => 'race',
        'importance_score' => 4,
    ], JSON_UNESCAPED_UNICODE);

    Http::fake([MISTRAL_URL => Http::response(mistralBody($json, 50, 25), 200)]);

    $result = app(MistralClient::class)->completeWithTool('система', 'въпрос', 'classify_f1_news', ['type' => 'object']);

    expect($result['input'])->toBe([
        'title_bg' => 'Заглавие',
        'classification' => 'race',
        'importance_score' => 4,
    ])
        ->and($result['input_tokens'])->toBe(50)
        ->and($result['output_tokens'])->toBe(25);
});

it('completeWithTool праща strict json_schema с нормализирана схема', function () {
    Http::fake([MISTRAL_URL => Http::response(mistralBody('{}'), 200)]);

    app(MistralClient::class)->completeWithTool('s', 'u', 'classify_f1_news', [
        'type' => 'object',
        'properties' => [
            'title_bg' => ['type' => 'string', 'maxLength' => 120],
            'constructor_id' => ['type' => ['integer', 'null']],
        ],
        'required' => ['title_bg'],
    ]);

    Http::assertSent(function (Request $request) {
        $format = $request->data()['response_format'];
        $schema = $format['json_schema']['schema'];

        return $format['type'] === 'json_schema'
            && $format['json_schema']['name'] === 'classify_f1_news'
            && $format['json_schema']['strict'] === true
            && $schema['additionalProperties'] === false
            // type: ['integer', 'null'] е нормализиран до anyOf.
            && $schema['properties']['constructor_id'] === ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
    });
});

it('completeWithTool хвърля LlmException при невалиден JSON в отговора', function () {
    Http::fake([MISTRAL_URL => Http::response(mistralBody('това не е JSON'), 200)]);

    expect(fn () => app(MistralClient::class)->completeWithTool('s', 'u', 'classify_f1_news', ['type' => 'object']))
        ->toThrow(LlmException::class);
});

it('completeWithTool хвърля LlmException при JSON, който не е обект', function () {
    Http::fake([MISTRAL_URL => Http::response(mistralBody('"просто string"'), 200)]);

    expect(fn () => app(MistralClient::class)->completeWithTool('s', 'u', 'classify_f1_news', ['type' => 'object']))
        ->toThrow(LlmException::class);
});

it('хвърля LlmException при празно съдържание в отговора', function () {
    Http::fake([MISTRAL_URL => Http::response(['choices' => [], 'usage' => []], 200)]);

    expect(fn () => app(MistralClient::class)->complete('s', 'u'))
        ->toThrow(LlmException::class);
});
