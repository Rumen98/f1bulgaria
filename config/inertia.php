<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    | Ботовете получават пълния HTML на първата вълна, вместо да чакат Google
    | да изпълни JavaScript-а.
    |
    | Изисква ЖИВ демон на сървъра: `php artisan inertia:start-ssr` под
    | supervisor (виж LAUNCH.md). Ако демонът падне, Inertia пада ГРАЦИОЗНО
    | към клиентски рендер — сайтът работи, просто губи SSR предимството,
    | затова health check-ът в LAUNCH.md не е излишен.
    |
    | `npm run build` вече вгражда и SSR bundle-а (ziggy:generate + двата
    | vite билда), а vite.config.js го прави самодостатъчен (ssr.noExternal),
    | така че демонът не зависи от node_modules по време на работа.
    */

    'ssr' => [
        'enabled' => env('INERTIA_SSR_ENABLED', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/Pages')],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
