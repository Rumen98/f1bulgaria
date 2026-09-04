<?php

declare(strict_types=1);

use App\Services\News\Llm\FallbackLlmClient;
use App\Services\News\Llm\LlmClient;
use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\LlmUnavailableException;

/**
 * Двоен, който брои извикванията и хвърля предварително зададени грешки.
 */
function scriptedClient(array $script, string $label = 'ok'): LlmClient
{
    return new class($script, $label) implements LlmClient
    {
        public int $calls = 0;

        public function __construct(private array $script, private string $label) {}

        public function complete(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): array
        {
            $this->consume();

            return ['content' => $this->label, 'input_tokens' => 1, 'output_tokens' => 1];
        }

        public function completeWithTool(string $systemPrompt, string $userPrompt, string $toolName, array $toolSchema, int $maxTokens = 2048): array
        {
            $this->consume();

            return ['input' => ['from' => $this->label], 'input_tokens' => 1, 'output_tokens' => 1];
        }

        private function consume(): void
        {
            $this->calls++;
            $throw = array_shift($this->script);

            if ($throw instanceof Throwable) {
                throw $throw;
            }
        }
    };
}

it('връща отговора на основния, докато той работи', function () {
    $primary = scriptedClient([], 'основен');
    $fallback = scriptedClient([], 'резервен');

    $result = (new FallbackLlmClient($primary, $fallback, 'mistral', 'anthropic'))
        ->complete('sys', 'user');

    expect($result['content'])->toBe('основен')
        ->and($fallback->calls)->toBe(0);
});

it('пада към резервния, когато основният е недостъпен', function () {
    $primary = scriptedClient([new LlmUnavailableException('403 spряно')], 'основен');
    $fallback = scriptedClient([], 'резервен');

    $result = (new FallbackLlmClient($primary, $fallback, 'mistral', 'anthropic'))
        ->complete('sys', 'user');

    expect($result['content'])->toBe('резервен');
});

it('НЕ пада, когато моделът е отговорил с боклук — отговорът вече е платен', function () {
    $primary = scriptedClient([new LlmException('невалиден JSON')], 'основен');
    $fallback = scriptedClient([], 'резервен');

    $client = new FallbackLlmClient($primary, $fallback, 'mistral', 'anthropic');

    expect(fn () => $client->complete('sys', 'user'))->toThrow(LlmException::class)
        ->and($fallback->calls)->toBe(0);
});

it('отписва основния след ПОСТОЯНЕН отказ, за да не чака всеки елемент', function () {
    // Постоянен отказ = нулева квота/спрян модел. 25 елемента по 2 заявки
    // не бива да чукат по мъртъв доставчик 50 пъти.
    $primary = scriptedClient([new LlmUnavailableException('429 нулева квота')], 'основен');
    $fallback = scriptedClient([], 'резервен');

    $client = new FallbackLlmClient($primary, $fallback, 'mistral', 'anthropic');
    $client->complete('sys', 'едно');
    $client->complete('sys', 'две');
    $client->complete('sys', 'три');

    expect($primary->calls)->toBe(1)
        ->and($fallback->calls)->toBe(3);
});

it('НЕ отписва основния при ВРЕМЕНЕН отказ — иначе един burst мести цялата партида', function () {
    // Точно рискът при безплатен основен и платен резервен: единично
    // задръстване не бива да прехвърли останалите 24 статии на платения.
    $primary = scriptedClient([new LlmUnavailableException('429 задръстване', permanent: false)], 'основен');
    $fallback = scriptedClient([], 'резервен');

    $client = new FallbackLlmClient($primary, $fallback, 'mistral', 'anthropic');
    $client->complete('sys', 'едно');
    $client->complete('sys', 'две');
    $client->complete('sys', 'три');

    expect($primary->calls)->toBe(3)
        ->and($fallback->calls)->toBe(1);
});

it('пада и при completeWithTool — класификаторът минава само оттам', function () {
    $primary = scriptedClient([new LlmUnavailableException('402 без кредит')], 'основен');
    $fallback = scriptedClient([], 'резервен');

    $result = (new FallbackLlmClient($primary, $fallback, 'anthropic', 'mistral'))
        ->completeWithTool('sys', 'user', 'classify', ['type' => 'object']);

    expect($result['input'])->toBe(['from' => 'резервен']);
});
