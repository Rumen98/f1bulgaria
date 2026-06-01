<?php

declare(strict_types=1);

namespace App\Services\Jolpica;

use RuntimeException;

/**
 * Хвърля се когато Jolpica API върне неуспех, който не може да се поправи с
 * повторни опити (4xx) или след изчерпване на retry-ите при 5xx/429/мрежа.
 */
class JolpicaException extends RuntimeException {}
