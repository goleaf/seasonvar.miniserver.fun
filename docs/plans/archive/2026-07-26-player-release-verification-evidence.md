# Task 102 — evidence verified player release

Дата: 26.07.2026

Ветка: `main`

Owner lease: `task-102-player-release-verification`
Exact manifest: 39 путей; расширения 34 → 38 → 39 выполнены до edits
обнаруженных fixtures.

## Что проанализировано

- PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, Boost 2.4.13,
  PHPUnit 12.5.32, Pint 1.29.3, SQLite, Vite 8.1.4, Tailwind 4.3.2,
  Playwright 1.61.1, HLS.js 1.6.16 и Plyr 3.8.4.
- Web/API playback routes, `CatalogTitlePlayer`, resolver/query/model scopes,
  media health/importer contracts, signed delivery, public cache generation,
  Vite graph, browser lifecycle и PWA media deny boundary.
- Local catalog snapshot: только `mp4`; 248 published active MP4 rows без
  `check_status`/`last_successful_check_at` должны сохранять совместимость.
- Exact SQLite resolver SQL и `EXPLAIN QUERY PLAN`; existing
  `licensed_media_publication_lookup_idx` выбран по
  `catalog_title_id/status/audience/deleted_at`, season/episode subqueries
  используют primary key.

## Найденные проблемы и реализация

1. Allowlisted новый format мог участвовать в public availability без единого
   successful check. `LicensedMedia::scopeWithVerifiedPlaybackFormat()` и
   `hasVerifiedPlaybackFormat()` теперь оставляют established MP4
   совместимым, но требуют для нового format `check_status=available` либо
   `last_successful_check_at`. Resolver применяет gate до `limit(100)`;
   explicit неподтверждённый source отвечает `503`.
2. PHP/player source и Vite assets не имели единого проверяемого release ID.
   Descriptor содержит 29 source paths; Vite plugin после финальной записи
   chunks создаёт record 19 assets. `PlayerReleaseReadiness` проверяет schema,
   safe paths, roots, отсутствие symlink, source fingerprint, manifest graph,
   bytes и SHA-256. `npm run build` включает Artisan readiness.
3. Public guest HTML generation зависела только от Vite manifest. Теперь она
   включает также SHA-256 player release record без изменения private/media
   cache boundary.
4. Browser CI не доказывал декодирование Firefox. Deterministic MP4 test
   вызывает `play()`, проверяет `readyState >= 2`, продвижение `currentTime`,
   `200/206` и отсутствие `media.error` в Desktop Chromium/Firefox.
5. Девять legacy API/Blade/Livewire fixtures создавали HLS без нового
   successful-check contract. Manifest расширялся до их edits; fixtures
   получили фактическое test evidence. Product rule не ослаблялся.
6. Отдельных проверенных audio/subtitle URL/body нет. Новые дорожки и fake
   controls не добавлены.

Новая migration, index, dependency, queue, environment variable, scheduler,
public route или production data mutation не потребовались.

## Созданные файлы

- `app/Console/Commands/CheckPlayerRelease.php`
- `app/Services/Operations/PlayerReleaseReadiness.php`
- `resources/player-release.json`
- `scripts/player-release-vite-plugin.js`
- `tests/Feature/VerifiedPlaybackFormatTest.php`
- `tests/Unit/PlayerReleaseReadinessTest.php`
- `docs/superpowers/specs/2026-07-26-player-verified-release-gates-design.md`
- `docs/superpowers/plans/2026-07-26-player-verified-release-gates.md`
- `docs/plans/task-102-player-release-verification-compliance.md`
- этот evidence-файл

## Изменённые файлы

- Backend/config/cache: `LicensedMedia`,
  `CatalogPlaybackSourceResolver`, `CatalogTitlePlaybackQuery`,
  `PublicPageCachePolicy`, `config/playback.php`.
- Build/browser: `package.json`, `vite.config.js`, `playwright.config.js`,
  `tests/browser/player-lifecycle.spec.js`.
- Tests/fixtures: `BrowserCiContractTest`, `PublicPageCachePolicyTest`,
  `SecurityHardeningTest`, API `CatalogTitleDetailTest`,
  `PlaybackDeliveryTest`, `PlaybackProgressTest`, `PlaybackSessionTest`,
  `CatalogBladeComponentTest`, `CatalogPageTest`.
- Documentation: canonical playback audit, cache/deployment/development/
  frontend/importer/performance/security owners, current plan, README и
  CHANGELOG.

## Verification

