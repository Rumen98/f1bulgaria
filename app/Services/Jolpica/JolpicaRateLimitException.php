<?php

declare(strict_types=1);

namespace App\Services\Jolpica;

/**
 * Хвърля се когато retry-ите са изчерпани, а последният отговор е 429 —
 * т.е. часовият rate limit на Jolpica е ударен. Извикващият може да изчака
 * прозореца и да опита отново (виж f1:sync-history), вместо да се откаже.
 */
class JolpicaRateLimitException extends JolpicaException {}
