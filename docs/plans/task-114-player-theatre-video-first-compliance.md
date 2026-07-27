# Task 114 — compliance matrix video-first theatre

Дата: 27.07.2026

| Требование | Статус | Evidence |
|---|---|---|
| Root `AGENTS.md`, requirements index и применимые owners прочитаны | `completed` | Повторно прочитаны до edits |
| Фактические runtime/package версии проверены | `completed` | PHP `8.5.8`, Laravel `13.22.0`, Livewire `4.3.3`, Tailwind `4.3.2`, Vite `8.1.4`, PHPUnit `12.5.32` |
| Работа только в существующей `main` | `completed` | `main...origin/main [ahead 6]`; branch/worktree не создавались |
| Exact workspace lease и NUL manifest | `completed` | `task-114-player-theatre-video-first`; 19 путей объявлены после browser discovery |
| Approved design и implementation plan | `completed` | Согласованная video-first модель сохранена в Task 114 design/plan |
| TDD RED до production edits | `completed` | Marker/CSS RED: `3` expected failures; header-state и compact-toggle RED подтверждены отдельно |
| Theatre video-first Blade/CSS behavior | `completed` | Scoped markers/order/overlay реализованы только под active body state |
| Normal mode, Livewire и media DOM compatibility | `completed` | PHPUnit + Playwright refresh/enter/exit/scroll/identity GREEN |
| Mobile, safe-area, touch и landscape | `completed` | Extended matrix `19 passed`, `2 expected skips`; visual matrix `7/7` |
| Vite/player release unit | `completed` | `31 sources`, `19 assets`, fingerprint `336fae52fc497e43ce048abefc6c25363bfd64275993abb5477046afad46d152` |
| Routes, schema, translations, cache keys и permissions | `not_applicable` | Contracts не меняются |
| Production/rollback/data-safety assessment | `completed` | Frontend-only rollout; migration/data/cache flush/backup не требуются |
| README, owners и русский CHANGELOG | `completed` | UI/frontend/views/player audit, visitor history и датированный changelog обновлены |
| Full verification и legacy/dead/debug/secret scans | `completed` | Full PHPUnit `2294` tests/`208369` assertions; syntax/docs/diff/release gates GREEN |
| Exact staged review, commit и push | `in_progress_commit_unresolved_push` | Task 113 зафиксирован отдельно; Tasks 114–116 staged exact, а непроверенный `composer.lock` исключён и блокирует clean pre-push |

## Cross-feature impact

| Domain | Impact | Evidence / стратегия |
|---|---|---|
| Player/Livewire | `affected` | DOM/CSS presentation и memory-only header compact restore; media/Livewire state не меняются |
| Global header lifecycle | `affected` | Memory-only compact-state восстанавливается до normal layout, чтобы сохранить точный scroll |
| Responsive/accessibility | `affected` | Safe-area, 44px toggle, desktop/mobile/tablet/landscape browser checks |
| Build/deployment/cache fingerprint | `affected` | Vite build и player release source list обновляются согласованно |
| Authentication/authorization/premium | `unaffected` | Entitlement, grants, policies и session state не меняются |
| Search/SEO/sitemap | `unaffected` | Routes/content/canonical markup не меняются вне theatre presentation |
| PWA/offline/media delivery | `unaffected` | Service worker denylist и playback/download routes не меняются |
| Importer/database/queues | `unaffected` | Schema, data, payloads и provider URLs не меняются |
| Translations | `unaffected` | Новых строк нет; существующие ru/en labels сохраняются |

## Expected changed files

- Blade: layout и существующий player workspace;
- CSS: scoped active-theatre presentation;
- JS: существующий theatre scroll lifecycle без нового persistent state;
- player release manifest;
- focused PHPUnit/Playwright tests;
- UI/frontend/views/player audit, README, CHANGELOG, plan/compliance/evidence.

## Protected public contracts

- routes/API/query keys и route model binding;
- `CatalogTitlePlayer` public Livewire API;
- media grants, entitlement, progress и source recovery;
- один media DOM node и текущие `wire:ignore` boundaries;
- normal-mode content/order/functionality;
- translation keys, cache keys, permissions и PWA media exclusions.

## Migrations, rollout и rollback

- migrations/data writes/backfill/backup: `not_applicable`;
- routes/API/translations/permissions: `unchanged`;
- cache keys/invalidation: `unchanged`; только asset/player fingerprint меняется;
- rollout: единый frontend release unit с Vite build;
- rollback: revert Blade/CSS/manifest/tests/docs commit и rebuild assets.

## Verification notes

- Первый RED: focused suite `4 tests`, `1 passed`, `3 expected failures`,
  `49 assertions`.
- Header compact RED подтвердил отсутствующий memory boundary; browser
  diagnostics показали `71 → 55 px` и scroll `491 → 475`.
- Compact-toggle RED подтвердил отсутствующие `aria-label`/responsive
  contracts.
- Final focused GREEN: `4 tests`, `83 assertions`.
- Финальный extended Playwright: `19 passed`, `2 expected skips`; screenshots
  desktop/mobile/tablet/narrow/landscape/TV сохранены в `output/playwright/`.
- Один Firefox EN source-selection run оставил `format=m3u8`, но на свежей
  fixture-базе тот же RU/EN scope прошёл `2/2`; theatre scope source selection
  не меняет.
- Первый full PHPUnit обнаружил H1 policy current plan и был исправлен; второй
  получил случайный factory duplicate. Изолированный тест прошёл `5/5`,
  финальный full gate — `2294 tests`, `208369 assertions`, `11 skips`.
