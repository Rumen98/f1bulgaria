<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Какво се случи при поставяне на публикация в опашката.
 *
 * Разликата има значение за отчета: „обновена" означава, че вече изпратено
 * съобщение ще бъде РЕДАКТИРАНО в канала — видима промяна, която не бива да
 * се брои наравно с нова публикация, нито да минава мълчаливо.
 */
enum ChannelQueueOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
}
