@component('mail::message')
# Дневен отчет · {{ $stats['date'] }}

Ето какво се случи на **Падок** през изминалия ден.

@component('mail::table')
| Показател | Брой |
| :--- | ---: |
| Нови регистрации | **{{ $stats['registrations'] }}** |
| Влизания | **{{ $stats['logins'] }}** (уникални: {{ $stats['unique_logins'] }}) |
| Изходи | {{ $stats['logouts'] }} |
| Неуспешни опити за вход | {{ $stats['failed'] }} |
| Общо потребители | {{ $stats['total_users'] }} |
@endcomponent

@if (! empty($stats['new_emails']))
## Нови акаунти
@foreach ($stats['new_emails'] as $email)
- {{ $email }}
@endforeach
@endif

@if ($stats['failed'] > 0)
> ⚠️ Неуспешни опити за вход днес: {{ $stats['failed'] }}. Ако са необичайно много, провери одит лога в админ панела.
@endif

Пълната хронология е в админ панела → **Общност → Одит лог**.

Поздрави,<br> {{ config('app.name') }}
@endcomponent
