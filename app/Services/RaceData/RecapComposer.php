<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Race;
use App\Services\News\Llm\LlmClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пише българския текст на рекапа.
 *
 * Двуслойно нарочно. Точките „Ключови факти“ се сглобяват от шаблони — те
 * носят числата и там няма право на импровизация. Свързващият текст минава
 * през LLM, защото шаблонен разказ звучи като касова бележка, а разказът е
 * причината някой изобщо да прочете страницата.
 *
 * Между двете стои пазач: всяко число в текста на модела трябва да съществува
 * във фактите. Открие ли се измислено, целият абзац се изхвърля и се пада към
 * шаблоните. Моделът може да съчинява прилагателни; няма право да съчинява
 * числа.
 */
class RecapComposer
{
    /** Позиции, места и малки бройки, които са легитимни във всеки текст. */
    private const FREE_INTEGERS_UP_TO = 30;

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return array{headline:string, bullets:array<int, string>, body:array<int, string>, by_llm:bool}
     */
    public function compose(Race $race, array $facts): array
    {
        $raceName = $race->name_bg;
        $bullets = $this->bullets($facts);
        $template = $this->templateBody($raceName, $facts);

        $body = $this->narrative($raceName, $facts, $bullets);

        return [
            'headline' => "Какво казват данните от {$raceName}",
            'bullets' => $bullets,
            'body' => $body ?? $template,
            'by_llm' => $body !== null,
        ];
    }

    /**
     * Точките с числата. Всяка е самостоятелна — липсващ факт просто отпада,
     * вместо да остави „—“ или празно изречение.
     *
     * @param  array<string, mixed>  $facts
     * @return array<int, string>
     */
    public function bullets(array $facts): array
    {
        $out = [];

        if (isset($facts['top_speed'])) {
            $out[] = sprintf(
                '%s мина през засичането с %d км/ч — най-високата скорост в състезанието.',
                $facts['top_speed']['name'],
                $facts['top_speed']['kmh'],
            );
        }

        if (isset($facts['fastest_lap'])) {
            $out[] = sprintf(
                'Най-бърза обиколка: %s с %s (обиколка %d).',
                $facts['fastest_lap']['name'],
                $facts['fastest_lap']['display'],
                $facts['fastest_lap']['lap'],
            );
        }

        if (isset($facts['stops']['winner_stops'], $facts['winner'])) {
            $compounds = $this->compounds($facts['stops']['winner_compounds'] ?? []);
            $out[] = sprintf(
                '%s спечели с %s%s.',
                $facts['winner']['name'],
                $this->stopsPhrase((int) $facts['stops']['winner_stops']),
                $compounds === '' ? '' : ' — '.$compounds,
            );
        }

        if (isset($facts['movers']['climber'])) {
            $climber = $facts['movers']['climber'];
            $out[] = sprintf(
                '%s спечели %s: тръгна %d-и, завърши %d-и.',
                $climber['name'],
                $this->positionsPhrase((int) $climber['gained']),
                $climber['from'],
                $climber['to'],
            );
        }

        if (isset($facts['pit']['fastest_lane'])) {
            $out[] = sprintf(
                'Най-кратък престой в пит лейна: %s — %s секунди.',
                $facts['pit']['fastest_lane']['name'],
                $this->decimal($facts['pit']['fastest_lane']['seconds']),
            );
        }

        if (isset($facts['neutralisations'])) {
            $out[] = sprintf(
                'Състезанието беше неутрализирано %s, общо %s.',
                $this->timesPhrase((int) $facts['neutralisations']['count']),
                $this->lapsPhrase((int) $facts['neutralisations']['laps']),
            );
        }

        if (isset($facts['battle'])) {
            $out[] = sprintf(
                '%s кара %s на по-малко от секунда зад болида пред себе си.',
                $facts['battle']['name'],
                $this->minutesPhrase((int) $facts['battle']['minutes']),
            );
        }

        if (isset($facts['championship']['gap'])) {
            $championship = $facts['championship'];
            $out[] = sprintf(
                'В шампионата %s води с %s пред втория.',
                $championship['leader'],
                $this->pointsPhrase((int) $championship['gap']),
            );
        }

        if (isset($facts['weather'])) {
            $out[] = sprintf(
                'Асфалтът беше между %s и %s градуса%s.',
                $this->decimal($facts['weather']['track_min']),
                $this->decimal($facts['weather']['track_max']),
                $facts['weather']['rain'] ? ', с дъжд по трасето' : '',
            );
        }

        return $out;
    }

