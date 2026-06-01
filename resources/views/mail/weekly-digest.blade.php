@component('mail::message')
# Рекап: {{ $race->name }}

Ето как мина последното състезание и къде си в класирането на прогнозите.

## Подиум
@component('mail::table')
| Поз. | Пилот |
| :--- | :--- |
@foreach ($recap as $row)
| {{ $row['position'] }} | {{ $row['driver'] }}{{ $row['fastest_lap'] ? ' ⏱️ (най-бърза обиколка)' : '' }} |
@endforeach
@endcomponent

## Твоята статистика този сезон
- Точки: **{{ $userStats['points'] }}**
- Подадени прогнози: **{{ $userStats['predictions'] }}**
- Най-добър резултат: **{{ $userStats['best'] }}** точки
- Среден резултат: **{{ $userStats['average'] }}** точки

## Класиране (топ 10)
@component('mail::table')
| # | Играч | Точки |
| :--- | :--- | :--- |
@foreach ($leaderboard as $entry)
| {{ $entry['position'] }} | {{ $entry['user']->name }} | {{ $entry['points'] }} |
@endforeach
@endcomponent

@component('mail::button', ['url' => url('/leaderboard')])
Виж пълното класиране
@endcomponent

До следващото състезание! 🏁<br>
Екипът на F1 България
@endcomponent
