@component('mail::message')
# Рекап: {{ $race->name_bg }}

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

{{--
    Човек без нито една прогноза не бива да получава таблица от нули —
    „точки 0, прогнози 0, най-добър резултат 0" е демотивиращо и заема
    мястото на единственото полезно нещо за него: поканата да започне.
--}}
@if ($userStats && $userStats['predictions'] > 0)
## Твоята статистика този сезон
@if (!empty($userStats['rank']))
- Позиция в лигата: **#{{ $userStats['rank'] }}** от {{ $userStats['players'] }} играчи
@endif
- Точки: **{{ $userStats['points'] }}**
- Подадени прогнози: **{{ $userStats['predictions'] }}**
- Най-добър резултат: **{{ $userStats['best'] }}** точки
- Среден резултат: **{{ $userStats['average'] }}** точки
@if (!empty($userStats['new_badges']))
- Нови значки тази седмица: **{{ implode(', ', $userStats['new_badges']) }}** 🏅
@endif
@elseif ($userStats)
## Още не си играл този сезон
Класирането отдолу е на хора, които просто са познали три имена преди старта. Отнема по-малко от минута: топ 3, пол позиция, най-бърза обиколка.
@endif

## Класиране (топ 10)
@component('mail::table')
| # | Играч | Точки |
| :--- | :--- | :--- |
@foreach ($leaderboard as $entry)
| {{ $entry['position'] }} | {{ $entry['user']->name }} | {{ $entry['points'] }} |
@endforeach
@endcomponent

@if ($f2)
## Ф2: уикендът на Никола Цолов 🇧🇬
{{ $f2['race'] }}:
@component('mail::table')
| Сесия | Резултат |
| :--- | :--- |
@foreach ($f2['results'] as $row)
| {{ $row['session'] }} | {{ $row['position'] !== null ? 'P'.$row['position'] : ($row['status'] ?? '—') }} |
@endforeach
@endcomponent
@if ($f2['standings_position'] !== null)
В шампионата: **#{{ $f2['standings_position'] }}** с **{{ $f2['points'] }} т.**
@endif
@endif

@if ($news !== [])
## От новините тази седмица
@foreach ($news as $item)
- [{{ $item['title'] }}]({{ $item['url'] }})
@endforeach
@endif

@if ($nextRace)
## Следващ кръг: {{ $nextRace['name'] }}
@if ($nextRace['deadline'])
Прогнозите се заключват **{{ $nextRace['deadline'] }}** — 5 минути преди квалификацията.
@endif
@endif

{{--
    Приоритетът на бутона следва това, което липсва на получателя: непознат
    има нужда от акаунт, играч без прогноза за следващия кръг — от прогноза,
    и чак редовният играч печели от класирането.
--}}
@if (! $userStats)
@component('mail::button', ['url' => url('/register')])
Включи се в prediction league
@endcomponent
@elseif ($nextRace)
@component('mail::button', ['url' => $nextRace['url']])
Дай прогноза за {{ $nextRace['name'] }}
@endcomponent
@else
@component('mail::button', ['url' => url('/leaderboard')])
Виж пълното класиране
@endcomponent
@endif

@include('mail.partials.community')

До следващото състезание! 🏁<br>
Екипът на Падок

@if ($unsubscribeToken)
<small>Получаваш този имейл като абонат на бюлетина на Падок. [Отпиши се]({{ route('newsletter.unsubscribe', $unsubscribeToken) }})</small>
@elseif ($userUnsubscribeUrl)
<small>Получаваш този имейл, защото имаш акаунт в Падок. [Спри имейлите]({{ $userUnsubscribeUrl }})</small>
@else
<small>Получаваш този имейл, защото имаш акаунт в Падок и участваш в prediction league.</small>
@endif
@endcomponent
