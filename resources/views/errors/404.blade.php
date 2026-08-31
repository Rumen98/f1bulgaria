@extends('errors::minimal')

@section('title', __('Not Found'))
@section('code', '404')
@section('message', __('Not Found'))

@section('links-note', 'Може би търсиш някое от тези:')

{{--
    404 е нормална навигация, не срив — затова тук предлагаме посоки, вместо да
    оставяме човека с един линк към началото.

    Линковете са СТАТИЧНИ нарочно: error шаблонът не бива да прави заявки към
    базата, за да не гръмне точно когато нещо вече е счупено. Скритите зад
    feature флагове модули не се включват — те връщат 404 и биха водили тук пак.
--}}
@section('links')
    <a href="/news">Новини</a>
    <a href="/calendar">Календар</a>
    <a href="/standings">Класиране</a>
    <a href="/drivers">Пилоти</a>
    <a href="/teams">Отбори</a>
    <a href="/leaderboard">Прогнози</a>
@endsection
