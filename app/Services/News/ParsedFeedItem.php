<?php

declare(strict_types=1);

namespace App\Services\News;

use Carbon\CarbonImmutable;

/**
 * Прост, неизменим DTO за един елемент, извлечен от RSS/Atom feed.
 * Все още суров — без LLM обработка (заглавие/резюме на български идват по-късно).
 */
final readonly class ParsedFeedItem
{
    public function __construct(
        public string $externalUrl,
        public ?string $externalGuid,
        public string $titleOriginal,
        public ?string $contentSnippet,
        public CarbonImmutable $publishedAt,
    ) {}
}
