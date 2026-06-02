<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use App\Enums\NewsClassification;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\Log;
use JsonException;

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
            2048,
        );

        $data = $this->decode($response['content'], $item);

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
     * Декодира JSON от отговора. Толерира преамбюл/епилог и ```json``` обвивка.
     * При неуспех логва суровия отговор за диагностика и хвърля LlmException.
     *
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function decode(string $content, TeamNewsItem $item): array
    {
        $json = $this->extractJson($content);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logParseFailure($item, $content, $e->getMessage());

            throw new LlmException('LLM не върна валиден JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            $this->logParseFailure($item, $content, 'Декодираната стойност не е JSON обект.');

            throw new LlmException('LLM не върна валиден JSON.');
        }

        return $decoded;
    }

    /**
     * Изважда JSON обекта от суров LLM отговор. Първо опитва най-агресивно —
     * substring-а между първото "{" и последното "}" (така отпадат преамбюл,
     * епилог и ```json``` огради). Ако няма скоби — пада до изчистване на огради.
     */
    private function extractJson(string $content): string
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start !== false && $end !== false && $end >= $start) {
            return substr($content, $start, $end - $start + 1);
        }

        // Fallback: изчистване на code fences.
        $raw = trim($content);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);

        return (string) preg_replace('/\s*```$/', '', (string) $raw);
    }

    private function logParseFailure(TeamNewsItem $item, string $rawResponse, string $parseError): void
    {
        Log::warning('LLM parse failure', [
            'item_id' => $item->id,
            'raw_response' => $rawResponse,
            'parse_error' => $parseError,
        ]);
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
