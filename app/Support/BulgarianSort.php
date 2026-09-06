<?php

declare(strict_types=1);

namespace App\Support;

use Collator;

/**
 * Азбучна подредба на кирилица.
 *
 * ЗАЩО НЕ В SQL: показваното име го няма в базата — идва от
 * config/driver-names-bg.php през DriverName::display(). А и MySQL подрежда
 * смесени латиница и кирилица по байтове, не по азбука, така че
 * `orderBy('last_name')` пращаше „Верстапен" преди „Албон".
 *
 * Ключът се сравнява със `<=>` (тоест става за `sortBy`). При наличен intl е
 * ICU sort key за българска локала; без intl — ръчна таблица със същия ред:
 * първо кирилица, после латиница.
 */
final class BulgarianSort
{
    /** Българската азбука в азбучен ред. */
    private const ALPHABET = 'абвгдежзийклмнопрстуфхцчшщъьюя';

    /**
     * Диакритиките се приравняват към базовата буква — иначе „Räikkönen" пада
     * след „Zhou", защото UTF-8 байтовете на „ä" са над тези на „z".
     * Ползва се само във fallback-а; ICU се справя сам.
     */
    private const LATIN_FOLDING = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ą' => 'a',
        'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ę' => 'e',
        'ğ' => 'g',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
        'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ť' => 't', 'ț' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    private static ?Collator $collator = null;

    private static bool $collatorResolved = false;

    /** @var array<string, string>|null */
    private static ?array $fallbackMap = null;

    /**
     * Ключ за азбучна подредба на показвано име.
     */
    public static function key(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $collator = self::collator();

        if ($collator !== null) {
            $key = $collator->getSortKey($value);

            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return strtr(mb_strtolower($value, 'UTF-8'), self::fallbackMap());
    }

    /**
     * Дали подредбата минава през ICU. Полезно за тестове и диагностика.
     */
    public static function usesIntl(): bool
    {
        return self::collator() !== null;
    }

    private static function collator(): ?Collator
    {
        if (self::$collatorResolved) {
            return self::$collator;
        }

        self::$collatorResolved = true;

        if (! class_exists(Collator::class)) {
            return null;
        }

        try {
            self::$collator = new Collator('bg_BG');
        } catch (\Throwable) {
            self::$collator = null;
        }

        return self::$collator;
    }

    /**
     * @return array<string, string>
     */
    private static function fallbackMap(): array
    {
        if (self::$fallbackMap !== null) {
            return self::$fallbackMap;
        }

        $map = [];

        // Префиксът \x01 е под всяка латинска буква, така че кирилицата излиза
        // първа — същата групировка, която дава и ICU при българска локала.
        foreach (mb_str_split(self::ALPHABET) as $index => $letter) {
            $map[$letter] = "\x01".chr(0x41 + $index);
        }

        return self::$fallbackMap = $map + self::LATIN_FOLDING;
    }
}
