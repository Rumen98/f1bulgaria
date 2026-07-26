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
        // Seo е scoped singleton — при long-lived runtime (Octane) контейнерът
        // преживява заявката. Без този reset canonical/og:type/JSON-LD от
        // предишната страница изтичат в следващата.
        app(Seo::class)->reset();

        $name = $request->route()?->getName();

        if ($name !== null) {
            // ВАЖНО: config("seo.{$name}") НЕ работи — имената на рутовете
            // съдържат точки ('teams.index'), а config() ги тълкува като
            // вложени ключове. Взимаме масива и търсим по литерален ключ.
            /** @var array{title:?string, description:?string}|null $meta */
            $meta = config('seo')[$name] ?? null;

            if ($meta !== null) {
                app(Seo::class)
                    ->title($meta['title'] ?? null)
                    ->description($meta['description'] ?? null);
            } elseif (! $request->routeIs('*.show', 'races.show', 'f2.*', 'profiles.show')) {
                // Липсващ запис за статична страница е пропуск, не дизайн —
                // в local го правим шумно, за да не тръгне с дефолтно заглавие.
                if (config('app.debug') && $request->isMethod('GET')) {
                    logger()->debug("Липсва config/seo.php запис за рут [{$name}].");
                }
            }
        }

        return $next($request);
    }
}
