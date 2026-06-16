# F1 Bulgaria Hub — Audit Report

Date: 2026-06-16
Branch: main · HEAD `900da95` · Tests: **362 passed** (2 clean runs, no flakiness observed)
Stack: Laravel 13 · Inertia v2 · Vue 3 · Tailwind v3 · Filament 4 · MySQL 5.7 (dev) · Pest

This is a read-only audit. No data was modified. Findings are bucketed by launch priority; line references point to the code as of this commit.

---

## Critical Issues (must fix преди launch)

- [ ] **V1 scope not enforced** — every V2 route is publicly reachable and linked in nav (`compare`, `rivalries`, `circuits`, `tsolov`, `istoria`, `f2/*`, `live`). Phase 2 deliverable: feature flags + nav cleanup + 404 on disabled features.
- [ ] **`POST /newsletter/subscribe` has no rate limiting** — [routes/web.php:73](routes/web.php#L73). Public, unauthenticated endpoint → spam/enumeration vector. Add `throttle:`.
- [ ] **Homepage has no `<h1>`** — [resources/js/Pages/Home.vue](resources/js/Pages/Home.vue) only has `h2`s ("Топ новини", "Разгледай"). Breaks SEO heading hierarchy & a11y. Every other page has exactly one h1.

## Important (fix преди launch ideally)

- [ ] **Tsolov content stale** — [config/tsolov.php:22](config/tsolov.php#L22) `current_series => 'FIA Формула 3'`. He now races **F2 2026 for Campos Racing (#6)** — confirmed in our own F2 data. The `TsolovController` pulls live F2 stats, but the static config string is wrong. (Page is V2-hidden, but should still be accurate.)
- [ ] **Meta descriptions missing on ~17 public pages**; only [News/Show.vue](resources/js/Pages/News/Show.vue) sets a per-page description + canonical + og:title. All others fall back to the global default in [app.blade.php](resources/views/app.blade.php). No page sets **og:image** → social shares render blank.
- [ ] **Favicon missing** — no `<link rel="icon">` / `apple-touch-icon` in [app.blade.php](resources/views/app.blade.php) (manifest + theme-color are present).
- [ ] **Sitemap gaps** — [GenerateSitemapCommand.php](app/Console/Commands/GenerateSitemapCommand.php) omits **`news.show` (50 published articles!)** and `races.show`. It also currently lists V2 routes that V1 should drop (compare/rivalries/circuits/tsolov/history/f2/live). Regenerate for V1 scope in Phase 2.
- [ ] **Footer is placeholder** — [PublicLayout.vue:159](resources/js/Layouts/PublicLayout.vue#L159) Telegram link is `href="#"`; no Privacy / Terms / Contact links.
- [ ] **Cold page loads ~1s** (dev server, no opcache): `/circuits/monaco` 1.38s, `/` 0.97s, `/teams` 0.95s, `/drivers/{slug}` 0.90s. Warm-cache drops these to <0.6s, so service caching works — but Home/Drivers cache least. Add homepage caching + deploy-time cache warmup.

## Nice to Have (V1 polish)

- [ ] **Dead scaffold**: [resources/js/Pages/Welcome.vue](resources/js/Pages/Welcome.vue) (386 lines, legacy Breeze) — not routed anywhere.
- [ ] **Unused `posts` table + Filament Posts resource** — News is backed by `team_news_items` (50 rows); `posts` is 0 rows and unused.
- [ ] **Minor query cleanups**: [StandingsController.php:38](app/Http/Controllers/StandingsController.php#L38) redundant `loadMissing('constructor')` (already eager-loaded); [CircuitsController.php:71-79](app/Http/Controllers/CircuitsController.php#L71) three separate `Race` queries that could be one fetch.
- [ ] **Thin test coverage**: no `Leaderboard` feature test; single test each for Compare/Tsolov/Calendar/History.
- [ ] **Loading states**: no skeletons/deferred props anywhere; Live page polls every 5s without a skeleton.
- [ ] **18 zero-race canonical teams** in `constructors_canonical` — all `is_active=0` and already hidden from public lists/sitemap (`total_races > 0` filter). Data-cleanup candidate, not a blocker.

## V2+ Hidden (not in V1) — enforced in Phase 2

| Feature | Routes | Action |
|---|---|---|
| Compare | `compare.index`, `compare.show` | nav link removed, 404 when flag off |
| Rivalries / Дуели | `rivalries.*` | nav removed, 404 when off |
| Circuits / Писти | `circuits.index`, `circuits.show` | nav removed, 404 when off |
| Tsolov | `tsolov` | nav removed, 404 when off |
| History / История | `history`, `history.world`, `history.bulgaria` | nav removed, 404 when off |
| Formula 2 | `f2`, `f2.*` | nav removed, 404 when off |
| Live timing | `live`, `live.refresh` | nav removed, 404 when off |
| "На този ден" widget | Home section | hidden via flag |
| Live session banner | Home section | hidden via flag |

---

## Routes Inventory

V1 = ships at launch. V2 = code kept, hidden behind feature flag (404 when disabled). Sys = auth/infra.

| URL | Controller@method | Scope | Auth | Status |
|---|---|---|---|---|
| `/` | HomeController@index | **V1** | no | 200 |
| `/news`, `/news/{slug}` | NewsController@index/show | **V1** | no | 200 |
| `/calendar` (+ `.ics` feeds) | CalendarController | **V1** | no | 200 |
| `/standings`, `/standings/{year}` | StandingsController@index | **V1** | no | 200 |
| `/teams`, `/teams/{slug}` | TeamsController | **V1** | no | 200 |
| `/drivers`, `/drivers/{slug}` | DriversController | **V1** | no | 200 |
| `/terminologiya` | TerminologyController@index | **V1** | no | 200 |
| `/predictions` | PredictionController@index | **V1** | yes | 200 |
| `/races/{race}` | RaceController@show | **V1** | no | 200 |
| `/races/{race}/prediction` | PredictionController@store | **V1** | yes | POST |
| `/leaderboard` | LeaderboardController@index | **V1** | no | 200 |
| `/profiles/{user}` | PublicProfileController@show | **V1** | no | 200 |
| `/newsletter/*` | NewsletterController | **V1** | no | 200/POST |
| `/circuits`, `/circuits/{slug}` | CircuitsController | V2 | no | 200 |
| `/compare`, `/compare/{s1}/{s2}` | CompareController | V2 | no | 200 |
| `/rivalries`, `/rivalries/{slug}` | RivalriesController | V2 | no/auth | 200 |
| `/tsolov` | TsolovController@index | V2 | no | 200 |
| `/istoria`, `/istoria/*` | HistoryController | V2 | no | 200 |
| `/f2`, `/f2/*` | F2*Controller | V2 | no | 200 |
| `/live`, `/live/refresh` | LiveTimingController | V2 | no | 200 |
| `/login` `/register` `/profile` `/dashboard` `/forgot-password` … | Auth/Profile | Sys | mixed | — |
| `/admin/*` | Filament | Sys | admin | gated |

Orphaned routes: none — every route is either linked in nav, used as a sub-resource (race detail, prediction store, profile), or system/admin.

---

## Database Health

| Table | Rows | Note |
|---|---|---|
| seasons | 77 | 1950→2026; current = **2026** (22 races, 5 with results) |
| drivers / drivers_canonical | 3271 / 879 | **0 orphans**, **0 duplicate slugs** ✓ |
| constructors / constructors_canonical | 1132 / 213 | **0 orphans**, **0 duplicate slugs** ✓ |
| races / race_sessions / results | 1171 / 2855 / 25933 | — |
| team_news_items / sources | 50 / 5 | **News content source** (publicly visible) |
| posts | 0 | unused legacy table |
| f2_seasons / races / sessions / results | 10 / 28 / 31 / 674 | only 2025+2026 synced; 8 empty seed seasons |
| f2_drivers / f2_teams | 61 / 38 | Tsolov present (#6, BUL, Campos, 2026) ✓ |
| predictions / prediction_scores | 1 / 0 | pre-launch |
| newsletter_subscribers | 0 | pre-launch |
| users | 2 | — |
| rivalries | 8 | V2 |
| badges / badge_user | 5 / 0 | — |

Integrity checks: no orphaned drivers/constructors (all have `canonical_id`); no duplicate canonical slugs; no "AyGu"-style parsing artifacts; "Cadillac F1 Team" has 5 races (legit 2026 entrant). 18 zero-race canonical teams exist but are inactive and hidden.

---

## Performance Baseline

Dev server (`artisan serve`, no opcache — pessimistic vs production). TTFB:

| Page | Cold | Warm | Verdict |
|---|---|---|---|
| `/circuits/monaco` | 1.38s | 0.60s | slowest cold; 3 separate Race queries + stats service |
| `/` (home) | 0.97s | 0.58s | topNews/live not cached |
| `/teams` | 0.95s | 0.35s | cache effective |
| `/drivers/lewis-hamilton` | 0.90s | 0.42s | cache effective |
| `/standings/2024` | 0.54s | — | ok |
| `/calendar` | 0.49s | — | ok |
| `/news` | 0.35s | — | fast |

Caching: `DriverStatsService` & `CircuitStatsService` cache aggressively (1h–1d). Home/News indexes don't cache. Recommendation: cache homepage `topNews`, add deploy-time cache warmup (see LAUNCH.md). Production opcache will materially reduce cold times.

---

## Security Findings

| Area | Status | Detail |
|---|---|---|
| Filament `/admin` gate | ✓ Secure | `User::canAccessPanel()` = `is_admin && banned_at === null` ([User.php:59](app/Models/User.php#L59)) |
| Secrets in git | ✓ Clean | `.env*` git-ignored; only `.env.example` tracked; all config uses `env()`, no hardcoded keys |
| CSRF | ✓ Default | standard web group; no `$except` bypasses; Filament adds `VerifyCsrfToken` |
| Login throttle | ✓ OK | `LoginRequest` uses `RateLimiter` (5 attempts) ([LoginRequest.php:63](app/Http/Requests/Auth/LoginRequest.php#L63)) |
| Email verification | ✓ OK | `throttle:6,1` |
| **Newsletter subscribe** | ✗ **Missing** | no throttle on public POST — **fix in Phase 3** |
| Register | ⚠ Minor | no throttle on `POST /register` — add `throttle` |
| Exposed data | ✓ Clean | public profile returns only name/bio/avatar/favorites/badges; User hides password+remember_token |

---

## Frontend & Mobile

- **Nav** ([PublicLayout.vue:11-27](resources/js/Layouts/PublicLayout.vue#L11)): static arrays `primaryNav` (8 links incl. live/circuits/tsolov) + `secondaryNav` "Повече ▾" dropdown (f2/rivalries/predictions/history/terminology), filtered by `route().has()`. Mobile shows all via `allItems`. → Phase 2 trims these to V1.
- **88 Vue files**; largest: Welcome.vue (386, dead), Live/Index.vue (215), Compare/Show.vue (173), PublicLayout.vue (162), Tsolov.vue (135).
- **Branding**: consistently "F1 България" (Cyrillic) — no "Hub"/English variant in UI yet (rebrand later).
- **Content**: fully Bulgarian; no English remnants, no lorem/placeholder text (besides footer `href="#"`).
- **Mobile**: nav has a working mobile menu (`allItems`); responsive grids throughout. Live manual verification of touch-target sizes / per-page mobile layout deferred (requires device/Playwright) — no obvious broken layouts in component review.

---

## Test Coverage (75 files, 362 tests)

Well-covered: F2 (13), News (12), Auth (6), Jolpica sync (5), Drivers (5), LiveTiming (4), Teams/Predictions/Canonical (3 each), Standings/Rivalries/Newsletter/Hero/Circuits (2 each). Singles: Tsolov, Terminology, Seo, Races, Profile, Homepage, History, Compare, Calendar. **Gaps**: no Leaderboard feature test. No skipped/incomplete tests found.
