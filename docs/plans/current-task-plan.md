# Текущая задача — Task 110: категории во всех discovery modes

## Цель

Вернуть на каждой поддерживаемой странице `/discover/{type}` тот же полный
двухуровневый список активных категорий и подкатегорий, который уже
восстановлен на `/discover/popular#collections`, без дублирования запросов,
изменения recommendation ranking или создания отдельного directory route.

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Requirements, versions и production diagnosis | `completed` | Laravel 13.22.0, Livewire 4.3.3, Tailwind CSS 4.3.2; production SSR по девяти modes |
| Persistent rule, design, plan и compliance matrix | `completed` | [requirement](../requirements/system-wide-integration.md), [design](../superpowers/specs/2026-07-27-all-discovery-collection-hierarchy-design.md), [plan](../superpowers/plans/2026-07-27-all-discovery-collection-hierarchy.md), [matrix](task-110-all-discovery-collection-hierarchy-compliance.md) |
| TDD implementation | `completed` | Regression RED на `personalized`, затем focused GREEN `22/22`, 278 assertions |
| Focused, build и browser verification | `completed` | Broad `157/157`, 1089 assertions; Pint/PHPStan/Vite green; production 18/18 desktop/mobile |
| Documentation/evidence | `completed` | Canonical owners, README, CHANGELOG и [evidence](archive/2026-07-27-all-discovery-collection-hierarchy-evidence.md) |
| Shared delivery audit | `in_progress` | Inherited Task 107 implementation отдельно подтверждён `135/135`, 771 assertions; его stale docs и a11y reason-group исправлены |
| Exact commit и push | `unresolved` | Требуются финальный exact staged snapshot, lease approval, commit и обычный push |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
|---|---|---|
| Общий browser first-party guard | `unresolved` | Новые Task 107/109/110 assertions проходят; соседний `/pwa/posters/browser-smoke` возвращает прежний `404` |
| Общий operational health | `unresolved` | Importer, database, Redis, workers и readiness исправны; Memcached/full cache warming остаются вне текущего scope |
| Remote delivery | `unresolved` | Exact commit ещё не создан; configured remote дополнительно требует успешной credential/write проверки |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Requirements, versions, plans и implementation review | `completed` | Task 107–110 matrices и архивные evidence содержат проверенные owners, stack, risks и protected contracts |
| Importer recovery/completion | `completed` | Fresh Seasonvar slice `376/376`, 2344 assertions; production canaries и rollback evidence сохранены |
| Compact similar recommendations | `completed` | Related matrix `135/135`, 771 assertions; native `6 + 6`, bounded reasons и server-side feedback сохранены |
| Discovery category hierarchy | `completed` | Fresh collection/discovery matrix `158/158`, 1075 assertions; production 18/18 desktop/mobile сохранено |
| Routes, authorization, privacy, SEO и cache compatibility | `already_compliant` | Existing public/API/Livewire contracts не расширены; task-specific cross-feature matrices проверены |
| Dependency update | `not_applicable` | Посторонний `composer.lock` не требуется для task behavior и исключён из delivery |
| Exact main delivery | `in_progress` | Ожидает staged-path equality, reviewed index approval, commit, clean-tree pre-push и обычный push |

## Последнее подтверждённое evidence

- [Task 107: compact similar recommendations](archive/2026-07-27-title-similar-recommendations-compact-evidence.md)
- [Task 108: complete Seasonvar importer](archive/2026-07-26-complete-seasonvar-importer-evidence.md)
- [Task 109: discovery hierarchy restoration](archive/2026-07-27-discovery-collection-hierarchy-restoration-evidence.md)
- [Task 110: hierarchy on all discovery modes](archive/2026-07-27-all-discovery-collection-hierarchy-evidence.md)

## Подтверждённая причина

- Все девять routes отвечают `200`, а `CatalogCollectionExplorer` уже умеет
  рендерить 5 root и 31 child.
- `CatalogDiscoveryPage::render()` передаёт section navigation только для
  `CatalogRecommendationType::Popular`.
- Blade монтирует explorer только внутри проверки непустой navigation.
- Поэтому `popular` содержит дерево, а остальные восемь modes не монтируют
  исправный компонент вообще.

## Выбранное решение

- Один существующий nested `CatalogCollectionExplorer` монтируется ровно один
  раз на каждом mode перед serial results.
- `#collections` остаётся общим; `popular` сохраняет `#popular-titles`,
  остальные modes получают `#discovery-titles`.
- Child key включает mode и locale, чтобы Livewire identity оставалась
  стабильной и не смешивала соседние route contexts.
- Collection URL state/paginator независимы от recommendation filters,
  paginator, refresh и ranking.

## Ожидаемые изменяемые файлы

- `app/Livewire/CatalogDiscoveryPage.php`
- `resources/views/livewire/catalog-discovery-page.blade.php`
- `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- `tests/Feature/CatalogDiscoveryLayoutTest.php`
- `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`
- `tests/browser/discovery-collections.spec.js`
- `docs/requirements/system-wide-integration.md`, `docs/architecture.md`,
  `docs/frontend.md`, `docs/views.md`, `docs/UI_STANDARDS.md`,
  `docs/caching.md`, `docs/performance.md`, `docs/README.md`
- `README.md`, `CHANGELOG.md`, Task 110 plan/spec/compliance/evidence

## Совместимые публичные contracts

- `GET /discover/{type}` и localized routes для девяти существующих modes;
- `/discover/popular#collections` и `#popular-titles`;
- collection query keys и `collectionsPage`;
- recommendation filters, ranking, refresh и main paginator;
- public collection eligibility, taxonomy, moderation/quality, API/sitemap;
- RU/EN translations и text-only nested hierarchy.

## Risks и cross-feature impact

| Domain | Решение |
|---|---|
| Database/migrations | Не меняются; production data не записывается |
| Routes/API | Не меняются; aliases/default route не добавляются |
| SEO | Collection state canonical к текущему mode и `noindex`; clean policy сохраняется |
| Cache | Новых keys нет; route/type уже входит в identity, stateful collection variants обходят shared HTML |
| Authorization/privacy | Только существующий public scope; private/personal state не раскрывается |
| Mobile/a11y | Один существующий nested list, wrapping и 44px real actions |
| Performance | Один фиксированный bounded explorer на mode; без N+1 и duplication |
| Rollback | Вернуть popular-only parent condition; schema/data/cache rollback не нужен |

## Verification notes

- После точечного bump `CatalogPages` до версии `257` все девять production
  URLs отвечают `200` и содержат один explorer, 5 root и 31 child.
- Изолированный production Chromium: 9 modes × desktop/mobile, overflow `0`,
  browser errors `0`; `/en/discover/random` проверен отдельно.
- Полный project browser scenario проходит новые discovery assertions, но его
  финальный общий guard по-прежнему видит два соседних
  `404 /pwa/posters/browser-smoke` на каждом viewport.

## Previous workstreams

Task 109 восстановил сам полный root/child tree и сохранён в
[evidence](archive/2026-07-27-discovery-collection-hierarchy-restoration-evidence.md).
Task 108 importer проверен отдельно. Inherited Task 107 больше не считается
непроверенным foreign scope: реализация `6 + 6`, compact feedback и
screen-reader semantics подтверждены отдельной recommendation matrix и
[evidence](archive/2026-07-27-title-similar-recommendations-compact-evidence.md).
Все четыре workstream включаются только после одного exact staged review;
посторонний `composer.lock` остаётся вне delivery.
