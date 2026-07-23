@component('mail::message')
# Рекап: {{ $race->name }}

@if ($userStats)
Ето как мина последното състезание и къде си в класирането на прогнозите.
@else
Ето как мина последното състезание във Формула 1.
@endif

## Подиум
@component('mail::table')
| Поз. | Пилот |
| :--- | :--- |
@foreach ($recap as $row)
| {{ $row['position'] }} | {{ $row['driver'] }}{{ $row['fastest_lap'] ? ' ⏱️ (най-бърза обиколка)' : '' }} |
@endforeach
@endcomponent

@if ($userStats)
## Твоята статистика този сезон
- Точки: **{{ $userStats['points'] }}**
- Подадени прогнози: **{{ $userStats['predictions'] }}**
- Най-добър резултат: **{{ $userStats['best'] }}** точки
- Среден резултат: **{{ $userStats['average'] }}** точки
@endif

## Класиране (топ 10)
@component('mail::table')
| # | Играч | Точки |
| :--- | :--- | :--- |
@foreach ($leaderboard as $entry)
| {{ $entry['position'] }} | {{ $entry['user']->name }} | {{ $entry['points'] }} |
@endforeach
@endcomponent

@if ($userStats)
@component('mail::button', ['url' => url('/leaderboard')])
Виж пълното класиране
@endcomponent
@else
@component('mail::button', ['url' => url('/register')])
Включи се в prediction league
@endcomponent
@endif

До следващото състезание! 🏁<br>
Екипът на Падок

@if ($unsubscribeToken)
<small>Получаваш този имейл като абонат на бюлетина на Падок. [Отпиши се]({{ route('newsletter.unsubscribe', $unsubscribeToken) }})</small>
@else
<small>Получаваш този имейл, защото имаш акаунт в Падок и участваш в prediction league.</small>
@endif
@endcomponent
