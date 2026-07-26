<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Помощни функции за текста на съобщенията в Telegram (HTML режим).
 *
 * @see https://core.telegram.org/bots/api#html-style
 */
class TelegramText
{
    /**
     * Екранира стойност за HTML режима на Telegram.
     *
     * Telegram изисква точно три знака: `<`, `>` и `&`. htmlspecialchars ги
     * покрива, а апострофът, който добавя като `&#039;`, е числова entity —
     * тях Bot API поддържа всички. Именуваните са само четири
     * (&lt; &gt; &amp; &quot;), затова НЕ ползвай htmlentities: тя произвежда
     * неща като &nbsp;, които Telegram не разпознава и показва буквално.
     */
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Разбива дълго съобщение на части под лимита на Telegram.
     *
     * Лимитът (4096) важи за текста СЛЕД парсване на entities, тоест HTML
     * таговете не се броят — затова мерим по strip_tags, не по суровия низ.
     *
     * Реже само на границата на ред. Това е предпоставка, а не удобство:
     * ако разделим по средата на `<b>…</b>`, Telegram връща 400 и целият
     * пост пада. Форматърите затова държат всеки таг затворен в своя ред.
     *
     * @return array<int, string>
     */
    public static function chunk(string $html, ?int $maxLength = null): array
    {
        $max = $maxLength ?? (int) config('channel.max_message_length', 3800);
        $lines = explode("\n", $html);

        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;

            if (self::visibleLength($candidate) <= $max) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            // Един ред сам по себе си над лимита не се дели допълнително —
            // би счупил тагове. Форматърите не произвеждат такива редове.
            $current = $line;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [''] : $chunks;
    }

    /**
     * Дължина на видимия текст — така я брои и Telegram.
     */
    public static function visibleLength(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }
}
