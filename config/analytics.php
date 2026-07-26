<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Аналитика
    |--------------------------------------------------------------------------
    | Всички са изключени по подразбиране — скриптът се вкарва само ако
    | съответната променлива е зададена. Plausible и Umami са cookieless
    | (без нужда от cookie банер); GA4 изисква съгласие в ЕС, но се връзва
    | с Google Search Console.
    */

    'plausible_domain' => env('PLAUSIBLE_DOMAIN'),
    'plausible_src' => env('PLAUSIBLE_SRC', 'https://plausible.io/js/script.js'),

    'umami_website_id' => env('UMAMI_WEBSITE_ID'),
    'umami_src' => env('UMAMI_SRC'),

    'ga_measurement_id' => env('GA_MEASUREMENT_ID'),

];
