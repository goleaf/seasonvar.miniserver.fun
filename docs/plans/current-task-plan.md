# Текущая задача — Task 102: verified player release

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 102 — допуск media formats после успешной проверки, единая версия player code/assets и реальное декодирование в Chromium/Firefox | `completed` | Единственный owner lease `task-102-player-release-verification`; [design](../superpowers/specs/2026-07-26-player-verified-release-gates-design.md), [implementation plan](../superpowers/plans/2026-07-26-player-verified-release-gates.md), [compliance](task-102-player-release-verification-compliance.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |
| Физические iOS/Android устройства и host WebKit codecs | `unresolved` | На доступном host нет подключённых физических устройств, `adb`, `idevice_id`, `xcrun` и WebKit codec package; viewport emulation не выдана за device evidence |
| Full shared-tree regression suite | `unresolved` | Default `256M` исчерпан чужим demo raster fixture; финальный 1G run после Task 102 fixes: 2 184 tests, 2 156 passed, 11 skipped, 16 foreign failures и 1 foreign importer error |
| Exact clean documentation graph | `unresolved` | Отслеживаемый общий archive уже ссылается на 10 незакоммиченных документов PWA/statistics другого owner; они не добавлены в Task 102 index |
| HTTPS push текущей `main` | `unresolved` | Task 104 push был отклонён из-за отсутствия HTTPS username; Task 102 повторит обычный `git push origin main` после точного commit |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Один owner, exact manifest и проверяемый index | `completed` | Живой lease, NUL-delimited manifest расширен до 39 путей до новых edits, baseline сохранён вне repository |
| MP4 backward compatibility и подтверждение новых форматов | `completed` | `LicensedMedia::scopeWithVerifiedPlaybackFormat()`, resolver/query integration и focused regressions |
| Единая версия PHP/player assets | `completed` | Vite release plugin, `player-release.json`, fail-closed `PlayerReleaseReadiness` и `npm run build` gate |
| Реальное browser decode evidence | `completed` | Deterministic MP4 fixture продвигает `currentTime` без `media.error` в Desktop Chromium и Desktop Firefox |
| Новые audio/subtitle tracks только после фактической доступности | `already_compliant` | Отдельные реальные URL/body для новых дорожек не обнаружены; фиктивные дорожки не создаются |
| Schema/index/dependency change | `not_applicable` | Existing health columns и `licensed_media_publication_lookup_idx` покрывают новый query; migration/package install не требуются |
| Документация, final review, commit и push | `in_progress` | Тематические документы/evidence и точный alternate index завершаются после полного regression analysis |

## Последнее подтверждённое evidence

Текущее подтверждение сохранено в
[Task 102 evidence](archive/2026-07-26-player-release-verification-evidence.md).
Предыдущая итерация не удалена и остаётся в
[Task 104 evidence](archive/2026-07-26-player-workspace-redesign-evidence.md).
Подтверждение ранее активного редакционного workstream также сохранено в
[Task 101 evidence](archive/2026-07-26-collection-quality-evidence.md).

## Живой checklist

Полный список с приоритетом, причиной, файлами, зависимостями, риском и
проверкой находится в
[Task 102 implementation plan](../superpowers/plans/2026-07-26-player-verified-release-gates.md).

| Stage | Status | Verification |
| --- | --- | --- |
| Requirements/version/code/database/browser discovery | completed | Canonical docs, Boost, repository, SQLite и browser evidence |
| Exclusive owner и exact file manifest | completed | Task 102 lease, `paths_declared=yes`, 39 paths, external baseline |
| Format gate tests/implementation | completed | Focused PHPUnit и query-plan review |
| Atomic release tests/implementation | completed | Unit tests, `npm run build`, readiness JSON |
| Chromium/Firefox deterministic media decode | completed | Actual `play()`, `readyState`, `currentTime`, status 200/206, no media error |
| Security/static/related verification | completed | Pint, PHPStan, Rector, build, related PHP/browser matrix |
| Full suite reconciliation | completed | Task 102 fixtures green; 16 foreign failures и 1 foreign error перечислены в evidence |
| Documentation | completed | README/CHANGELOG/thematic owners/compliance/archive evidence |
| Exact index/commit/push | pending | staged snapshot, `main` commit, ordinary push |

## Scope, compatibility и последнее discovery

- Меняются только объявленные 39 paths; shared dirty files коммитятся через
  isolated alternate index относительно сохранённого baseline.
- Public routes, query keys, API response, signed grants, entitlement,
  progress, report/download, Livewire player identity и MP4 остаются
  совместимыми.
- Cache меняется только в public asset build dimension; media/signed/private
  caching остаётся запрещён.
- Rollback: revert единого Task 102 commit и полный rebuild предыдущей версии;
  частичный PHP/assets rollout недопустим.
- Catalog snapshot содержит только `mp4`; 248 опубликованных MP4 без
  historical health-check остаются доступными по backward-compatible
  established-format rule.
- Новые форматы проходят только при `check_status=available` либо
  `last_successful_check_at`; degraded формат без прежнего success скрывается.
- Новых независимых subtitle/audio URL и подтверждённого body нет, поэтому
  новые дорожки не создаются.
- Связанный regression-run выявил legacy mobile/API/Blade/Livewire HLS
  fixtures без availability evidence. Manifest расширен до 39 путей до edits,
  а fixtures теперь хранят тот же successful-check contract, что production
  importer.
- Отдельные `CatalogPageTest` и `CatalogBladeComponentTest` regressions
  воспроизводятся с доступным MP4/HLS и относятся к параллельному
  Livewire/player workspace, а не к Task 102 format gate.
