{{--
    Покана към Телеграм. Показва се само когато TELEGRAM_COMMUNITY_URL е зададен,
    за да не води писмото към празен линк.
--}}
@if ($communityUrl = config('services.telegram.community_url'))

---

💬 Коментираме уикенда заедно в [Телеграм]({{ $communityUrl }}) — новините излизат там веднага щом ги публикуваме.
@endif
