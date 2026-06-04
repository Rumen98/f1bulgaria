<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

/**
 * Резултат от LLM генерирането на разширена (оригинална) българска статия.
 */
final readonly class NewsArticleContent
{
    /**
     * @param  array<int, string>  $keyFacts
     * @param  array{input_tokens: int, output_tokens: int}  $tokenUsage
     */
    public function __construct(
        public string $fullArticleBg,
        public array $keyFacts,
        public string $analysisBg,
        public array $tokenUsage,
    ) {}
}
