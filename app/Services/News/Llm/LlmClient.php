<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

/**
 * Абстракция над LLM доставчик (Anthropic, Gemini…). Позволява смяна на
 * доставчика чрез конфигурация (news.llm_driver), без промени в потребителите
 * на класа — класификаторът и генераторът на статии зависят само от този
 * интерфейс.
 */
interface LlmClient
{
    /**
     * Свободен текстов отговор.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     *
     * @throws LlmException
     */
    public function complete(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): array;

    /**
     * Структуриран отговор, валиден спрямо подадената JSON Schema.
     *
     * @param  array<string, mixed>  $toolSchema  JSON Schema за резултата
     * @return array{input: array<string, mixed>, input_tokens: int, output_tokens: int}
     *
     * @throws LlmException
     */
    public function completeWithTool(
        string $systemPrompt,
        string $userPrompt,
        string $toolName,
        array $toolSchema,
        int $maxTokens = 2048,
    ): array;
}
