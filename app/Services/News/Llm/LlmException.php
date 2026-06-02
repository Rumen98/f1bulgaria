<?php

declare(strict_types=1);

namespace App\Services\News\Llm;

use RuntimeException;

/**
 * Хвърля се при провал на LLM слоя — HTTP грешка към Anthropic API
 * (след retry-ите) или невалиден/неочакван отговор от модела.
 */
class LlmException extends RuntimeException {}
