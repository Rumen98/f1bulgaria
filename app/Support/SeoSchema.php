<?php

declare(strict_types=1);

namespace App\Support;

/**
 * JSON-LD структурирани данни за търсачките.
 *
 * Живее в PHP клас (не в Blade), защото Blade компилира schema ключа
 * "(at)context" като своя едноименна директива — дори вътре в PHP стринг
 * в {!! !!} израз. Този бъг вече стигна до production веднъж; не връщай
 * schema markup обратно в шаблона. (Дори Pint яде този ключ в docblock.)
 */
class SeoSchema
{
    public static function jsonLd(): string
    {
        return json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    'name' => 'Падок',
                    'url' => config('app.url'),
                    'logo' => asset('icon-512.png'),
                    'description' => 'Независима общност на българските фенове на Формула 1.',
                ],
                [
                    '@type' => 'WebSite',
                    'name' => 'Падок',
                    'url' => config('app.url'),
                    'inLanguage' => 'bg',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
