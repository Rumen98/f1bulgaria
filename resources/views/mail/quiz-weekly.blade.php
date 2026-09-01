@component('mail::message')
# Новите въпроси са тук 🧠

Куизът на седмица **{{ $week }}** е готов — 10 въпроса, еднакви за всички.
Точка при първия верен отговор, втори опит няма: отговаряш, събираш точки
и чакаш следващия понеделник.

@if ($unsubscribeToken === null)
@component('mail::button', ['url' => url('/quiz')])
Реши ги
@endcomponent
@else
Точките и класацията искат акаунт — прави се за минута.
@component('mail::button', ['url' => url('/register')])
Регистрирай се и играй
@endcomponent
@endif

@if ($spotlight)
## Знаеш ли, че…

**{{ $spotlight['title'] }}** — {{ $spotlight['text'] }}

[Виж го тук]({{ $spotlight['url'] }})
@endif

@include('mail.partials.community')

Успешна седмица! 🏁<br>
Екипът на Падок

@if ($unsubscribeToken)
<small>Получаваш този имейл като абонат на бюлетина на Падок. [Отпиши се]({{ route('newsletter.unsubscribe', $unsubscribeToken) }})</small>
@elseif ($userUnsubscribeUrl)
<small>Получаваш този имейл, защото имаш акаунт в Падок. [Спри имейлите]({{ $userUnsubscribeUrl }})</small>
@endif
@endcomponent
