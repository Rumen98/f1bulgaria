# F1 България — Launch Guide

Pre-launch checklist + deploy/operations за production (Hetzner Cloud, Ubuntu).
V1 scope: пилоти, отбори, календар, новини, класиране, прогнози, терминология.
V2 модули са скрити зад feature flags (виж [config/features.php](config/features.php)).

---

## 1. Pre-launch checklist

**Сървър / среда**
- [ ] PHP 8.4+ (препоръчително 8.5, както на dev машината — [config/database.php](config/database.php) ползва `Pdo\Mysql`, наличен от 8.4), MySQL 8 (или 5.7), Nginx, Composer, Node 20+ инсталирани
- [ ] `.env` създаден от `.env.example` и попълнен за production: `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL=https://padok.bg`, `SESSION_SECURE_COOKIE=true`, database драйвери за session/queue/cache
- [ ] `php artisan key:generate` (ако `APP_KEY` е празен)
- [ ] `APP_URL=https://padok.bg` и реален SMTP за поща (newsletter/верификация)
- [ ] Всички `FEATURE_*` са `false` (V1 scope)

**База данни**
- [ ] `php artisan migrate --force`
- [ ] Backfill на F1 данните: `php artisan f1:sync-history 1950 2025` (минали сезони; бавно — пусни в nohup/screen) + `php artisan f1:sync-season` (текущ)
- [ ] СЛЕД всеки sync на нови сезони: `php artisan constructors:backfill-canonical && php artisan drivers:backfill-canonical` — иначе профилите на новите пилоти/отбори връщат 404 (канонични записи)
- [ ] (по избор) F2 данни: `php artisan f2:sync-wikipedia --year=all` — само ако `FEATURE_F2=true`
- [ ] Поне един админ: потребител с `is_admin=true` (виж раздел 9)

**Assets / кешове**
- [ ] `npm ci && npm run build`
- [ ] `php artisan config:cache route:cache view:cache event:cache`
- [ ] `php artisan sitemap:generate` → `public/sitemap.xml`
- [ ] Warmup на тежките кешове (раздел 6)

**Cron / scheduler** (критично — виж раздел 5)
- [ ] `* * * * * php artisan schedule:run` в crontab на deploy потребителя