| Проверка | Фактический результат |
| --- | --- |
| RED format/release/cache/browser contracts | ожидаемо падали: unverified/degraded formats проходили, cache hash не менялся, readiness class отсутствовал, Firefox не был установлен |
| GREEN initial Task 102 matrix | 14 tests, 123 assertions |
| Related playback/API/security matrix | 27 tests, 219 assertions |
| Final focused/integration matrix до discovered fixtures | 39 tests, 343 assertions |
| Legacy API fixtures после health evidence | 16 tests, 162 assertions |
| Exact Blade HLS fixture | 1 test, 9 assertions |
| Exact Livewire transition HLS fixture | 1 test, 25 assertions |
| Final task-scoped PHP matrix | 47 tests, 400 assertions; exact Blade 1/9 и Livewire 1/25 также прошли |
| Scoped Pint | passed |
| Scoped PHPStan/Larastan | passed, 0 errors |
| Scoped Rector dry-run | passed, 0 changes/errors |
| Node syntax plugin/config/browser | passed |
| `npm run build` из isolated staged snapshot | passed; source fingerprint `ddfb3c28334938ee0a3395ccba51d50cb373fe3577053703b020a7bc78690300`, 29 sources, 19 assets |
| `php artisan player:release-check --json` | passed с тем же fingerprint/counts |
| `composer validate --strict` | manifest valid; только существующие root/plugin warnings |
| Playback route inspection | passed, 5 named playback routes |
| Focused browser decode | 2 passed за 18.7 s: Desktop Chromium и Desktop Firefox |
| Full browser lifecycle matrix | 44 tests: 2 passed, 20 skipped, 22 failed за 12.2 min |
| Default full PHP suite | stopped: XML-enforced 256M exhausted in foreign `DemoRasterAsset` fixture |
| Temporary 1G full PHP suite после всех Task 102 fixes | 2 184 tests; 2 156 passed; 206 903 assertions; 11 skipped; 16 failures; 1 error |

Full browser failures не скрыты и не исправлялись вне scope: authenticated
flows получают foreign `/pwa/posters/browser-smoke` 404; также остаются
параллельные progress/history hot-swap, duplicate retry control и
mobile/tablet overlay regressions. Decode tests Task 102 прошли оба.

Full PHP failures после Task 102 fixes: offline Blade infrastructure call;
два `CatalogBladeComponentTest`; четыре `CatalogPageTest`; три
`CatalogSearchPageTest`; `ExternalPlaylistImportTest`;
`LegacyTagSchemaCompatibilityTest`; два `LocalRateLimitRemovalTest`;
`SeasonvarTitleMergeTest`; `WebAccountManagementTest`; error
`SeasonvarImportDispatchBatcherTest` из-за отсутствующего параллельного
класса. Task 102 tests и исправленный player transition среди них отсутствуют.

Первый финальный Playwright rerun использовал неточный `--grep` и честно
завершился `No tests found`; команда сразу повторена с точным фрагментом
`decode and advance` и прошла 2/2 за 18.7 s.

## Security, data safety и rollback

- SQL строится Eloquent/query-builder bindings; raw user SQL не добавлен.
- Release record не содержит source code, URL поставщика, credentials, token
  или private path. Path traversal/symlink/hash/size/source drift fail-closed.
- Signed grants, entitlement, SSRF/DNS/host validation, CSRF, policies,
  private/no-store и PWA video denylist не ослаблены.
- Schema/data не менялись; backup/backfill/migration не требуются.
- Rollback: revert единого Task 102 commit и полный build предыдущей версии.
  Partial PHP/assets rollout запрещён.

## Оставшиеся ограничения

- Physical Android/iOS, hardware decoder, native iOS fullscreen и host WebKit
  codecs остаются `unresolved_device`; viewport emulation не названа real
  device evidence.
- Live provider в Firefox сбросил network response после `206`; local
  deterministic decode прошёл, provider failure не замаскирован blacklist.
- Foreign dirty worktree и его regressions не удалялись и не включаются
  автоматически в Task 102 index.
- Exact clean docs snapshot воспроизвёл baseline-дефект: отслеживаемый
  `2026-07-26-shared-main-workflow-evidence.md` ссылается на 10 ещё
  незакоммиченных PWA/statistics Markdown-файлов другого owner. Они не
  добавлены в Task 102 index; hook-проверка прошла во временном дереве с
  доступными shared-worktree ссылочными целями.
- Exact index approval, commit hash и ordinary push фиксируются финальным
  delivery report после проверки staged snapshot.
