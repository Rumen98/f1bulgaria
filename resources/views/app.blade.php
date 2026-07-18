<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#e10600">

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        <title inertia>{{ config('app.name', 'Падок') }}</title>

        <!-- SEO / Open Graph (по подразбиране; Inertia <Head> презаписва title-а на отделните страници) -->
        <meta name="description" content="Падок — новини, календар, класирания, статистика и live тайминг от Формула 1 на български.">
        <link rel="canonical" href="{{ url()->current() }}">
        <meta property="og:site_name" content="Падок">
        <meta property="og:type" content="website">
        <meta property="og:title" content="Падок — Формула 1 на български">
        <meta property="og:description" content="Формула 1 на български — новини, календар, класирания и статистика.">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('og-image.jpg') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:alt" content="Падок — Формула 1 на български">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Падок — Формула 1 на български">
        <meta name="twitter:description" content="Формула 1 на български — новини, календар, класирания и статистика.">
        <meta name="twitter:image" content="{{ asset('og-image.jpg') }}">
        <meta name="twitter:image:alt" content="Падок — Формула 1 на български">
        <meta property="og:locale" content="bg_BG">

        {{-- Структурирани данни за търсачките. Генерира се с json_encode, а не
             в markup — "@context" се сблъсква с Blade директивата @context. --}}
        <script type="application/ld+json">{!! json_encode([
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
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

        <!-- PWA -->
        <link rel="manifest" href="/manifest.webmanifest">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600|exo-2:700,800,900&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
