<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Към коя сесия се отнася редът в `results` — главно състезание или спринт.
 * И двете носят шампионатни точки и трябва да се сумират в класирането.
 */
enum ResultSessionType: string
{
    case Race = 'race';
    case Sprint = 'sprint';
}