    /**
     * Свързаният разказ през LLM. null значи „падни към шаблона“.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<int, string>  $bullets
     * @return array<int, string>|null
     */
    private function narrative(string $raceName, array $facts, array $bullets): ?array
    {
        // При наваксване назад разказът се изключва изцяло — седемдесет
        // извиквания за текстове, които никой няма да прочете скоро.
        // Шаблонът е скучен, но верен и безплатен.
        if ($bullets === [] || ! config('race-data.narrative_llm', true)) {
            return null;
        }

        $system = <<<'PROMPT'
        Ти пишеш кратък аналитичен текст на български за сайт на български фенове на Формула 1.

        Правила, които не нарушаваш:
        1. Ползваш САМО фактите, които са ти подадени. НЯМАШ право да добавяш число, име, обстоятелство или причина, които ги няма в тях. Ако нещо не е подадено, не го споменаваш.
        2. Не измисляш обяснения защо се е случило нещо. Пишеш какво показват данните, не какво предполагаш.
        3. Точно три абзаца, всеки 2-3 изречения. Без заглавия, без булети, без емоджи.
        4. Тон: спокоен, конкретен, за човек, който е гледал състезанието. Без клишета от типа „зрелищна битка" и „невероятен обрат".
        5. Числата се пишат точно както са подадени. Не закръгляш и не преизчисляваш.
        6. Чуждите имена са вече на български в подадените факти — преписваш ги буквално.
        PROMPT;

        $user = "Състезание: {$raceName}\n\nФакти:\n".implode("\n", array_map(
            fn (string $b) => '- '.$b,
            $bullets,
        ));

        try {
            $response = $this->llm->completeWithTool(
                $system,
                $user,
                'write_race_recap',
                [
                    'type' => 'object',
                    'properties' => [
                        'paragraphs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'maxLength' => 700],
                            'minItems' => 3,
                            'maxItems' => 3,
                        ],
                    ],
                    'required' => ['paragraphs'],
                ],
                1024,
            );
        } catch (Throwable $e) {
            Log::warning('Рекап: LLM разказът се провали, падаме към шаблон', ['error' => $e->getMessage()]);

            return null;
        }

        $paragraphs = array_values(array_filter(
            array_map('trim', (array) ($response['input']['paragraphs'] ?? [])),
            fn ($p) => is_string($p) && $p !== '',
        ));

        if (count($paragraphs) !== 3) {
            return null;
        }

        $invented = $this->inventedNumbers(implode(' ', $paragraphs), $facts);

        if ($invented !== []) {
            Log::warning('Рекап: моделът върна числа, които ги няма във фактите', ['numbers' => $invented]);

            return null;
        }

