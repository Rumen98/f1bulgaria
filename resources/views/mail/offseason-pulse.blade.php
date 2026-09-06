@component('mail::message')
# Пауза е — но не съвсем 📻

@if ($countdown)
До **{{ $countdown['race'] }}** остават **{{ $countdown['days'] }} дни** ({{ $countdown['when'] }}, софийско време).
@endif

@if ($news !== [])
## Какво се случи междувременно
@foreach ($news as $item)
- [{{ $item['title'] }}]({{ $item['url'] }})
@endforeach
@endif

@if ($standings !== [])
## Върхът на класирането
@component('mail::table')
| # | Пилот | Точки |
| :--- | :--- | :--- |
@foreach ($standings as $row)
| {{ $row['position'] }} | {{ $row['driver'] }} | {{ $row['points'] }} |
@endforeach
@endcomponent
@endif

{{--
    Получателят без токен е потребител с акаунт — за него единственото действие, което има стойност, е прогнозата. Без обявен следващ кръг няма за какво да се прогнозира, затова тогава падаме към календара. --}}
@if ($unsubscribeToken === null)
@if ($countdown)
@component('mail::button', ['url' => route('predictions.index')])
Дай прогноза за {{ $countdown['race'] }}
@endcomponent

Отнема по-малко от минута: топ 3, пол позиция, най-бърза обиколка. Прогнозите се заключват 5 минути преди квалификацията.
@else
@component('mail::button', ['url' => route('calendar')])
Виж календара
@endcomponent
@endif
@else
@component('mail::button', ['url' => url('/register')])
Включи се в prediction league
@endcomponent
@endif

@include('mail.partials.community')

До скоро на пистата!<br> Екипът на Падок

@if ($unsubscribeToken)
<small>Получаваш този имейл като абонат на бюлетина на Падок. [Отпиши се]({{ route('newsletter.unsubscribe', $unsubscribeToken) }})</small>
@elseif ($userUnsubscribeUrl)
<small>Получаваш този имейл, защото имаш акаунт в Падок. [Спри имейлите]({{ $userUnsubscribeUrl }})</small>
@endif
@endcomponent
