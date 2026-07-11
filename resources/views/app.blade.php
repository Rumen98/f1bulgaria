<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#e10600">

        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        <title inertia>{{ config('app.name', 'F1 България') }}</title>

        <!-- SEO / Open Graph (по подразбиране; Inertia <Head> презаписва title-а на отделните страници) -->
        <meta name="description" content="F1 България — новини, календар, класирания, статистика и live тайминг от Формула 1 на български.">
        <link rel="canonical" href="{{ url()->current() }}">
        <meta property="og:site_name" content="F1 България">
        <meta property="og:type" content="website">
        <meta property="og:title" content="F1 България">
        <meta property="og:description" content="Формула 1 на български — новини, календар, класирания и статистика.">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('images/og-default.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="F1 България">
        <meta name="twitter:description" content="Формула 1 на български — новини, календар, класирания и статистика.">
        <meta name="twitter:image" content="{{ asset('images/og-default.png') }}">

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
