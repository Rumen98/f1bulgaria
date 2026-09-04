<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Декоратор над LlmClient: изпълнява заявката през основния доставчик и —
 * само ако той е недостъпен — я повтаря веднъж през резервния.
 *
 * Пада се САМО при LlmUnavailableException, тоест когато нищо не е
 * генерирано и нищо не е платено. Ако моделът е отговорил с невалиден JSON,
 * отговорът е таксуван и грешката се вдига нагоре без втори опит — иначе
 * бихме поръчали и платили едно и също нещо два пъти.
 *
 * ОТПИСВАНЕ НА ОСНОВНИЯ: само при ПОСТОЯНЕН провал (невалиден ключ, изчерпан
 * кредит, нулева квота за модела). news:enrich минава през 25 елемента по
 * две заявки и няма смисъл всеки да чака собствен кръг към мъртъв доставчик.
 * При ВРЕМЕНЕН провал (задръстване, 5xx, мрежа) падаме само за конкретната
 * заявка и не отписваме — иначе един burst на безплатната тарифа би
 * преместил цялата останала партида върху платения резервен доставчик тихо.
 *
 * Отписването е за живота на инстанцията, а binding-ът е transient — всяка
 * следваща artisan команда пробва основния наново, тоест възстановяването е
 * автоматично и без състояние в базата.
 *
 * ОБХВАТ: декораторът важи за всеки консуматор на LlmClient — освен новините
 * това е и App\Services\Quiz\QuizQuestionGenerator. Куизът върви в отделен
 * процес със собствено отписване.
 */
class FallbackLlmClient implements LlmClient
{
    /** Причината, поради която основният е отписан за този процес (null = още се пробва). */
    private ?string $primaryDisabled = null;

    public function __construct(
        private readonly LlmClient $primary,
        private readonly LlmClient $fallback,
        private readonly string $primaryName,
        private readonly string $fallbackName,
    ) {}

    /**
     * @return array{content: string, input_tokens: int, output_tokens: int}
     *
     * @throws LlmException
     */
    public function complete(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): array
    {
        return $this->attempt(
            fn (LlmClient $client): array => $client->complete($systemPrompt, $userPrompt, $maxTokens),
        );
    }

    /**
     * @param  array<string, mixed>  $toolSchema
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
        return $this->attempt(
            fn (LlmClient $client): array => $client->completeWithTool(
                $systemPrompt,
                $userPrompt,
                $toolName,
                $toolSchema,
                $maxTokens,
            ),
        );
    }

    /**
     * Един опит през основния (ако още не е отписан) и един през резервния.
     *
     * @param  Closure(LlmClient): array<string, mixed>  $call
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function attempt(Closure $call): array
    {
        if ($this->primaryDisabled === null) {
            try {
                return $call($this->primary);
            } catch (LlmUnavailableException $e) {
                if ($e->isPermanent()) {
                    $this->primaryDisabled = $e->getMessage();

                    Log::warning("LLM доставчик [{$this->primaryName}] е недостъпен, минавам на "
                        ."[{$this->fallbackName}] до края на процеса: {$e->getMessage()}");
                } else {
                    Log::warning("LLM доставчик [{$this->primaryName}] се провали временно, "
                        ."тази заявка минава през [{$this->fallbackName}]: {$e->getMessage()}");
                }
            }
        }

        return $call($this->fallback);
    }
}
