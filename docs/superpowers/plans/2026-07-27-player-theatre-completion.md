# Task 112 — план завершения режима «Театр»

## Scope

Исправить потерю player viewport при включении theatre, завершить визуальную
тёмную сцену, сохранить media DOM identity и проверить keyboard/mobile/
landscape contracts.

## Ожидаемые изменяемые файлы

- `resources/js/player-navigation.js`
- `resources/css/app.css`
- `resources/views/livewire/catalog-title-player.blade.php`
- `tests/browser/player-workspace.spec.js`
- `tests/Unit/PlayerWorkspaceAssetContractTest.php`
- `tests/Feature/CatalogPlayerWorkspaceTest.php`
- `docs/UI_STANDARDS.md`
- `docs/frontend.md`
- `docs/views.md`
- `docs/audits/video-playback-report.md`
- `README.md`
- `CHANGELOG.md`
- task design/plan/compliance/current registry/archive evidence

## Protected contracts

- `resources/views/livewire/catalog-title-detail.blade.php`
- `app/Livewire/CatalogTitleDetail.php`
- `app/Livewire/CatalogTitlePlayer.php`
- `resources/js/player.js`
- `resources/js/player-menu.js`
- `resources/player-release.json`
- `routes/web.php`, `routes/api.php`
- `lang/ru/catalog.php`, `lang/en/catalog.php`
- playback grants, progress tokens, entitlement and delivery routes
- service worker media exclusions

## Этапы

1. Зафиксировать RED browser/asset/Blade contracts для viewport, scroll,
   DOM identity, icon, global shell и seasons surface.
2. Реализовать memory-only scroll lifecycle через один cancellable
   `requestAnimationFrame`.
3. Добавить точные Blade data markers без второго полного `wire:ignore`.
4. Завершить scoped theatre CSS без fixed overlay и nested scroll.
5. Выполнить focused PHPUnit, syntax, Vite/player release gate и Playwright.
6. Проверить extended device matrix и lifecycle Chromium/Firefox.
7. Обновить owners, README, CHANGELOG, compliance и archive evidence.
8. Проверить exact staged snapshot, commit в `main` и выполнить push.

## Выполнение

Этапы 1–7 завершены. RED зафиксировал отсутствие required frame/icon
contracts, GREEN прошёл focused PHPUnit (`4 tests`, `70 assertions`),
связанный backend/security/player набор (`91 tests`, `1220 assertions`),
основную (`8 passed`, `1 expected skip`) и расширенную
(`19 passed`, `2 expected skips`) browser-матрицы, а также Chromium/Firefox
lifecycle (`16 passed`, `6 expected skips`). Vite собрал `28 modules`;
release gate подтвердил `30 sources` и `19 assets`. Этап 8 выполняется после
финального repository scan и повторного чтения требований.

## TDD

RED должен падать на текущей реализации из-за отрицательной геометрии toggle
или player после входа и отсутствия scroll restoration/icon marker. После
реализации тот же тест должен пройти без ослабления assertions.

## Риски

| Риск | Мера |
|---|---|
| Focus сам меняет scroll после выхода | `focus({ preventScroll: true })` только после восстановления layout |
| Livewire morph заменяет video | Проверка exact DOM identity; полный ignore остаётся один |
| Pending frame работает после navigation | Frame отменяется в abort cleanup |
| Mobile bottom nav закрывает сцену | Она скрыта только при body theatre marker |
| Landscape video превышает высоту | Max inline-size вычисляется из `dvh` с `vh` fallback |
| Dark surface снижает contrast | Scoped явные selectors и browser computed-style assertion |
| PWA сохраняет media | Service worker/denylist не меняются, release/lifecycle gates повторяются |

## Migrations, routes, translations, cache и permissions

- migrations: `not_applicable`;
- routes/API: `unchanged`;
- translations: новые keys не требуются, parity сохраняется;
- cache keys/invalidation: `not_applicable`;
- permissions/policies: `unchanged`;
- production data/backup: data mutation отсутствует;
- deployment: требуется согласованный Vite build и player release fingerprint;
- rollback: вернуть согласованный frontend/Blade commit и rebuild assets.
