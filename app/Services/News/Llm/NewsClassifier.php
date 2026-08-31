<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use App\Models\TeamNewsItem;
use Illuminate\Support\Facades\Log;

/**
 * Класифицира и превежда една новина чрез LLM: заглавие/резюме на български,
 * категория, обвързан отбор и оценка за важност. Валидира отговора стриктно.
 */
class NewsClassifier
{
    private const TOOL_NAME = 'classify_f1_news';

    private const ARTICLE_TOOL_NAME = 'write_f1_article';

    public function __construct(private readonly LlmClient $client) {}

    /**
     * Генерира НАША оригинална българска статия по фактите от новината
     * (4-6 параграфа), плюс ключови факти и собствен анализ. Forced tool use.
     *
     * @param  string|null  $sourceText  Пълният текст на оригинала (ако е наличен) —
     *                                   дава реални факти/числа/резултати отвъд RSS откъса.
     *
     * @throws LlmException
     */
    public function generateFullArticle(TeamNewsItem $item, ?string $sourceText = null): NewsArticleContent
    {
        $response = $this->client->completeWithTool(
            (string) config('news.full_article_system_prompt'),
            $this->buildArticlePrompt($item, $sourceText),
            self::ARTICLE_TOOL_NAME,
            $this->articleToolSchema(),
            4096,
        );

        return $this->validateArticle($response['input'], $response);
    }

    /**
     * @throws LlmException
     */
    public function classify(TeamNewsItem $item): NewsClassificationResult
    {
        $season = Season::current();

        // Forced tool use → API-то гарантира структуриран input спрямо схемата,
        // което елиминира JSON parsing проблемите при свободен текст.
        $response = $this->client->completeWithTool(
            (string) config('news.classifier_system_prompt'),
            $this->buildUserPrompt($item, $season),
            self::TOOL_NAME,
            $this->toolSchema(),
            2048,
        );

        // Схемата гарантира типовете; валидираме само бизнес правилата.
        return $this->validate($response['input'], $response);
    }

