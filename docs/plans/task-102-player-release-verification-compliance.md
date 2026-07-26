# Task 102 compliance matrix

Дата: 26.07.2026

Ветка: `main`
Статусы: `completed`, `already_compliant`, `not_applicable`, `unresolved`.

| Требование | Статус | Evidence |
| --- | --- | --- |
| AGENTS и requirements index прочитаны до кода | completed | Root `AGENTS.md`, все обязательные и player/import/frontend/cache/security/operations requirements перечитаны |
| Canonical player owner обновлён первым | completed | `docs/audits/video-playback-report.md` |
| Один owner и exact manifest до edits | completed | Lease `task-102-player-release-verification`, NUL manifest расширялся до 39 путей до каждого discovery edit |
| Чужой dirty tree сохранён | completed | External baseline всех declared existing paths и alternate-index strategy |
| Новый format только после фактической availability | completed | `scopeWithVerifiedPlaybackFormat()`, instance gate, resolver/query integration и RED/GREEN tests |
| Established MP4 сохраняет совместимость | completed | Исторические `not_checked` MP4 проходят; local DB имеет 248 таких published rows |
| Tracks не выдумываются | already_compliant | Отдельного track URL/body нет; новые controls/data не созданы |
| PHP и build assets выпускаются одной проверяемой версией | completed | 29 source files, 19 assets, Vite post-write record и fail-closed readiness command |
| Chromium/Firefox фактически декодируют fixture | completed | Два Playwright проекта: `play()`, `readyState >= 2`, `currentTime > 0.05`, no `media.error` |
| Physical iOS/Android проверены | unresolved | Устройств/`adb`/`idevice_id`/`xcrun` нет; viewport не назван device evidence |
| WebKit проверен | unresolved | Browser binary есть, но host не имеет требуемого codec package; system dependency не устанавливалась |
| Auth/routes/API/query keys совместимы | completed | Related PHP matrix 27/219 и final focused matrix; routes/contracts не менялись |
| PWA не кеширует video/signed playback | already_compliant | PWA deny boundary перечитан; PWA files не менялись |
| SQL/N+1/index review | completed | SQLite plan использует `licensed_media_publication_lookup_idx`; gate до `limit(100)`, speculative index отклонён |
| Security review | completed | Safe relative paths, root containment, no symlink, hashes/sizes, no secret/source body, requested 503 boundary |
| Migrations | not_applicable | Existing health columns достаточны; schema/data не менялись |
| Dependencies | not_applicable | Composer/npm dependency graph не менялся |
| README/CHANGELOG/thematic docs | completed | Visitor update, Russian technical entry, cache/deploy/dev/frontend/importer/performance/security/canonical audit |
| Focused verification | completed | PHPUnit, Pint, PHPStan, Rector, Node syntax, Vite/readiness, Composer, routes, Chromium/Firefox |
| Full PHP suite | unresolved | Default 256M memory exhausted in foreign demo raster; финальный 1G run после всех Task 102 fixes: 2 184 tests, 2 156 passed, 206 903 assertions, 11 skipped, 16 foreign failures и 1 foreign error; Task 102 tests отсутствуют среди них |
| Full browser matrix | unresolved | Decode tests 2 passed; remaining 22 failures concern foreign PWA poster 404/player workspace/mobile overlay regressions, 20 platform skips |
| Exact index approval | unresolved | Выполняется после final verification и staged diff review |
| Commit/push main | unresolved | Выполняется последним; production deploy не авторизован |

## Защищаемые contracts

- `titles.show`, `playback.source`, mobile playback API и route model binding;
- `season`, `episode`, `media`, `variant`, `quality`, `format`;
- one-player/Plyr/HLS lifecycle, signed grants, entitlement, progress/report;
- established MP4 и отсутствие fabricated audio/subtitle tracks;
- PWA media denylist, public/private cache boundary и отсутствие production
  deployment в этой задаче.

## Data/operations assessment

- DDL/backfill: `not_applicable`.
- Backup: код не меняет данные; production deploy следует общему
  backup/runbook.
- Cache: меняется только public asset generation fingerprint.
- Failure recovery: несовпавший release check завершает build ненулевым кодом;
  предыдущий целый release остаётся активным.
- Rollback: revert единого Task 102 commit и полный build предыдущей версии;
  partial PHP/assets rollback запрещён.
- Production deployment: `not_applicable` без отдельной authority.
