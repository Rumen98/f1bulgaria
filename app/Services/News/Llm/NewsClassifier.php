<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use App\Enums\NewsClassification;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use App\Models\TeamNewsItem;

/**
 * Класифицира и превежда една новина чрез Claude: заглавие/резюме на български,
 * категория, обвързан отбор и оценка за важност. Валидира отговора стриктно.
 */
class NewsClassifier
{
    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @throws LlmException
     */
    public function classify(TeamNewsItem $item): NewsClassificationResult
    {
        $season = Season::current();

        $response = $this->client->complete(
            (string) config('news.classifier_system_prompt'),
            $this->buildUserPrompt($item, $season),
        );

        $data = $this->decode($response['content']);

        return $this->validate($data, $response);
    }

    private function buildUserPrompt(TeamNewsItem $item, ?Season $season): string
    {
        $constructors = $season
            ? $season->constructors()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Constructor $c) => "- {$c->id}: {$c->name}")->implode("\n")
            : '(няма данни)';

        $drivers = $season
            ? $season->drivers()->orderBy('last_name')->get()
                ->map(fn (Driver $d) => "- {$d->fullName()}")->implode("\n")
            : '(няма данни)';

        return <<<PROMPT
            Конструктори за сезона (id: име):
            {$constructors}

            Пилоти за сезона:
            {$drivers}

            Новина за класификация:
            Заглавие: {$item->title_original}
            Описание: {$item->content_snippet}
            PROMPT;
    }

    /**
     * Декодира JSON, толерирайки ```json ... ``` обвивка.
     *
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function decode(string $content): array
    {
        $raw = trim($content);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', (string) $raw);

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            throw new LlmException('LLM не върна валиден JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{content: string, input_tokens: int, output_tokens: int}  $response
     *
     * @throws LlmException
     */
    private function validate(array $data, array $response): NewsClassificationResult
    {
        $titleBg = trim((string) ($data['title_bg'] ?? ''));
        $summaryBg = trim((string) ($data['summary_bg'] ?? ''));

        if ($titleBg === '' || $summaryBg === '') {
            throw new LlmException('LLM върна празно заглавие или резюме.');
        }

        $classification = NewsClassification::tryFrom((string) ($data['classification'] ?? ''));

        if ($classification === null) {
            throw new LlmException('LLM върна невалидна класификация: '.($data['classification'] ?? 'null'));
        }

        $constructorId = $data['constructor_id'] ?? null;

        if ($constructorId !== null) {
            $constructorId = (int) $constructorId;

            if (! Constructor::query()->whereKey($constructorId)->exists()) {
                throw new LlmException("LLM върна несъществуващ constructor_id: {$constructorId}");
            }
        }

        $importance = (int) ($data['importance_score'] ?? 0);

        if ($importance < 1 || $importance > 5) {
            throw new LlmException("LLM върна importance_score извън диапазона 1-5: {$importance}");
        }

        return new NewsClassificationResult(
            titleBg: $titleBg,
            summaryBg: $summaryBg,
            classification: $classification,
            constructorId: $constructorId,
            importanceScore: $importance,
            rawResponse: $response['content'],
            tokenUsage: [
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
            ],
        );
    }
}
