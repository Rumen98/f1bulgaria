<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Тънък HTTP wrapper над Anthropic Messages API (без официален SDK).
 *
 * Retry: до 3 опита с exponential backoff само за 5xx/429/мрежови грешки;
 * 4xx (напр. 401) се хвърля веднага без повтаряне. API ключът никога не се
 * включва в съобщения за грешки.
 *
 * @see https://docs.anthropic.com/en/api/messages
 * @see https://docs.anthropic.com/en/docs/build-with-claude/tool-use
 */
class AnthropicClient
{
    private const TIMEOUT_SECONDS = 30;

    private const MAX_ATTEMPTS = 3;

    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Свободен текстов отговор.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     *
     * @throws LlmException
     */
    public function complete(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): array
    {
        $data = $this->dispatch([
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        $content = data_get($data, 'content.0.text');

        if (! is_string($content) || $content === '') {
            throw new LlmException('Неочакван отговор от Anthropic API (липсва текстово съдържание).');
        }

        return [
            'content' => $content,
            'input_tokens' => (int) data_get($data, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($data, 'usage.output_tokens', 0),
        ];
    }

    /**
     * Структуриран отговор чрез forced tool use — API-то гарантирано връща
     * tool_use блок с `input`, валиден спрямо подадената JSON Schema. Това
     * елиминира JSON parsing проблемите при свободен текст.
     *
     * @param  array<string, mixed>  $toolSchema  JSON Schema за input-а
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
    ): array {
        $data = $this->dispatch([
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'tools' => [[
                'name' => $toolName,
                'description' => 'Връща структуриран резултат според схемата.',
                'input_schema' => $toolSchema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ]);

        foreach (data_get($data, 'content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                return [
                    'input' => (array) ($block['input'] ?? []),
                    'input_tokens' => (int) data_get($data, 'usage.input_tokens', 0),
                    'output_tokens' => (int) data_get($data, 'usage.output_tokens', 0),
                ];
            }
        }

        throw new LlmException("Anthropic API не върна tool_use блок за '{$toolName}'.");
    }

    /**
     * Изпраща заявка към /messages с retry/backoff и връща декодирания JSON.
     *
     * @param  array<string, mixed>  $payload  тяло без `model` (добавя се тук)
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function dispatch(array $payload): array
    {
        /** @var array{key:?string, model:string, base_url:string} $config */
        $config = config('services.anthropic');

        if (blank($config['key'])) {
            throw new LlmException('Липсва ANTHROPIC_API_KEY в конфигурацията.');
        }

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders([
                    'x-api-key' => $config['key'],
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(self::MAX_ATTEMPTS, $this->backoff(...), $this->shouldRetry(...), throw: true)
                ->post('/messages', [...$payload, 'model' => $config['model']]);
        } catch (RequestException $e) {
            // Само статусът — без ключа/хедърите.
            throw new LlmException("Anthropic API върна {$e->response->status()}.", previous: $e);
        } catch (ConnectionException) {
            throw new LlmException('Мрежова грешка при връзка с Anthropic API.');
        }

        return (array) $response->json();
    }

    /**
     * Изчакване преди следващия опит — по-дълго при 429 (rate limit).
     */
    private function backoff(int $attempt, Throwable $exception): int
    {
        $rateLimited = $exception instanceof RequestException
            && $exception->response->status() === 429;

        $base = $rateLimited ? 1000 : 250;

        return $base * (2 ** ($attempt - 1));
    }

    /**
     * Повтаряме само при мрежови грешки и 5xx/429 — не и при други 4xx.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->serverError() || $exception->response->status() === 429);
    }
}
