<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Българското изписване на имената на пилотите (config/driver-names-bg.php).
 *
 * Търсенето на български е на кирилица — „Люис Хамилтън", не „Lewis Hamilton".
 * Кирилското име отива в <h1>, <title> и описанието; латинското остава видимо
 * като подзаглавие, за да хващаме и двете заявки.
 */
class DriverName
{
    /**
     * Българското име, ако е известно; иначе null.
     */
    public static function bg(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $name = config('driver-names-bg')[$slug] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Името за показване — българското, ако го има, иначе латинското.
     */
    public static function display(?string $slug, string $latin): string
    {
        return self::bg($slug) ?? $latin;
    }

    /**
     * „Люис Хамилтън (Lewis Hamilton)" — за meta описания, където искаме и
     * двата варианта в един и същ текст.
     */
    public static function both(?string $slug, string $latin): string
    {
        $bg = self::bg($slug);

        return $bg === null || $bg === $latin ? $latin : "{$bg} ({$latin})";
    }
}
