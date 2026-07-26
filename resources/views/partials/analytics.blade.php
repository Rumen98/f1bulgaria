{{-- Аналитика — включва се само ако е конфигурирана в .env.
     Plausible/Umami са cookieless (не изискват cookie банер);
     GA4 се връзва със Search Console, но изисква съгласие в ЕС. --}}
@if (config('analytics.plausible_domain'))
    <script defer
            data-domain="{{ config('analytics.plausible_domain') }}"
            src="{{ config('analytics.plausible_src') }}"></script>
@endif

@if (config('analytics.umami_website_id'))
    <script defer
            data-website-id="{{ config('analytics.umami_website_id') }}"
            src="{{ config('analytics.umami_src') }}"></script>
@endif

@if (config('analytics.ga_measurement_id'))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('analytics.ga_measurement_id') }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ config('analytics.ga_measurement_id') }}');
    </script>
@endif
