<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Прилага SEO дефолтите за статичните страници от config/seo.php.
 *
 * Върви ПРЕДИ контролера, така че динамичните страници (новина, пилот, отбор)
 * спокойно презаписват стойностите в самия контролер.
 */
class ApplyRouteSeo
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if ($name !== null) {
            /** @var array{title:?string, description:string}|null $meta */
            $meta = config("seo.{$name}");

            if ($meta !== null) {
                app(Seo::class)
                    ->title($meta['title'] ?? null)
                    ->description($meta['description'] ?? null);
            }
        }

        return $next($request);
    }
}
