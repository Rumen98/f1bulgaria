<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use Throwable;

/**
 * Доставчикът не е на разположение: липсващ ключ, отхвърлена автентикация,
 * изчерпан кредит, нулева квота за модела, паднало API или мрежова грешка.
 *
 * Ключовото за fallback-а: нищо не е генерирано и нищо не е таксувано, така
 * че същата заявка може да се повтори през резервния доставчик без двойно
 * плащане. Останалите LlmException-и (моделът е отговорил, но с невалиден
 * JSON или без tool_use блок) НЕ са от този тип — там изходните токени вече
 * са платени и повтарянето би било втора поръчка на същото нещо.
 */
class LlmUnavailableException extends LlmException
{
    /**
     * @param  bool  $permanent  true = доставчикът няма да се оправи сам в
     *                           рамките на това пускане (невалиден ключ,
     *                           нулева квота, спрян модел). false = временно
     *                           (задръстване, 5xx, мрежа) — падаме за тази
     *                           заявка, но не отписваме доставчика за цялата
     *                           партида.
     */
    public function __construct(
        string $message,
        private readonly bool $permanent = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
