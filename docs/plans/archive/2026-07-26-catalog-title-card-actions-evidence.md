# Task 103 evidence — новая карточка сериала

Дата: 26.07.2026.

Статус: `in_progress: ready for delivery`.

## Baseline

- Branch: `main`.
- Pre-task HEAD: `ab9aa3e` (`feat: enforce shared main task ownership`).
- Upstream state: `main` ahead of `origin/main` by 112 commits.
- Workspace: foreign staged/unstaged/untracked state preserved; Task 103 owns
  only its declared manifest through repository lease.
- Runtime: PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, Boost 2.4.13,
  PHPUnit 12.5.32, Tailwind CSS 4.3.2, SQLite.

## Findings

- Canonical `TitleCard` был reusable, но `compact` попадал в list-template;
  добавлен отдельный compatibility template без изменения его consumers.
- Country/`18+`/recent season нельзя было добавлять отдельными relations:
  page-bound metadata сведена в один `UNION ALL`, а genres остались
  единственной Eloquent card relation.
- Existing library и recommendation feedback services уже владеют
  authorization, transaction, rate limit и invalidation; новый repository,
  controller или write model не понадобились.
- Browser action менял library state, но status находился в другом Livewire
  island и не обновлялся. Status/error перенесены в `catalog-pagination`,
  после чего реальный round-trip стал объявлять результат.
- Tablet coarse-pointer dropdown шириной 20rem выходил за левый viewport у
  первой grid-card. Touch/tablet presentation заменён на fixed
  safe-area-aware sheet; fine-pointer desktop сохранил absolute dropdown.
- Title-detail budget имеет чужой текущий рост с 29 до 34 queries:
  SQL trace связывает пять schema-запросов с незакоммиченным изменением
  `CatalogCollectionSchema::qualityAvailable()` вне manifest Task 103. Файл и
  его тест не изменялись; это shared-workstream evidence, а не обход budget.

## Verification log

- Focused RED перед реализацией:
  `php artisan test --compact tests/Feature/CatalogCompactTitleCardTest.php
  tests/Feature/CatalogTitleCardMetadataLoaderTest.php
  tests/Feature/CatalogTitleCardActionTest.php` — 15 tests: 2 passed,
  2 failed, 11 errors; зафиксированы отсутствующие templates/loader/actions.
- Focused GREEN той же команды — 15 passed, 118 assertions.
- Related catalog regression после Pint — 91 passed, 694 assertions.
- `CatalogPageTest` targeted regressions — 2 passed, 11 assertions.
- Catalog initial/deferred/update Livewire budget — 1 passed, 23 assertions.
- `./vendor/bin/pint --format agent <Task 103 PHP paths>` — passed; исправлен
  только порядок imports/format в собственном scope.
- `npm run build` — passed, Vite 8.1.4, 28 modules; final CSS 212.23 kB
  (44.59 kB gzip), application JS 45.34 kB (14.16 kB gzip).
- Focused Playwright для новых grid/list/actions:
  desktop 2 passed, mobile 2 passed, tablet 2 passed; дополнительный desktop
  и tablet retest после island/sheet fixes — passed.
- Остальные catalog browser tests запущены по project из-за внешнего 60-second
  process limit: desktop 9 passed; mobile 8 passed/1 intentional skipped;
  tablet 8 passed/1 intentional skipped. Итого по матрице — 31 passed,
  2 skipped, 0 failed.
- Финальный объединённый запуск двух Task 103 сценариев во всех трёх
  проектах сначала выявил order-dependent предположение теста о начальном
  состоянии библиотеки: desktop менял строку, а mobile/tablet ожидали прежнее
  значение. Тест переведён на проверку фактического initial state,
  противоположного `aria-pressed` и соответствующего уведомления. Повторный
  единый запуск — 6 passed за 47,0 s.
- Axe `wcag2a|wcag2aa`, page geometry, 44 px touch targets, local
  asset/console/page errors и keyboard focus включены в browser assertions.
  Grid/list/tablet-sheet screenshots просмотрены визуально.
- `impeccable audit`: P0/P1 не найдено; три detector warnings были
  false-positive на co-located base `text-slate-700` и
  `hover:text-emerald/amber`, computed hover state и Axe не подтвердили
  contrast defect.
- Итоговый связанный PHPUnit-набор (`CatalogCompactTitleCardTest`,
  `CatalogTitleCardMetadataLoaderTest`, `CatalogTitleCardActionTest`,
  `CatalogBladeComponentTest`, `CatalogVisualSystemTest`,
  `CatalogTitlesUxRedesignTest`, `CatalogTitlesCardCountQueryTest`,
  `CatalogTranslationPreferenceCardTest`, `CatalogPageTest`,
  `TranslationCatalogParityTest`) — 172 passed, 81 689 assertions.
- После типизации connection boundary через
  `Illuminate\Database\DatabaseManager`: focused regressions — 100 passed,
  980 assertions; exact production-scope `PHPStan` — passed, 0 errors;
  `Pint --dirty --format agent` — passed.
- `TranslationCatalogParityTest` — 3 passed, 80 162 assertions;
  `route:list --path=titles --except-vendor --json` — passed и подтвердил
  прежние `titles.index`, `titles.year`, `titles.show` contracts.
- Стандартный `php artisan test --compact` дважды исчерпал установленный в
  `phpunit.xml` лимит 256 MiB на чужих длинных наборах. Diagnostic full run с
  1 GiB завершил 2162 tests: 2130 passed, 11 skipped, 18 failed, 3 errors.
  Все failures/errors принадлежат незавершённым shared PWA, collection
  quality, authentication/import и shared-workflow изменениям либо известному
  чужому росту title-detail schema queries; focused Task 103 tests зелёные.
- `php artisan project:docs-refresh --check` — unresolved:
  `docs/MAINTENANCE_LOG.md` требует refresh из-за чужих unstaged managed
  additions. Task 103 не менял и не присваивал этот файл.
- Финальный repository scan не нашёл SQL/authorization в Task 103 Blade,
  debug/TODO, hover-only actions, второго card metadata loader или
  незавершённой Task 103 заглушки.

## Delivery

Implementation и доступные gates завершены. Exact alternate-index commit и
ordinary push выполняются после фиксации этого evidence; фактический hash и
результат remote delivery принадлежат финальному отчёту.