**Сигурност**
- [ ] HTTPS активен (Let's Encrypt), HTTP→HTTPS redirect
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `.env` с права 600, собственост на deploy потребителя
- [ ] `/admin` достъпен само за `is_admin` (вече е gated в кода)

**Съдържание (виж раздел 11)**
- [x] og:image социален банер — генериран (`public/images/og-default.png` + мета тагове в app.blade.php)
- [ ] Телефон/имейл в страницата Контакт; текст на Поверителност и Условия
- [ ] Telegram линк (в момента сочи към `#` във футъра — добави реалния URL)

**Финална проверка**
- [ ] `php artisan test` — зелено (381 теста)
- [ ] Ръчно: V1 страниците връщат 200; V2 (`/f2`, `/circuits`, `/tsolov`, `/live`, `/istoria`, `/rivalries`, `/compare`) връщат 404
- [ ] `/up` връща 200 (health)

---

## 2. Първоначален деплой

```bash
git clone <repo> /var/www/f1bulgaria && cd /var/www/f1bulgaria
cp .env.example .env                       # после попълни тайните и production стойностите (раздел 1)
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate                   # ако APP_KEY е празен
php artisan storage:link
php artisan migrate --force
php artisan f1:sync-history 1950 2025       # backfill минали сезони (бавно; nohup/screen)
php artisan f1:sync-season                  # текущ сезон
php artisan sitemap:generate
php artisan config:cache route:cache view:cache event:cache
```

Nginx сочи към `public/`; задай crontab (раздел 5) и `queue` worker (раздел 7).

## 3. Рутинен деплой (нова версия)

```bash
cd /var/www/f1bulgaria
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache route:cache view:cache event:cache
php artisan sitemap:generate
php artisan up
```

> След промяна на routes/config ВИНАГИ `route:cache`/`config:cache` отново — иначе старите кеширани routes се сервират (вкл. feature middleware-а).

## 4. Включване на V2 модул след launch

Без code change — само `.env` + рекеширане:

```bash
# напр. пускане на Формула 2:
sed -i 's/FEATURE_F2=false/FEATURE_F2=true/' .env
php artisan config:cache route:cache
php artisan sitemap:generate    # вече включва F2 URL-ите
```

Навигацията, sitemap-ът и рутовете автоматично се съобразяват с флага.

## 5. Scheduler (cron) — задължително

Без това прогнозите не се заключват, резултатите не се синхронизират и новините спират.

```cron
* * * * * cd /var/www/f1bulgaria && php artisan schedule:run >> /dev/null 2>&1
```

Текущи задачи ([routes/console.php](routes/console.php)):
- `f1:lock-predictions` — всяка минута (заключва прогнозите преди квалификация)
- `f1:sync-results` — на час (резултати + точкуване + значки)
- `f1:weekly-digest` — седмичен рекап имейл
- `news:fetch` — 06:00 (RSS емисии); `news:enrich --limit=50` — 06:30 (LLM превод/класификация)

## 6. Cache warmup

Тежките stat-кешове (driver/circuit) се пълнят при първо посещение → първият посетител е бавен (~1s). Загрей след деплой:

```bash
php artisan sitemap:generate >/dev/null
# „удари" ключовите страници, за да напълниш кеша преди трафика:
for u in / /standings /teams /drivers/lewis-hamilton; do curl -s -o /dev/null "$APP_URL$u"; done
```

(Алтернатива за бъдеще: dedicated `cache:warm` команда. Засега горното е достатъчно.)

## 7. Опашка (queue)

`QUEUE_CONNECTION=database`. Пусни worker (systemd/supervisor):

```bash
php artisan queue:work --tries=3 --max-time=3600
```

Поща (newsletter double opt-in, верификация) минава през опашката.

## 8. DNS / HTTPS

- A/AAAA запис `padok.bg` → IP на сървъра (+ `www` CNAME → apex или redirect)
- Let's Encrypt (`certbot --nginx`), auto-renew (cron на certbot)
- Принудителен HTTPS redirect в Nginx; HSTS header
- След смяна на домейн → обнови `APP_URL`, [public/robots.txt](public/robots.txt) (Sitemap ред) и регенерирай sitemap

## 8а. Вход с Google (Socialite)

1. [Google Cloud Console](https://console.cloud.google.com/) → нов проект „Padok" →
   **APIs & Services → OAuth consent screen**: External, име „Падок", домейн padok.bg
2. **Credentials → Create OAuth client ID** → Web application:
   - Authorized JavaScript origins: `https://padok.bg`
   - Authorized redirect URIs: `https://padok.bg/auth/google/callback`
3. В `.env`:
   ```
   GOOGLE_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=...
   ```
4. `php artisan config:cache` — бутонът „Продължи с Google" на login/register проработва.

Забележки: Google акаунтите се създават с потвърден имейл (без наш верификационен
мейл); при съществуващ акаунт със същия имейл Google се свързва към него (без дубликат).

## 9. Админ достъп

Задай `ADMIN_EMAIL` и `ADMIN_PASSWORD` в `.env` и пусни:

```bash
php artisan padok:sync-admin
```

Командата създава/обновява акаунта (парола като hash, `is_admin=true`, потвърден имейл).
Пускай я след всяка промяна на креденшълите. Логваш се на `/admin` с тях.

По избор: `ADMIN_ACCESS_KEY` активира „скрита врата" — без `?key=` панелът връща 404
(празна стойност = изключена). Ръчно промотиране на друг акаунт:

```bash
php artisan tinker --execute 'App\Models\User::where("email","ti@padok.bg")->update(["is_admin"=>true]);'
```

## 10. Backup стратегия

- **БД**: дневен `mysqldump` (cron), retention 14–30 дни, off-server копие (S3/друг хост):
  ```cron
  30 3 * * * mysqldump --single-transaction f1bulgaria | gzip > /backups/db-$(date +\%F).sql.gz
  ```
- **Качени файлове**: `storage/app/public` (аватари/изображения) — седмичен rsync към off-server.
- `.env` — съхранявай тайните в password manager (НЕ в git).
- Тествай възстановяване поне веднъж преди launch.

## 11. Мониторинг

- **Health**: `GET /up` (Laravel) връща 200 — вържи към uptime monitor (UptimeRobot / Better Uptime), алерт по имейл/Telegram.
- **Грешки**: препоръчва се Sentry. Без нова зависимост засега; при нужда `composer require sentry/sentry-laravel` и `SENTRY_LARAVEL_DSN` в `.env`. Дотогава — следи `storage/logs` (daily channel, `LOG_LEVEL=warning`).
- **Uptime цели**: monitor на `/` и `/up` на 1–5 мин; алерт при 2 поредни fail-а.

## 12. Изоставащи задачи преди launch (съдържание, не код)

Маркирани в [AUDIT.md](AUDIT.md):
- [x] og:image социален банер (1200×630) + `<meta property="og:image">` в [app.blade.php](resources/views/app.blade.php) — готово
- [ ] Реален текст за Поверителност / Условия / Контакт ([StaticPageController](app/Http/Controllers/StaticPageController.php) + [Static/Page.vue](resources/js/Pages/Static/Page.vue))
- [ ] Telegram URL във футъра (виж [PublicLayout.vue](resources/js/Layouts/PublicLayout.vue))
