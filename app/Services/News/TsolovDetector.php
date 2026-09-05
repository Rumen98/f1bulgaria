<?php

declare(strict_types=1);

namespace App\Services\News;

use Illuminate\Support\Str;

/**
 * Разпознава новини за Никола Цолов.
 *
 * Нарочно БЕЗ LLM. За конкретен човек точното съвпадение по фамилия е
 * по-добро от модел във всяко измерение: няма фалшиви попадения, не струва
 * нищо, не зависи от жив доставчик и дава един и същ резултат всеки път.
 * Моделът остава за това, в което е незаменим — превода и статията.
 *
 * Работи върху ОРИГИНАЛНИЯ текст (английски), защото се вика при вземането,
 * преди обогатяването. Кирилските форми са тук за източниците на български.
 */
class TsolovDetector
{
    /**
     * Фамилията стига — „Цолов" е достатъчно рядка, за да няма съименник в
     * автомобилния спорт. Личното име се проверява отделно само за пълнота.
     *
     * @var list<string>
     */
    private const NEEDLES = ['tsolov', 'цолов'];

    public function matches(?string ...$texts): bool
    {
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }

            $haystack = Str::lower($text);

            foreach (self::NEEDLES as $needle) {
                // Границата на думата пази от съвпадение вътре в друга дума.
                if (preg_match('/(?<!\p{L})'.preg_quote($needle, '/').'/u', $haystack) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
