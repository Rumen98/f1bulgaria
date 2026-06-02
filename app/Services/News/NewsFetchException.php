<?php

declare(strict_types=1);

namespace App\Services\News;

use RuntimeException;

/**
 * Хвърля се при провал на вземането/парсването на RSS/Atom feed
 * (HTTP грешка след retry-ите или невалиден XML).
 */
class NewsFetchException extends RuntimeException {}
