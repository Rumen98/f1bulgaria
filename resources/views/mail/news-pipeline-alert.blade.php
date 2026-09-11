@component('mail::message')
@if ($test)
# Тест на алармата

Това писмо е пуснато ръчно с `news:health-check --force-alert`. Щом го четеш, доставката работи — а ако си го намерил в спама, добави подателя в контактите си, иначе истинската аларма ще отиде на същото място.

@component('mail::table')
| Показател | Стойност |
| :--- | ---: |
| Състояние в момента | {{ $status['healthy'] ? 'здраво' : 'АВАРИЯ' }} |
| Чакащи новини | {{ $status['pending'] }} |
| Най-стара необработена | {{ $status['oldest_pending_at'] ?? 'няма' }} |
| Последна взета новина | {{ $status['last_fetched_at'] ?? 'няма' }} |
@endcomponent

@if (! $status['healthy'])
> ⚠️ {{ $status['reason'] }}
@endif
@elseif ($recovered)
# Новините пак се публикуват

Pipeline-ът се възстанови сам.

@component('mail::table')
| Показател | Стойност |
| :--- | ---: |
| Инцидентът започна | {{ $since ?? '—' }} |
| Чакащи новини | {{ $status['pending'] }} |
| Най-стара необработена | {{ $status['oldest_pending_at'] ?? 'няма' }} |
@endcomponent

Изоставането се наваксва само — `news:enrich` минава по 25 статии на :05 и :35.
@else
# Новините спряха

@component('mail::panel')
{{ $status['reason'] }}
@endcomponent

@component('mail::table')
| Показател | Стойност |
| :--- | ---: |
| Чакащи новини | **{{ $status['pending'] }}** |
| Най-стара необработена | {{ $status['oldest_pending_at'] ?? 'няма' }} |
| Последна взета новина | {{ $status['last_fetched_at'] ?? 'няма' }} |
| Часове без движение | {{ $status['stale_hours'] ?? '—' }} |
@endcomponent

## Откъде да започнеш

Първо виж КАКВО гърми — грешката на доставчика е в лога дословно:

```
grep -h "News enrich failed" /var/www/f1bulgaria/storage/logs/laravel*.log | tail -5
```

После провери дали проблемът е в LLM доставчика:

```
cd /var/www/f1bulgaria && sudo -u www-data php artisan news:enrich --limit=1
```

Чести причини, подредени по вероятност: изчерпан кредит или лимит при доставчика; моделът е спрян от тарифата (403, или 429 с `x-ratelimit-limit-req-minute: 0`); невалиден ключ; стар config кеш след промяна в `.env`.

Смяната на доставчик е промяна в `.env` плюс `php artisan config:cache`.
@endif

@component('mail::subcopy')
Това е автоматично оперативно съобщение от `news:health-check`. Праща се веднъж на инцидент, не на всяка проверка.
@endcomponent
@endcomponent
