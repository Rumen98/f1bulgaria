<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Коментираме уикендите на живо в [Telegram канала](https://t.me/padokbg).

© {{ date('Y') }} Падок — [padok.bg]({{ config('app.url') }})

Независим фен проект, несвързан с Formula One Group или FIA.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
