@component('mail::message')
# Този уикенд: {{ $race->name_bg }} 🏁

{{ $race->circuit }}, {{ $race->country }}{{ $race->has_sprint ? ' · спринтов уикенд' : '' }}

@if ($stake)
{{ $stake }}
@endif

## Програма (софийско време)
@component('mail::table')
| Сесия | Кога |
| :--- | :--- |
@foreach ($program as $row)
| {{ $row['label'] }} | {{ $row['when'] }} |
@endforeach
@endcomponent

@if ($unsubscribeToken === null)
@if ($deadline)
Прогнозите се заключват в **{{ $deadline }}** — не чакай последния момент.
@endif
@component('mail::button', ['url' => route('races.show', $race)])
Подай прогноза
@endcomponent
@else
Прогнозираш топ 3, pole позиция, най-бърза обиколка и още — и се бориш за върха на класирането.
@component('mail::button', ['url' => url('/register')])
Включи се в prediction league
@endcomponent
@endif

@include('mail.partials.community')

Успешен уикенд!<br>
Екипът на Падок

@if ($unsubscribeToken)
<small>Получаваш този имейл като абонат на бюлетина на Падок. [Отпиши се]({{ route('newsletter.unsubscribe', $unsubscribeToken) }})</small>
@elseif ($userUnsubscribeUrl)
<small>Получаваш този имейл, защото имаш акаунт в Падок. [Спри имейлите]({{ $userUnsubscribeUrl }})</small>
@endif
@endcomponent
