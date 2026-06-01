# Проект: F1 България Community платформа

## Контекст
Соло разработка, страничен проект: общностен сайт за българските фенове на Формула 1. Аз (Румен) съм единственият човек по проекта — код, съдържание, модерация, общност. Планът трябва да уважава това:
- Агресивно намален скоуп
- Максимална автоматизация на повтарящи се задачи
- Никакви фийчъри, изискващи real-time човешка модерация в MVP

## Стак (непроменим)
- Laravel 11 (последна стабилна)
- Inertia.js + Vue 3 (Composition API, `<script setup>`)
- Tailwind CSS v3
- Filament 3 за админ панел
- MySQL 8
- Pest за тестове
- Деплой: Hetzner Cloud (Ubuntu 22.04)

ЗАБЕЛЕЖКА: Laravel Reverb / WebSockets са ИЗВЪН MVP. Real-time чат живее във външен Telegram канал засега.

## Конвенции
- PHP 8.3, PSR-12, strict types където е разумно
- Използвай Laravel Boost MCP за справки по Laravel документация
- Използвай Context7 MCP за справки по библиотеки от трети страни (Inertia, Filament и т.н.) — никога не разчитай на памет за API-та на библиотеки
- Form Requests за цялата валидация
- Resource класове за API отговори
- Service класове за бизнес логика, контролерите да са тънки
- Eloquent над raw query builder, освен ако performance не го налага
- Vue компоненти: PascalCase, single-file, composables за споделена логика
- Само български език (`bg` locale), без i18n setup
- Timezone: всичко в базата UTC, показвай в Europe/Sofia

## MVP Скоуп (строго, в този ред)
1. Auth + профили (Breeze + Inertia)
2. F1 data sync от Jolpica API (`https://api.jolpi.ca/ergast/f1/`) — сезони, състезания, пилоти, конструктори, резултати
3. Публични страници: календар на състезанията, класиране (пилоти + конструктори), детайли на състезание
4. Prediction league: топ 3, pole, най-бърза обиколка, брой DNF, safety car (да/не)
5. Профилни страници със статистика на прогнозите и badges
6. Админ панел чрез Filament: CRUD на постове, модерация на потребители, ръчни корекции на данни

## ИЗРИЧНО ИЗВЪН MVP
- Live race threads / WebSockets / чат (използвай външен Telegram)
- Форум / коментари под постове
- Fantasy league
- Многоезичие
- Плащания / абонаменти
- Социален login (само email в началото)
- Мобилно приложение

## Схема на базата (първоначални миграции)
- `users` (Breeze default + `favorite_driver_id`, `favorite_constructor_id`, `bio`, `avatar_path`)
- `seasons` (year, is_current)
- `constructors` (name, slug, color_hex, season_id)
- `drivers` (first_name, last_name, slug, permanent_number, constructor_id, country_code, season_id)
- `races` (season_id, round, name, circuit, country, race_datetime_utc, qualifying_datetime_utc, sprint_datetime_utc nullable, has_sprint bool)
- `sessions` (race_id, type [fp1|fp2|fp3|qualifying|sprint_quali|sprint|race], scheduled_at_utc)
- `results` (