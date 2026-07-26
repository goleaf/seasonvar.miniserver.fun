# План реализации Task 102: verified player release gates

Статусы: `pending`, `in_progress`, `completed`, `skipped`, `unresolved`.

| Priority | Status | Что и почему | Файлы/зависимости | Риск | Проверка |
| --- | --- | --- | --- | --- | --- |
| critical | completed | Перечитать AGENTS, requirements index, player/import/frontend/security/cache/production/maintenance/PWA owners и фактический код | `AGENTS.md`, `docs/requirements/*`, тематические docs, Laravel Boost | пропустить постоянный contract | список owners, Boost application info/docs |
| critical | completed | Проверить main/remote/dirty tree и дождаться предыдущего владельца player paths | Git/lease scripts | смешать commits общей main | exclusive lease Task 102 |
| critical | completed | Объявить точный NUL path manifest и сохранить baseline shared dirty files; расширять до правки каждого найденного legacy fixture | 39 declared paths, temporary baseline | захватить чужие hunks | lease `paths_declared=yes`, baseline exists |
| critical | completed | Сначала закрепить постоянное правило в canonical player owner | `docs/audits/video-playback-report.md` | расходящийся implementation | повторное чтение owner |
| critical | completed | Зафиксировать design, этот checklist, current plan и compliance matrix | `docs/superpowers/*`, `docs/plans/*` | устаревший scope | current-plan policy и перечитывание |
| critical | completed | Написать RED feature tests established/unverified/verified/degraded formats | `tests/Feature/VerifiedPlaybackFormatTest.php`, related resolver tests | принять status без реального evidence | targeted PHPUnit сначала падал |
| high | completed | Написать RED release-readiness и cache fingerprint tests | `tests/Unit/PlayerReleaseReadinessTest.php`, `PublicPageCachePolicyTest.php` | хешировать не тот graph | missing/tampered/source/symlink cases |
| high | completed | Написать RED browser/CI contract и Firefox media decode smoke | browser contract/lifecycle, Playwright config/package | flaky provider test | deterministic local fixture |
| critical | completed | Реализовать единый Eloquent/instance format gate и применить до bounded candidate limit | model/query/resolver/config | сломать legacy MP4 или requested status | focused feature tests и SQL |
| high | completed | Реализовать tracked source descriptor и Vite post-build record | resource descriptor, Vite plugin/config | nondeterministic record | final on-disk hashes |
| high | completed | Реализовать fail-closed readiness service/command и подключить к build | operations service, Artisan command, package script | path traversal/symlink/partial build | unit cases и command JSON exit |
| high | completed | Включить release hash в guest HTML cache generation | `PublicPageCachePolicy` | private/signed cache leak | unit test только public asset dimension |
| high | completed | Добавить Desktop Firefox только для player lifecycle и фактический MP4 play smoke | Playwright config/test/install script | умножить весь CI suite | project `testMatch`, Chromium+Firefox run |
| medium | completed | Проверить query plan и отказаться от speculative DDL без evidence | SQLite EXPLAIN/schema | write overhead лишнего index | existing index подтверждён |
| high | completed | Запустить focused tests, Pint, build/readiness и browser matrix | existing PHP/npm tools | скрытая regression | фактические exit codes/results |
| high | completed | Запустить related и full suite, отделить Task 102 errors от foreign state | PHPUnit/browser/static scans | ложный green | Task 102 fixtures fixed; foreign outcomes listed |
| medium | completed | Обновить тематические docs, README и русский CHANGELOG | declared docs | неактуальный runbook | documentation diff/re-read |
| critical | pending | Повторно прочитать requirements, проверить diff/secrets/debug и создать exact alternate index от HEAD | Git/lease | staged foreign hunk | staged manifest/hash approval |
| critical | pending | Commit только в main и ordinary push configured remote | `main`, origin | auth/dirty pre-push failure | commit hash и фактический push output |

## Cross-feature impact

| Domain | Status | Evidence/strategy |
| --- | --- | --- |
| Authentication/authorization | unaffected | signed grant и entitlement services не меняются |
| Translations/accessibility | unaffected | новых user-facing controls/keys нет; browser test использует существующую UI |
| Caching | affected | только asset generation получает release hash; signed/media cache запрещён |
| Search/recommendations/discovery | affected | общий `withoutKnownFailures` исключает неподтверждённый новый format |
| SEO/sitemap | unaffected | route/canonical/structured data не меняются |
| Privacy/security | affected | fail-closed paths/hashes; record без URL/secrets/source body |
| Mobile/physical devices | unresolved | responsive Chromium остаётся; физических устройств нет |
| Administration/audit | unaffected | write controls и audit events не меняются |
| Imports/media health | affected | existing successful availability evidence становится gate |
| Premium/region/legal | unaffected | entitlement boundary не меняется |
| PWA | affected | build assets проверяются, но video/signed playback cache остаётся запрещён |
| Production operations | affected | asset build/check становятся одной release gate; deploy не выполняется |

## Expected changed files

Точный список принадлежит lease Task 102 и объявлен NUL-separated manifest до
редактирования. Он включает model/query/resolver/config, release
service/command/plugin/descriptor, Vite/Playwright/package contracts,
focused tests, canonical/thematic docs, README, CHANGELOG и evidence.

## Rollback

Revert Task 102 commit и выполнить полный production build прежней версии.
Миграций/data backfill/новых dependency нет. Частичный rollback PHP или assets
запрещён той же readiness boundary.
