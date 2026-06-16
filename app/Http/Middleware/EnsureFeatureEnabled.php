<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пуска заявката само ако даден feature flag (config/features.php) е включен.
 * Изключена функция → 404 (сякаш рутът не съществува). Употреба: ->middleware('feature:f2').
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless((bool) config("features.{$feature}", false), 404);

        return $next($request);
    }
}
