<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301 от www.* към каноничния хост от APP_URL.
 *
 * Без това www.padok.bg сервира пълно копие на сайта, всяка страница
 * канонизира сама себе си и crawl бюджетът се дели между два хоста.
 * nginx-ниво е по-евтино, но това работи независимо от конфигурацията
 * на сървъра — и на локална машина, и след смяна на хостинг.
 */
class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($canonical) || $canonical === '') {
            return $next($request);
        }

        $host = $request->getHost();

        // Пренасочваме само www варианта на каноничния хост — всичко друго
        // (localhost, .test домейни, healthcheck по IP) остава недокоснато.
        if ($host !== 'www.'.$canonical) {
            return $next($request);
        }

        return redirect()->away(
            rtrim((string) config('app.url'), '/').$request->getRequestUri(),
            301,
        );
    }
}
