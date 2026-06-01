<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ergast/Jolpica не връща цветове на отборите, затова държим малка карта по
 * constructorId. Непознат конструктор → null (админът може да го коригира ръчно).
 */
final class ConstructorColors
{
    /** @var array<string, string> */
    private const MAP = [
        'red_bull' => '#3671C6',
        'ferrari' => '#E8002D',
        'mercedes' => '#27F4D2',
        'mclaren' => '#FF8000',
        'aston_martin' => '#229971',
        'alpine' => '#0093CC',
        'williams' => '#64C4FF',
        'rb' => '#6692FF',
        'sauber' => '#52E252',
        'haas' => '#B6BABD',
        'alphatauri' => '#5E8FAA',
        'alfa' => '#C92D4B',
        'renault' => '#FFF500',
        'racing_point' => '#F596C8',
        'toro_rosso' => '#469BFF',
        'force_india' => '#F596C8',
    ];

    public static function forJolpicaId(?string $jolpicaId): ?string
    {
        if ($jolpicaId === null) {
            return null;
        }

        return self::MAP[$jolpicaId] ?? null;
    }
}