    /**
     * JSON Schema за tool input-а. Изброените типове се гарантират от API-то;
     * бизнес валидността (реален constructor_id) се проверява в validate().
     *
     * @return array<string, mixed>
     */
    private function toolSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title_bg' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Заглавие на български'],
                'summary_bg' => ['type' => 'string', 'maxLength' => 400, 'description' => 'Резюме на български, 2-3 изречения'],
                'classification' => [
                    'type' => 'string',
                    'enum' => ['race', 'driver', 'technical', 'rumor', 'business', 'other'],
                ],
                'constructor_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'ID на конкретен отбор или null. САМО по подадения списък пилот→отбор, не по общи знания.',
                ],
                'importance_score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'is_f1_related' => [
                    'type' => 'boolean',
                    'description' => 'true САМО ако новината е за Формула 1, Формула 2/Формула 3 или български автомобилен спорт. MotoGP, Moto2, NASCAR, IndyCar, WEC, Формула E, рали/WRC, DTM и всякакви други серии → false.',
                ],
                'duplicate_of_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'ID на скорошна новина, отразяваща СЪЩАТА история (не просто същата тема), или null.',
                ],
            ],
            'required' => ['title_bg', 'summary_bg', 'classification', 'importance_score', 'is_f1_related'],
        ];
    }

    private function buildUserPrompt(TeamNewsItem $item, ?Season $season): string
    {
        $constructors = $season
            ? $season->constructors()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Constructor $c) => "- {$c->id}: {$c->name}")->implode("\n")
            : '(няма данни)';

        // С отбора на всеки пилот — иначе LLM-ът посяга към остарялата си
        // памет (напр. „Хамилтън → Mercedes" от предишните му сезони).
        $drivers = $season
            ? $season->drivers()->with('constructor')->orderBy('last_name')->get()
                ->map(fn (Driver $d) => '- '.$d->fullName().' ('.($d->constructor?->name ?? 'без отбор').')')->implode("\n")
            : '(няма данни)';

        $recent = $this->recentTitles($item);

        return <<<PROMPT
            Конструктори за сезона (id: име):
            {$constructors}

            Пилоти за ТЕКУЩИЯ сезон (пилот → отбор). Обвързвай отбора САМО по този списък:
            {$drivers}

            Скорошни новини на сайта (id: заглавие). Ако новината по-долу отразява СЪЩАТА
            история като някоя от тях (същото събитие, не просто същата тема) — върни
            duplicate_of_id с нейния ID, иначе null:
            {$recent}

            Новина за класификация:
            Заглавие: {$item->title_original}
            Описание: {$item->content_snippet}
            PROMPT;
    }

    /**
     * Заглавията от последните 72 часа (без текущата новина) — контекст за
     * крос-източникова дедупликация.
     */
    private function recentTitles(TeamNewsItem $item): string
    {
        $titles = TeamNewsItem::query()
            ->whereKeyNot($item->id)
            ->where('published_at', '>=', now()->subHours(72))
            ->whereNotIn('status', [NewsStatus::Rejected->value])
            ->orderByDesc('published_at')
            ->limit(25)
            ->get(['id', 'title_bg', 'title_original'])
            ->map(fn (TeamNewsItem $recent) => "- {$recent->id}: ".($recent->title_bg ?? $recent->title_original))
            ->implode("\n");

        return $titles !== '' ? $titles : '(няма скорошни новини)';
    }

    /**
     * JSON Schema за разширената статия. Типовете се гарантират от API-то.
     *
     * @return array<string, mixed>
     */
    private function articleToolSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'full_article_bg' => ['type' => 'string', 'description' => 'Оригинална българска статия, 4-6 параграфа, разделени с празен ред'],
                'key_facts' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '3-5 кратки ключови факта',
                ],
                'our_analysis_bg' => ['type' => 'string', 'description' => '2-3 параграфа собствен анализ (добавена стойност)'],
            ],
            'required' => ['full_article_bg', 'key_facts', 'our_analysis_bg'],
        ];
    }

    private function buildArticlePrompt(TeamNewsItem $item, ?string $sourceText = null): string
    {
        $context = $item->title_bg
            ? "Вече подготвен български контекст:\nЗаглавие: {$item->title_bg}\nРезюме: {$item->summary_bg}\n\n"
            : '';

        $source = $sourceText !== null
            ? "\n\nПълен текст на оригиналната статия — извлечи всички конкретни факти, числа, класирания и резултати (не копирай изречения):\n{$sourceText}"
            : '';

        return <<<PROMPT
            {$context}Оригинална новина (английски) — ползвай я САМО за фактите, не копирай изреченията:
            Заглавие: {$item->title_original}
            Описание: {$item->content_snippet}{$source}
            PROMPT;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{input: array<string, mixed>, input_tokens: int, output_tokens: int}  $response
     *
     * @throws LlmException
     */
    private function validateArticle(array $data, array $response): NewsArticleContent
    {
        $article = trim((string) ($data['full_article_bg'] ?? ''));
        $analysis = trim((string) ($data['our_analysis_bg'] ?? ''));

        if ($article === '') {
            throw new LlmException('LLM върна празна статия.');
        }

        $keyFacts = array_values(array_filter(array_map(
            fn ($fact) => trim((string) $fact),
            (array) ($data['key_facts'] ?? []),
        )));

        return new NewsArticleContent(
            fullArticleBg: $article,
            keyFacts: $keyFacts,
            analysisBg: $analysis,
            tokenUsage: [
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{input: array<string, mixed>, input_tokens: int, output_tokens: int}  $response
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

        // По подразбиране true: липсваща стойност от по-слаб модел не бива
        // да изтрива легитимна F1 новина.
        $isF1Related = (bool) ($data['is_f1_related'] ?? true);

        // Толерантно: невалиден duplicate_of_id не проваля обогатяването —
        // просто не третираме новината като дубликат.
        $duplicateOfId = $data['duplicate_of_id'] ?? null;

        if ($duplicateOfId !== null) {
            $duplicateOfId = (int) $duplicateOfId;

            if (! TeamNewsItem::query()->whereKey($duplicateOfId)->exists()) {
                Log::warning("LLM върна несъществуващ duplicate_of_id: {$duplicateOfId}");
                $duplicateOfId = null;
            }
        }

        return new NewsClassificationResult(
            titleBg: $titleBg,
            summaryBg: $summaryBg,
            classification: $classification,
            constructorId: $constructorId,
            importanceScore: $importance,
            rawResponse: json_encode($data, JSON_UNESCAPED_UNICODE) ?: '',
            tokenUsage: [
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
            ],
            duplicateOfId: $duplicateOfId,
            isF1Related: $isF1Related,
        );
    }
}
