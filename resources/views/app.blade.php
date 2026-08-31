@php
    /** @var \App\Support\Seo $seo */
    $seo = app(\App\Support\Seo::class);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#e10600">

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        {{-- SEO / Open Graph — рендерира се СЪРВЪРНО от App\Support\Seo.
             Социалните скрейпъри (Facebook, Viber, Telegram, X) не изпълняват
             JavaScript, затова тези тагове НЕ бива да се дублират във Vue
             <Head> — иначе скрейпърът чете първото (генерично) срещане. --}}
        {{-- БЕЗ `inertia` атрибут: Inertia head manager-ът притежава всеки
             елемент с този атрибут и го ЗАМЕНЯ при монтиране — така сървърното
             заглавие се губеше още преди Googlebot да го прочете. Клиентът
             обновява document.title от seoTitle prop-а при SPA навигация. --}}
        <title>{{ $seo->resolvedTitle() }}</title>
        <meta name="description" content="{{ $seo->resolvedDescription() }}">
        <link rel="canonical" href="{{ $seo->resolvedCanonical() }}">
        @if ($seo->isNoindex())
            <meta name="robots" content="noindex, follow">
        @endif

        <meta property="og:site_name" content="Падок">
        <meta property="og:type" content="{{ $seo->resolvedType() }}">
        <meta property="og:title" content="{{ $seo->socialTitle() }}">
        <meta property="og:description" content="{{ $seo->resolvedDescription() }}">
        <meta property="og:url" content="{{ $seo->resolvedCanonical() }}">
        <meta property="og:image" content="{{ $seo->resolvedImage() }}">
        @if ($size = $seo->resolvedImageSize())
            <meta property="og:image:width" content="{{ $size[0] }}">
            <meta property="og:image:height" content="{{ $size[1] }}">
        @endif
        <meta property="og:image:alt" content="{{ $seo->socialTitle() }}">
        <meta property="og:locale" content="bg_BG">
        @if ($seo->publishedAt())
            <meta property="article:published_time" content="{{ $seo->publishedAt() }}">
            <meta property="article:modified_time" content="{{ $seo->modifiedAt() ?? $seo->publishedAt() }}">
        @endif

        <meta name="twitter:card" content="{{ $seo->twitterCard() }}">
        <meta name="twitter:title" content="{{ $seo->socialTitle() }}">
        <meta name="twitter:description" content="{{ $seo->resolvedDescription() }}">
        <meta name="twitter:image" content="{{ $seo->resolvedImage() }}">
        <meta name="twitter:image:alt" content="{{ $seo->socialTitle() }}">

        {{-- Структурирани данни за търсачките. НЕ ги връщай в шаблона — Blade
             компилира "@context" като директива дори в PHP стринг (виж SeoSchema). --}}
        <script type="application/ld+json">{!! \App\Support\SeoSchema::jsonLd($seo->schemaNodes()) !!}</script>

        <link rel="alternate" type="application/rss+xml" title="Падок — новини" href="{{ route('feed') }}">

        <!-- PWA -->
        <link rel="manifest" href="/manifest.webmanifest">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600|exo-2:700,800,900&display=swap" rel="stylesheet" />

        @include('partials.analytics')

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        {{-- Съдържание за ботове, които не изпълняват JavaScript. Vue заменя
             #app при монтиране, но <noscript> остава в първоначалния HTML —
             това е единствената crawlable вътрешна структура преди рендер. --}}
        <noscript>
            <h1>{{ $seo->resolvedTitle() }}</h1>
            <p>{{ $seo->resolvedDescription() }}</p>
            <nav>
                <a href="{{ route('news.index') }}">Новини</a> ·
                <a href="{{ route('calendar') }}">Календар</a> ·
                <a href="{{ route('standings') }}">Класиране</a> ·
                <a href="{{ route('teams.index') }}">Отбори</a> ·
                <a href="{{ route('drivers.index') }}">Пилоти</a>
            </nav>
        </noscript>
    </body>
</html>