        return $paragraphs;
    }

    /**
     * Числата в текста, които не се срещат във фактите.
     *
     * @param  array<string, mixed>  $facts
     * @return array<int, string>
     */
    private function inventedNumbers(string $text, array $facts): array
    {
        $allowed = $this->allowedNumbers($facts);

        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $matches);

        $invented = [];

        foreach ($matches[0] as $raw) {
            $normalised = str_replace(',', '.', $raw);

            if (isset($allowed[$normalised]) || isset($allowed[ltrim($normalised, '0')])) {
                continue;
            }

            // Малките цели числа са позиции, места и бройки — те се въртят
            // във всяко изречение и не са твърдение за данните.
            if (ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= self::FREE_INTEGERS_UP_TO) {
                continue;
            }

            // Годините не са данни от сесията, а контекст („сезон 2026“).
            if (ctype_digit($raw) && (int) $raw >= 1950 && (int) $raw <= 2100) {
                continue;
            }

            $invented[] = $raw;
        }

        return array_values(array_unique($invented));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, true>
     */
    private function allowedNumbers(array $facts): array
    {
        $allowed = [];

        array_walk_recursive($facts, function ($value) use (&$allowed): void {
            if (! is_int($value) && ! is_float($value)) {
                return;
            }

            $allowed[(string) $value] = true;
            $allowed[(string) round((float) $value, 1)] = true;
            $allowed[(string) (int) $value] = true;
        });

        // Времената излизат като „1:23.456“ — и минутите, и секундите в тях са
        // законни, макар да не са отделни числа във фактите.
        foreach (['fastest_lap'] as $key) {
            $display = $facts[$key]['display'] ?? null;

            if (is_string($display)) {
                foreach (preg_split('/[:.]/', $display) ?: [] as $part) {
                    $allowed[ltrim($part, '0') ?: '0'] = true;
                    $allowed[$part] = true;
                }

                $allowed[str_replace(':', '.', $display)] = true;
            }
        }

        return $allowed;
    }

    /**
     * Шаблонният разказ — резервният вариант. Скучен, но верен.
     *
     * @param  array<string, mixed>  $facts
     * @return array<int, string>
     */
    private function templateBody(string $raceName, array $facts): array
    {
        $paragraphs = [];

        if (isset($facts['winner'])) {
            $winner = $facts['winner'];
            $gap = $winner['gap_to_second'] !== null
                ? sprintf(' с %s секунди пред втория', $this->decimal($winner['gap_to_second']))
                : '';
            $paragraphs[] = sprintf('%s спечели %s%s.', $winner['name'], $raceName, $gap);
        } else {
            $paragraphs[] = "Данните от {$raceName} са налични.";
        }

        if (isset($facts['stops'])) {
            $paragraphs[] = sprintf(
                'Най-много спирания направи %s (%s), най-малко — %s (%s).',
                $facts['stops']['most']['name'],
                $this->stopsPhrase((int) $facts['stops']['most']['count']),
                $facts['stops']['least']['name'],
                $this->stopsPhrase((int) $facts['stops']['least']['count']),
            );
        }

        if (isset($facts['fastest_lap'])) {
            $paragraphs[] = sprintf(
                'Най-бързата обиколка остана за %s — %s.',
                $facts['fastest_lap']['name'],
                $facts['fastest_lap']['display'],
            );
        }

        return $paragraphs;
    }

    /** @param  array<int, string>  $compounds */
    private function compounds(array $compounds): string
    {
        $names = [
            'SOFT' => 'мека',
            'MEDIUM' => 'средна',
            'HARD' => 'твърда',
            'INTERMEDIATE' => 'междинна',
            'WET' => 'дъждовна',
        ];

        $words = array_values(array_filter(array_map(
            fn (string $c) => $names[mb_strtoupper($c)] ?? null,
            $compounds,
        )));

        return $words === [] ? '' : implode(' → ', $words);
    }

    private function stopsPhrase(int $count): string
    {
        return match ($count) {
            0 => 'нула спирания',
            1 => 'едно спиране',
            2 => 'две спирания',
            default => "{$count} спирания",
        };
    }

    private function positionsPhrase(int $count): string
    {
        return $count === 1 ? 'една позиция' : "{$count} позиции";
    }

    private function timesPhrase(int $count): string
    {
        return match ($count) {
            1 => 'веднъж',
            2 => 'два пъти',
            default => "{$count} пъти",
        };
    }

    private function lapsPhrase(int $count): string
    {
        return $count === 1 ? 'една обиколка' : "{$count} обиколки";
    }

    private function minutesPhrase(int $count): string
    {
        return $count === 1 ? 'една минута' : "{$count} минути";
    }

    private function pointsPhrase(int $count): string
    {
        return $count === 1 ? 'една точка' : "{$count} точки";
    }

    /** Десетичните числа на български се пишат със запетая. */
    private function decimal(float|int $value): string
    {
        return str_replace('.', ',', (string) round((float) $value, 1));
    }
}
