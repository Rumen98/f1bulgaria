<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    | ИЗКЛЮЧЕНО по подразбиране. Включва се едва след като на сървъра върви
    | демонът (`php artisan inertia:start-ssr` под supervisor) — иначе всяка
    | заявка първо се опитва да се свърже с 127.0.0.1:13714, изчаква таймаут
    | и чак тогава пада към клиентски рендер. Резултатът е бавен сайт.
    |
    | Когато демонът работи, ботовете получават пълния HTML на първата вълна,
    | вместо да чакат Google да изпълни JavaScript-а.
    */

    'ssr' => [
        'enabled' => env('INERTIA_SSR_ENABLED', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/Pages')],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
