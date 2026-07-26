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
ARTISAN="sudo -u www-data php artisan"

cd "$APP_DIR"

echo "→ Изтегляне на кода"
sudo -u www-data git pull --ff-only

echo "→ PHP зависимости"
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction

echo "→ Frontend билд (клиент + SSR bundle)"
# npm run build прави ziggy:generate + vite build + vite build --ssr.
# ziggy:generate чете production .env, така че APP_URL в bundle-а е верният.
sudo -u www-data npm ci
sudo -u www-data npm run build

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
sudo supervisorctl restart padok-ssr

echo "→ Проверка"
sleep 3
$ARTISAN inertia:check-ssr
curl -fsS -o /dev/null -w "  HTTP %{http_code}\n" https://padok.bg/

echo "✓ Готово"
