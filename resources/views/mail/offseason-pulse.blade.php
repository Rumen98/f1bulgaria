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

@if ($unsubscribeToken === null)
@component('mail::button', ['url' => route('calendar')])
Виж календара
@endcomponent
@else
@component('mail::button', ['url' => url('/register')])
Включи се в prediction league
@endcomponent
@endif

До скоро на пистата!<br>
Екипът на Падок

@if ($unsubscribeToken)
<small>Получаваш този имейл като абонат на бюлетина на Падок. [Отпиши се]({{ route('newsletter.unsubscribe', $unsubscribeToken) }})</small>
@elseif ($userUnsubscribeUrl)
<small>Получаваш този имейл, защото имаш акаунт в Падок. [Спри имейлите]({{ $userUnsubscribeUrl }})</small>
@endif
@endcomponent
