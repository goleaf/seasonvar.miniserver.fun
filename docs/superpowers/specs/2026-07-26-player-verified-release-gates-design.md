# Design: проверенные форматы и единая версия player release

Дата: 26.07.2026

Задача: Task 102

## Цель

Не допускать новый media format или отдельную дорожку в публичный playback по
одной декларации, расширению URL либо browser capability; одновременно
гарантировать, что PHP-контракт проигрывателя и скомпилированные JS/CSS/assets
выпускаются как одна проверяемая версия.

## Подтверждённое исходное состояние

- Runtime: PHP 8.5, Laravel 13.22, Livewire 4.3, SQLite.
- Frontend: Blade/Livewire, Tailwind 4.3, Vite 8.1, Plyr 3.8, HLS.js 1.6.
- Текущий SQLite snapshot содержит только явно импортированный `mp4`.
- Existing health contract хранит `check_status`,
  `last_successful_check_at` и bounded повторные проверки.
- Независимых subtitle/audio track URL или bodies в schema нет.
- Chromium реально воспроизводит live MP4; Firefox на live provider получил
  сетевой reset после `206`, поэтому provider evidence не доказывает app
  incompatibility. Физических iOS/Android устройств в среде нет.

## Решение

### 1. Verified-format boundary

`config/playback.php` объявляет established formats. На текущих данных это
только `mp4`; он сохраняет прежнюю совместимость даже до health-check.

`LicensedMedia::withVerifiedPlaybackFormat()` централизует SQL boundary:

- established format проходит;
- historical `NULL`/empty format остаётся compatibility candidate;
- явный новый format проходит только при `check_status=available` или
  непустом `last_successful_check_at`.

Instance-проверка повторяет правило после server-side определения format.
`withoutKnownFailures()` включает этот scope, чтобы playback counts,
recommendations и public discovery не расходились с resolver.

Requested media identity загружается отдельно: корректная строка нового
неподтверждённого format возвращает контролируемую unavailable/`503`, а не
ложный `404`. Automatic candidates фильтруются до bounded `limit(100)`.

### 2. Player release record

Tracked `resources/player-release.json` содержит schema и точный список
repository source-файлов player contract. Vite post-plugin:

1. валидирует descriptor;
2. вычисляет детерминированный source fingerprint по path и SHA-256;
3. после генерации bundle считает SHA-256/bytes каждого output;
4. выпускает `public/build/player-release.json`.

Публичный record не содержит исходный код, private absolute paths или
credentials.

Laravel `PlayerReleaseReadiness` независимо:

- проверяет descriptor, safe relative paths, отсутствие symlink и выходов из
  project/build root;
- пересчитывает source fingerprint;
- обходит Vite manifest от `resources/js/app.js` через
  `imports`/`dynamicImports`/`css`/`assets`;
- требует достижимый player entry и совпадение всех записанных hashes/bytes.

Команда `player:release-check --json` возвращает ненулевой exit code при любой
несогласованности. `npm run build` выполняет Vite и затем эту команду.

### 3. Cache generation

`PublicPageCachePolicy::assetBuildFingerprint()` объединяет Laravel
`Vite::manifestHash()` и hash player release record. Это меняет generation
guest HTML после согласованного player build, не кеширует signed URLs и не
создаёт broad cache flush.

### 4. Browser evidence

Default Playwright matrix получает `Desktop Firefox`, ограниченный
`player-lifecycle.spec.js`. Новый test воспроизводит локальный progressive MP4
fixture, ждёт media `play`, продвижение `currentTime`, достаточный
`readyState`, отсутствие `HTMLMediaElement.error` и проверяет Range response.

WebKit не включается в обязательную матрицу этой среды: установленный browser
не имеет необходимого host codec package. Viewport projects проверяют только
responsive behavior. Физические устройства остаются `unresolved_device`.

## Неизменяемые contracts

- `/titles/{catalogTitle:slug}`, `playback.source` и API playback routes;
- query keys `season`, `episode`, `media`, `variant`, `quality`, `format`;
- один Livewire player и один Plyr/HLS lifecycle;
- signed URL, entitlement, fallback, progress, reporting и download boundary;
- MP4 rows до/без availability check;
- PWA запрет offline video/signed playback cache;
- русско-английский translation key parity.

## Database, production и rollback

Migration/индекс не требуются: используются существующие indexed/selected
health columns, а новый predicate не вводит новый join или sort. Проверка
EXPLAIN выполняется на фактическом SQL.

Deploy order: получить backup согласно runbook, выполнить production asset
build, запустить `player:release-check --json`, затем штатно обновить caches и
workers. Нельзя публиковать только PHP или только `public/build`.

Rollback: вернуть Task 102 commit и собрать старый asset release целиком.
Schema/data не меняются. Уже подтверждённые timestamps не удаляются.

## Риски

- Старые `NULL format` остаются permissive ради совместимости, но resolver
  продолжает URL/MIME/allowlist/health validation.
- Provider/browser codec поведение нельзя доказать fixture-тестом для всех
  реальных устройств.
- Полный suite может содержать регрессии параллельных workstreams; они
  фиксируются отдельно и не маскируются.
