<?php

declare(strict_types=1);

use App\Services\News\Llm\AnthropicClient;
use App\Services\News\Llm\LlmClient;
use App\Services\News\Llm\MistralClient;

it('резолвва Anthropic драйвера по подразбиране', function () {
    config()->set('news.llm_driver', 'anthropic');

    expect(app(LlmClient::class))->toBeInstanceOf(AnthropicClient::class);
});

it('резолвва Mistral драйвера при news.llm_driver=mistral', function () {
    config()->set('news.llm_driver', 'mistral');

    expect(app(LlmClient::class))->toBeInstanceOf(MistralClient::class);
});

it('хвърля при непознат драйвер', function () {
    config()->set('news.llm_driver', 'openai');

    expect(fn () => app(LlmClient::class))->toThrow(InvalidArgumentException::class);
});
