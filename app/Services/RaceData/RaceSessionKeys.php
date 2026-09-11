<?php

declare(strict_types=1);

namespace App\Services\RaceData;

/**
 * Ключовете на един уикенд в OpenF1.
 *
 * Квалификацията е отделно поле, защото стартовата решетка виси при НЕЯ, а не
 * при състезанието — питане на `starting_grid` с ключа на състезанието връща
 * празен отговор без грешка.
 *
 * `circuit` и `year` пътуват дотук само заради геометрията на пистата от
 * MultiViewer (виж CircuitGeometry) — адресът ѝ се сглобява от тях.
 */
final readonly class RaceSessionKeys
{
    public function __construct(
        public int $race,
        public ?int $qualifying = null,
        public ?int $meeting = null,
        public ?int $circuit = null,
        public ?int $year = null,
    ) {}
}
