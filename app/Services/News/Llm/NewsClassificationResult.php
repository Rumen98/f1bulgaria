<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use App\Enums\NewsClassification;

/**
 * Резултат от LLM класификацията на една новина.
 */
final readonly class NewsClassificationResult
{
    /**
     * @param  array{input_tokens: int, output_tokens: int}  $tokenUsage
     */
    public function __construct(
        public string $titleBg,
        public string $summaryBg,
        public NewsClassification $classification,
        public ?int $constructorId,
        public int $importanceScore,
        public string $rawResponse,
        public array $tokenUsage,
        /** ID на скорошна новина със СЪЩАТА история (крос-източников дубликат). */
        public ?int $duplicateOfId = null,
        /**
         * Новината за Формула 1/2/3 или български автомобилен спорт ли е.
         * false → не влиза на сайта (глобалните RSS емисии носят и MotoGP,
         * NASCAR, WEC и рали, които не са тема на Падок).
         */
        public bool $isF1Related = true,
    ) {}
}
