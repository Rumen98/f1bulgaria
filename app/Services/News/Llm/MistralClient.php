<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Тънък HTTP wrapper над Mistral Chat Completions API (без официален SDK).
 * Безплатната алтернатива на Anthropic драйвера — La Plateforme Experiment
 * tier (~1 млрд. токена/месец, 1 заявка/сек, без карта).
 *
 * Структурираният отговор използва response_format: json_schema със strict
 * режим — еквивалентът на forced tool use при Anthropic. Retry: до 3 опита
 * с exponential backoff само за 5xx/429/мрежови грешки; 4xx се хвърля
 * веднага. API ключът никога не се включва в съобщения за грешки.
 *
 * @see https://docs.mistral.ai/api/
 * @see https://docs.mistral.ai/studio-api/conversations/structured-output/custom
 */
class MistralClient implements LlmClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private const MAX_ATTEMPTS = 3;

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
            'messages' => $this->messages($systemPrompt, $userPrompt),
        ]);

        return [
            'content' => $this->extractContent($data),
            'input_tokens' => (int) data_get($data, 'usage.prompt_tokens', 0),
            'output_tokens' => (int) data_get($data, 'usage.completion_tokens', 0),
        ];
    }

    /**
     * Структуриран отговор чрез json_schema (strict) — API-то връща в
     * message.content JSON string, валиден спрямо подадената схема.
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
    ): array {
        $data = $this->dispatch([
            'max_tokens' => $maxTokens,
            'messages' => $this->messages($systemPrompt, $userPrompt),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $toolName,
                    'strict' => true,
                    'schema' => $this->normalizeSchema($toolSchema),
                ],
            ],
        ]);

        $content = $this->extractContent($data);

        try {
            $input = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // При finish_reason=length JSON-ът е отрязан — включваме причината
            // в съобщението, иначе грешката в лога е неразгадаема.
            $finishReason = (string) data_get($data, 'choices.0.finish_reason', 'unknown');

            throw new LlmException(
                "Mistral API не върна валиден JSON за '{$toolName}' (finish_reason: {$finishReason}).",
                previous: $e,
            );
        }

        if (! is_array($input)) {
            throw new LlmException("Mistral API върна JSON, който не е обект, за '{$toolName}'.");
        }

        return [
            'input' => $input,
            'input_tokens' => (int) data_get($data, 'usage.prompt_tokens', 0),
            'output_tokens' => (int) data_get($data, 'usage.completion_tokens', 0),
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(string $systemPrompt, string $userPrompt): array
    {
        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Привежда JSON Schema към формата, който Mistral strict режимът очаква:
     * additionalProperties: false на всеки object и anyOf вместо type-масиви
     * с "null" (формата, която Pydantic/Zod генерират — гарантирано поддържана).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;

            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (is_array($property)) {
                    $schema['properties'][$name] = $this->normalizeSchema($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalizeSchema($schema['items']);
        }

        // ['integer', 'null'] → anyOf: [{type: integer}, {type: 'null'}]
        if (is_array($schema['type'] ?? null)) {
            $schema['anyOf'] = array_map(fn (string $type) => ['type' => $type], $schema['type']);
            unset($schema['type']);
        }

        return $schema;
    }

    /**
     * Текстовото съдържание на първия choice.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LlmException
     */
    private function extractContent(array $data): string
    {
        $content = data_get($data, 'choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new LlmException('Неочакван отговор от Mistral API (липсва текстово съдържание).');
        }

        return $content;
    }

    /**
     * Изпраща заявка към /chat/completions с retry/backoff и връща декодирания JSON.
     *
     * @param  array<string, mixed>  $payload  тяло без `model` (добавя се тук)
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function dispatch(array $payload): array
    {
        /** @var array{key:?string, model:string, base_url:string, timeout?:int} $config */
        $config = config('services.mistral');

        if (blank($config['key'])) {
            throw new LlmException('Липсва MISTRAL_API_KEY в конфигурацията.');
        }

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withToken($config['key'])
                ->acceptJson()
                ->timeout((int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS))
                ->retry(self::MAX_ATTEMPTS, $this->backoff(...), $this->shouldRetry(...), throw: true)
                ->post('/chat/completions', [...$payload, 'model' => $config['model']]);
        } catch (RequestException $e) {
            // Статус + съобщението на API-то — без ключа/хедърите. Mistral
            // връща грешката в "message" (понякога вложено в "detail").
            $json = $e->response->json();
            $apiMessage = data_get($json, 'message') ?? data_get($json, 'detail.0.msg', '');
            $detail = is_string($apiMessage) && $apiMessage !== '' ? ' '.Str::limit($apiMessage, 200) : '';

            throw new LlmException("Mistral API върна {$e->response->status()}.{$detail}", previous: $e);
        } catch (ConnectionException) {
            throw new LlmException('Мрежова грешка при връзка с Mistral API.');
        }

        return (array) $response->json();
    }

    /**
     * Изчакване преди следващия опит — по-дълго при 429 (rate limit; free
     * tier-ът е ограничен до 1 заявка/сек).
     */
    private function backoff(int $attempt, Throwable $exception): int
    {
        $rateLimited = $exception instanceof RequestException
            && $exception->response->status() === 429;

        $base = $rateLimited ? 1500 : 250;

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
