<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * „Скрита врата" за админ панела: без валиден ключ /admin връща 404, все
 * едно не съществува — скрива login формата от сканери и brute force.
 *
 * Вход: /admin?key={ADMIN_ACCESS_KEY}. При съвпадение се издава бисквитка
 * (шифрована от EncryptCookies в стека на панела) и ключът не е нужен
 * повече на този браузър. Празен ADMIN_ACCESS_KEY изключва защитата
 * (локална разработка). Смяна на ключа инвалидира всички бисквитки,
 * защото стойността им е HMAC на самия ключ.
 */
class RequireAdminAccessKey
{
    private const COOKIE_NAME = 'padok_admin_gate';

    private const COOKIE_MINUTES = 30 * 24 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('app.admin_access_key', '');

        if ($key === '') {
            return $next($request);
        }

        $expected = hash_hmac('sha256', $key, (string) config('app.key'));

        $provided = (string) $request->query('key', '');

        if ($provided !== '' && hash_equals($key, $provided)) {
            // Редирект към същия URL без ?key= (да не остава в history/логове).
            return redirect($request->url())
                ->withCookie(cookie(self::COOKIE_NAME, $expected, self::COOKIE_MINUTES));
        }

        if (hash_equals($expected, (string) $request->cookie(self::COOKIE_NAME, ''))) {
            return $next($request);
        }

        abort(404);
    }
}
