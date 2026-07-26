#!/usr/bin/env bash
#
# Деплой на padok.bg (Hetzner, Ubuntu).
#
#   sudo bash deploy.sh
#
# ВАЖНО: artisan ВИНАГИ като www-data. Root-run artisan оставя root-owned
# файлове в storage/ и bootstrap/cache/, php-fpm не може да пише в тях и
# сайтът връща 500 БЕЗ нищо в лога (защото и логърът не може да пише).
set -euo pipefail

APP_DIR="/var/www/f1bulgaria"
# -H задава HOME на www-data — npm и composer искат писаема HOME за кеша си.
RUN="sudo -H -u www-data"
ARTISAN="$RUN php artisan"

cd "$APP_DIR"

# --- 0. Собственост -----------------------------------------------------
# Ако някой е пускал git/artisan като root, файловете са root-owned и всяка
# следваща команда като www-data гърми ("dubious ownership", Permission denied).
# Оправяме го тук, вместо да гадаем после защо сайтът дава 500.
echo "→ Проверка на собствеността"
if [ "$(stat -c '%U' "$APP_DIR/.git")" != "www-data" ]; then
    echo "  .git е на $(stat -c '%U' "$APP_DIR/.git") — прехвърлям на www-data"
    chown -R www-data:www-data "$APP_DIR"
fi

# Git отказва да работи в директория с чужда собственост. --system (в
# /etc/gitconfig) важи и за root, и за www-data — --global би записал само в
# HOME на текущия потребител и другият пак ще гърми.
git config --system --add safe.directory "$APP_DIR" 2>/dev/null || true

echo "→ Изтегляне на кода"
$RUN git pull --ff-only

echo "→ PHP зависимости"
$RUN composer install --no-dev --optimize-autoloader --no-interaction

echo "→ Frontend билд (клиент + SSR bundle)"
# npm run build прави ziggy:generate + vite build + vite build --ssr.
# ziggy:generate чете production .env, така че APP_URL в bundle-а е верният.
$RUN npm ci
$RUN npm run build

echo "→ Миграции"
$ARTISAN migrate --force

echo "→ Кеширане на конфигурацията"
$ARTISAN optimize:clear
$ARTISAN config:cache
$ARTISAN route:cache
$ARTISAN view:cache

echo "→ Рестарт на SSR демона"
# Задължително СЛЕД билда: демонът зарежда bundle-а веднъж при старт и не
# следи файла. Без рестарт сервира стария компилиран Vue срещу новия клиентски
# bundle → hydration mismatch и разбъркан екран.
$ARTISAN inertia:stop-ssr || true
supervisorctl restart padok-ssr

echo "→ Проверка"
sleep 3
$ARTISAN inertia:check-ssr

# Не просто „процесът е жив", а „HTML-ът наистина съдържа съдържание".
articles=$(curl -fsS https://padok.bg/news | grep -o '<article' | wc -l)
if [ "$articles" -gt 0 ]; then
    echo "  SSR рендира ($articles статии в първоначалния HTML) ✓"
else
    echo "  ВНИМАНИЕ: 0 статии в HTML-а — сервира се клиентски рендер."
    echo "  Провери: supervisorctl status padok-ssr; tail storage/logs/ssr.log"
fi

echo "✓ Готово"
