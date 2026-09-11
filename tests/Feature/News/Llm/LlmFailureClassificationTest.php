<?php

declare(strict_types=1);

use App\Services\News\Llm\AnthropicClient;
use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\LlmUnavailableException;
use App\Services\News\Llm\MistralClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.anthropic.key' => 'test-key',
        'services.anthropic.model' => 'claude-sonnet-4-6',
        'services.anthropic.base_url' => 'https://api.anthropic.com/v1',
        'services.mistral.key' => 'test-key',
        'services.mistral.model' => 'ministral-14b-latest',
        'services.mistral.base_url' => 'https://api.mistral.ai/v1',
    ]);
});

it('Mistral: 429 с нулев минутен лимит е ПОСТОЯНЕН отказ, без повтаряне', function () {
    // Формата, която аварията от 03.09 прие: тарифата отпуска нула заявки
    // за модела. Повтарянето никога няма да помогне.
    Http::fake(['*' => Http::response(['message' => 'Rate limit exceeded'], 429, [
        'x-ratelimit-limit-req-minute' => '0',
    ])]);

    expect(fn () => (new MistralClient)->complete('sys', 'user'))
        ->toThrow(LlmUnavailableException::class);

    Http::assertSentCount(1);
});

it('Mistral: нечислов rate-limit хедър НЕ значи нулева квота', function () {
    // Пазачът е is_numeric, а не само cast: (int) "unlimited" също е 0 и без
    // него всеки нечислов хедър би изглеждал като изчерпана квота.
    Http::fake(['*' => Http::response(['message' => 'Rate limit exceeded'], 429, [
        'x-ratelimit-limit-req-minute' => 'unlimited',
    ])]);

    try {
        (new MistralClient)->complete('sys', 'user');
    } catch (LlmUnavailableException $e) {
        expect($e->isPermanent())->toBeFalse();
    }

    // Повторило се е — значи не е било сметнато за нулева квота.
    Http::assertSentCount(3);
});

it('Mistral: 403 „не е в тарифата" е постоянен отказ', function () {
    Http::fake(['*' => Http::response(['message' => 'This model is not available in your subscription tier'], 403)]);

    try {
        (new MistralClient)->complete('sys', 'user');
        expect(false)->toBeTrue();
    } catch (LlmUnavailableException $e) {
        expect($e->isPermanent())->toBeTrue();
    }
});

it('Anthropic: 402 без кредит е постоянен отказ — иначе платеният никога не пада', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Payment required']], 402)]);

    try {
        (new AnthropicClient)->complete('sys', 'user');
        expect(false)->toBeTrue();
    } catch (LlmUnavailableException $e) {
        expect($e->isPermanent())->toBeTrue();
    }
});

it('Anthropic: 400 за изчерпан баланс е постоянен отказ', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Your credit balance is too low']], 400)]);

    try {
        (new AnthropicClient)->complete('sys', 'user');
        expect(false)->toBeTrue();
    } catch (LlmUnavailableException $e) {
        expect($e->isPermanent())->toBeTrue();
    }
});

it('Anthropic: 400 за сгрешена заявка НЕ е отказ на доставчика', function () {
    // Смяната на доставчик не поправя наша грешка в заявката — и не бива да
    // харчи втори път за нея.
    Http::fake(['*' => Http::response(['error' => ['message' => 'max_tokens must be positive']], 400)]);

    $thrown = null;

    try {
        (new AnthropicClient)->complete('sys', 'user');
    } catch (LlmException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(LlmException::class)
        ->and($thrown)->not->toBeInstanceOf(LlmUnavailableException::class);
});

it('липсващ ключ е отказ на доставчика, не обикновена грешка', function () {
    config(['services.mistral.key' => null]);

    expect(fn () => (new MistralClient)->complete('sys', 'user'))
        ->toThrow(LlmUnavailableException::class);
});
