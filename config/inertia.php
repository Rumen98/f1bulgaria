<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    | ИЗКЛЮЧЕНО. Кодът е готов (resources/js/ssr.js, `npm run build:ssr`), но
    | преди включване трябва да се дорешат три неща — всяко проверено емпирично:
    |
    |  1. resources/js/utils/routes.js вика глобалния `route()` от @routes,
    |     който в Node не съществува — try/catch го гълта, но nav линковете
    |     изчезват от SSR HTML-а и се появяват чак след хидратация.
    |  2. resources/js/ssr.js чете Ziggy от build-time генерирания ziggy.js —
    |     APP_URL се запича в bundle-а при билда.
    |  3. vue и @inertiajs/vue3 са в devDependencies, а SSR bundle-ът ги
    |     externalize-ва → `npm ci --omit=dev` чупи демона.
    |
    | Освен това `npm run build` НЕ пресъздава SSR bundle-а (само build:ssr),
    | а HttpGateway гълта грешките без лог — стар bundle би сервирал тихо.
    |
    | Приоритетът е нисък: сървърните мета тагове, JSON-LD и noscript блокът
    | вече покриват скрейпърите, които не изпълняват JavaScript.
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
