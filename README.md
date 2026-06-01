# F1 България — Community платформа

Общностен сайт за българските фенове на Формула 1: календар, класиране,
prediction league и блог. Соло проект.

## Стак
Laravel 11 · Inertia + Vue 3 (`<script setup>`) · Tailwind v3 · Filament 3 ·
MySQL 8 · Pest. Локали: само `bg`. Време: UTC в базата, Europe/Sofia в UI.

## Локален старт

```bash
composer install
npm install

# 1. MySQL 8 база (виж .env за креденшъли)
mysql -u root -e "CREATE DATABASE f1bulgaria CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Миграции + базови данни (значки + админ акаунт)
php artisan migrate:fresh --seed

# 3. Първоначален синхрон на данните от Jolpica
php artisan f1:sync-season 2024

# 4. Front-end
npm run dev   # или npm run build
```

Админ акаунт от сийдъра: `itcashbroker@gmail.com` / `password` (`is_admin = true`).
Панелът е на `/admin`.

## Artisan команди (автоматизация)

| Команда | Какво прави | Разписание |
| --- | --- | --- |
| `f1:sync-season {year?}` | Пълен синхрон: конструктори, пилоти, календар, сесии | ръчно |
| `f1:sync-results {race?}` | Резултати + pole + точкуване на прогнози + значки | на час |
| `f1:lock-predictions` | Заключва прогнозите 5 мин преди квалификацията | всяка минута |
| `f1:weekly-digest` | Неделен рекап + leaderboard по имейл | неделя 20:00 (Sofia) |

Разписанието е в [routes/console.php](routes/console.php). На сървъра е нужен
само един cron ред: `* * * * * php artisan schedule:run`.

## Архитектура

- **Услуги** (`app/Services`) държат бизнес логиката; контролерите са тънки.
  - `Jolpica\JolpicaClient` — HTTP клиент към `api.jolpi.ca` (Ergast-съвместим).
  - `Jolpica\SeasonSyncService`, `Jolpica\ResultSyncService` — идемпотентен синхрон.
  - `Predictions\PredictionScoringService` — точкуване (схема в `config/predictions.php`).
  - `Predictions\PredictionLockService`, `Predictions\LeaderboardService`.
  - `Standings\StandingsService`, `Badges\BadgeService`.
- **Form Requests** за валидацията, **Resource** класове за Inertia props.
- **Filament** (`app/Filament/Resources`): постове (CRUD), потребители (модерация:
  бан/админ), и F1 данни (сезони/състезания/пилоти/конструктори/резултати) за
  ръчни корекции. Редакцията на резултат/състезание преизчислява точките.

## Точкова схема (по подразбиране, виж `config/predictions.php`)

P1 25 · P2 18 · P3 15 · пилот в топ3 на грешна позиция 5 · pole 10 ·
най-бърза обиколка 10 · точен брой DNF 10 / разлика 1 → 5 · safety car 10.

## Решения, отклоняващи се от първоначалната схема

1. `sessions` → **`race_sessions`** — Laravel вече ползва таблица `sessions`.
2. `favorite_driver_id` / `favorite_constructor_id` се добавят в отделна миграция
   след `drivers`/`constructors` (валидни външни ключове).
3. Към схемата са добавени: `jolpica_id` (drivers/constructors/races/results) и
   `driver_code` за cross-season статистика; `races.pole_driver_id` и
   `races.had_safety_car` (нужни за точкуване, последното — ръчно от админа);
   `users.is_admin` и `users.banned_at` (Filament достъп + модерация).

## Тестове

```bash
php artisan test   # Pest, върви на SQLite in-memory
```
Покрити: точкуване, заключване, синхрон (с фалшив HTTP), класиране, подаване на
прогноза + Breeze auth/profile.

## Извън MVP (нарочно)
Live чат/WebSockets (живее в Telegram), форум, fantasy, многоезичие, плащания,
социален login, мобилно приложение.
