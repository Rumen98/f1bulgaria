@php($health = $stats['health'])
@component('mail::message')
# Дневен отчет · {{ $stats['date'] }}

{{--
    Проблемите са ПРЕДИ числата за активността. Активността е любопитство, проблемът е работа за днес — а човек чете отгоре надолу и спира, когато срещне познатото. --}}
@if (! empty($health['problems']))
## ⚠️ За оправяне
@foreach ($health['problems'] as $problem)
- **{{ $problem['label'] }}** — {{ $problem['text'] }}
@endforeach
@else
Нищо не е счупено. Ето какво се случи на **Падок** през изминалия ден.
@endif

@component('mail::table')
| Показател | Брой |
| :--- | ---: |
| Нови регистрации | **{{ $stats['registrations'] }}** |
| Влизания | **{{ $stats['logins'] }}** (уникални: {{ $stats['unique_logins'] }}) |
| Изходи | {{ $stats['logouts'] }} |
| Неуспешни опити за вход | {{ $stats['failed'] }} |
| Общо потребители | {{ $stats['total_users'] }} |
@endcomponent

{{--
    Всяка секция е ЕДИН ред. Подробностите отдолу излизат само когато има какво да се направи — иначе писмото изглежда еднакво дълго всеки ден и след седмица спира да се чете. --}}
## Състояние
- **Опашка:** {{ $health['queue']['line'] }}
- **Писма:** {{ $health['mail']['line'] }}
- **Рекапи:** {{ $health['recaps']['line'] }}
- **Лига:** {{ $health['league']['line'] }}

@if (! empty($health['recaps']['missing']))
### Кръгове без рекап
@foreach ($health['recaps']['missing'] as $race)
- {{ $race['name'] }} ({{ $race['date'] }})
@endforeach
@if ($health['recaps']['missing_count'] > count($health['recaps']['missing']))
- … и още {{ $health['recaps']['missing_count'] - count($health['recaps']['missing']) }}
@endif
@endif

@if (! empty($health['recaps']['abandoned']))
### Отказали се рекапи
@foreach ($health['recaps']['abandoned'] as $recap)
- {{ $recap['name'] }} — {{ $recap['attempts'] }} опита{{ $recap['error'] ? ': '.$recap['error'] : '' }}
@endforeach
@endif

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
