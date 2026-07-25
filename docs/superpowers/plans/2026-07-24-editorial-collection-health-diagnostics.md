# Диагностика здоровья редакционных подборок — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать администратору безопасную и ограниченную сводку пустых source-managed подборок, покрытия сопоставлением и стабильных причин matched/ambiguous/unmatched для последней синхронизации HDRezka.

**Architecture:** Существующий `CatalogCollectionQuery::latestSourceSyncSummary()` остаётся единственной read boundary сводки и дополняется двумя индексируемыми агрегатами: фактическим числом пустых source-managed коллекций и allowlisted breakdown строк последнего run. Livewire готовит локализованные значения и процент, Blade только выводит готовые массивы. Matcher, reconciliation, source rows, membership и production configuration не изменяются.

**Tech Stack:** PHP 8.5, Laravel 13.21.1, Livewire 4.3.3, SQLite, Blade, Tailwind CSS 4.3.2, PHPUnit 12.5.31.

**Execution status:** `implementation_complete_local`, `verification_complete`; commit/push `unresolved` из-за активных foreign importer/player/system изменений и обязательного clean-worktree hook.

Этот документ остаётся подробным исполнимым планом Task 1. Безлимитное
продолжение, dependency graph для Tasks 2–18 и правила монотонного приёма
Tasks 19+ находятся в
[`2026-07-24-editorial-collections-improvement-master-plan.md`](2026-07-24-editorial-collections-improvement-master-plan.md).

Task 2 продолжения также завершён и проверен локально: общая type boundary
сохранила matcher behavior, admin diagnostics разделила
`supported|unsupported|unknown` и показала 10 actionable против 29
unsupported-only пустых подборок на read-only снимке из 5 633 items. Этот
файл не переписывает завершённые шаги Task 1; точный Task 2 contract и
delivery gate принадлежат improvement master plan и current task plan.

## Global Constraints

- Работать только в существующей ветке `main`; worktree, другая ветка и subagents запрещены project contract.
- Не выполнять внешний HTTP, sync command, DML против production rows, migration, cache clear или изменение `.env`.
- Не ослаблять exact matcher и не прикреплять ambiguous/unmatched source items.
- Не выводить source URL/path, remote title, raw `match_reasons`, error body, cookies или secrets.
- Сохранить существующие admin permission, route, no-store/noindex и full-page Livewire boundaries.
- Все пользовательские строки имеют `ru`/`en` parity; основной видимый текст остаётся русским.
- Новые агрегаты используют существующие индексы; production latency не заявляется без измерения.
- Не поглощать, не отменять и не stage-ить параллельные importer/player/system изменения общего worktree.

---

## File map

- Modify: `tests/Feature/HdRezkaCollectionPresentationTest.php` — feature contract данных, приватности и bounded query count.
- Modify: `app/Services/Collections/CatalogCollectionQuery.php` — allowlisted aggregate boundary последнего run.
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php` — локализованное подготовленное UI-состояние.
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php` — query-free вывод health/breakdown.
- Modify: `lang/ru/collections.php`, `lang/en/collections.php` — заголовки и labels с parity.
- Modify: `docs/architecture.md`, `docs/administration.md`, `docs/performance.md` — canonical contracts.
- Modify: `docs/plans/current-task-plan.md`, `README.md`, `CHANGELOG.md` — task evidence, обязательная README-проверка и технический журнал.
- Preserve: matcher/reconciler/sync command, migrations/schema, routes, permissions, cache keys, public collection/API payloads, recommendation scoring.

## Compliance matrix

| Требование | Статус до реализации | Evidence / решение |
| --- | --- | --- |
| Canonical collection/source architecture | `already_compliant` | Расширяется существующий `CatalogCollectionQuery`; второй source/report service не создаётся |
| Exact matching safety | `already_compliant` | Matcher/reconciler и membership writes не меняются |
| Admin authorization/privacy | `already_compliant` | Используется существующий `/admin/catalog?section=collections`; allowlist исключает raw source/error data |
| Database performance | `completed` | `EXPLAIN QUERY PLAN` подтвердил `catalog_collection_source_items_reconcile_idx`, provider source index и membership unique index |
| Multilingual UI | `completed` | Добавлены одинаковые `ru`/`en` keys; focused SSR contract проверил русский вывод |
| Blade/query boundary | `completed` | Blade получает только подготовленные `health_metrics`/`match_metrics`, не вызывает DB/service/model |
| Routes/API/cache/SEO/search | `not_applicable` | Публичные contracts и cache identity не меняются |
| Production operations | `not_applicable` | Нет migrations, config/runtime/provider calls или mutations |
| README/CHANGELOG/current plan | `completed_local` | Точные непересекающиеся sections добавлены без изменения foreign hunks; final delivery evidence ещё зависит от освобождения worktree |
| Commit/push | `unresolved` | Clean-worktree hook нельзя обходить; stage только task manifest после удаления foreign blocker владельцем |

## Protected public contracts and risks

- `php artisan seasonvar:import` и `catalog-collections:sync-hdrezka` не меняются.
- `CatalogCollectionQuery::latestSourceSyncSummary()` сохраняет существующие keys `status`, `counters`, `completed_at_label`, `completed_at_iso`; новый `diagnostics` additive и читается только admin component.
- Existing `collections.sync.metrics.*` и шесть operational cards сохраняются.
- `CatalogCollectionSourceMatchStatus` и matcher method codes не становятся переводимыми identity: UI переводит только отдельную allowlist presentation keys.
- Unknown/future method code не попадает в DOM и не ломает страницу.
- Пустая подборка определяется по фактическому отсутствию `catalog_collection_items` у provider-linked collection, а не по partial-run counters.
- Coverage использует bounded run counters `matched/items`, возвращает `0` при нулевом denominator и ограничивается диапазоном `0..100`.
- Rollback — удалить additive `diagnostics` preparation/markup/translations; schema/data/cache rollback не требуется.

---

### Task 1: RED contract безопасной диагностики

**Files:**
- Modify: `tests/Feature/HdRezkaCollectionPresentationTest.php`

**Interfaces:**
- Consumes: существующий admin route `admin.catalog`, `CatalogCollectionSyncRun`, source/item provenance и membership.
- Produces: feature contract для `diagnostics.health_metrics`, `diagnostics.match_metrics`, allowlist и bounded SQL.

- [x] **Step 1: Добавить fixture последнего run с двумя source collections**

Создать одну непустую и одну пустую source-managed collection. Для последнего run сохранить шесть source items:

```php
[
    ['matched', 'primary'],
    ['matched', 'alias'],
    ['ambiguous', 'insufficient_lead'],
    ['unmatched', 'no_exact_candidate'],
    ['unmatched', 'no_eligible_candidate'],
    ['unmatched', 'private-internal-reason'],
]
```

Run counters должны быть согласованы: `items=6`, `matched=2`, `ambiguous=1`, `unmatched=3`. Internal reason намеренно не входит в presentation allowlist.

- [x] **Step 2: Добавить assertions**

Проверить:

```php
->assertSeeTextInOrder(['Пустых подборок', '1'])
->assertSeeTextInOrder(['Покрытие совпадениями', '33,33'])
->assertSeeTextInOrder(['По основному названию', '1'])
->assertSeeTextInOrder(['По псевдониму', '1'])
->assertSeeTextInOrder(['Недостаточный отрыв', '1'])
->assertSeeTextInOrder(['Нет точного кандидата', '1'])
->assertSeeTextInOrder(['Нет подходящего кандидата', '1'])
->assertDontSee('private-internal-reason', false);
```

Query log должен доказать ровно один aggregate read `catalog_collection_source_items` и ровно один empty-collection aggregate; existing latest-run read остаётся один. Source path/error summary по-прежнему отсутствуют в HTML.

- [x] **Step 3: Запустить RED**

Run:

```bash
php artisan test --filter=HdRezkaCollectionPresentationTest
```

Expected: новый test contract падает на отсутствующих labels/diagnostics, а не на fixture/schema ошибке.

---

### Task 2: GREEN query и presentation boundary

**Files:**
- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Interfaces:**
- Consumes: `CatalogCollectionSyncRun::$id/counters`, `CatalogCollectionSource`, `CatalogCollectionSourceItem`, `CatalogCollectionItem`.
- Produces:

```php
array{
    status: string,
    counters: array<string, int>,
    diagnostics: array{
        empty_collections: int,
        match_coverage_percent: float,
        match_methods: array<string, int>
    },
    completed_at_label: string,
    completed_at_iso: string
}
```

- [x] **Step 1: Добавить allowlist codes**

В `CatalogCollectionQuery` добавить private constant:

```php
private const SOURCE_MATCH_METHOD_METRIC_KEYS = [
    'matched:primary' => 'matched_primary',
    'matched:original' => 'matched_original',
    'matched:alias' => 'matched_alias',
    'matched:detail_original' => 'matched_detail_original',
    'ambiguous:candidate_limit' => 'ambiguous_candidate_limit',
    'ambiguous:insufficient_lead' => 'ambiguous_insufficient_lead',
    'unmatched:no_exact_candidate' => 'unmatched_no_exact_candidate',
    'unmatched:no_eligible_candidate' => 'unmatched_no_eligible_candidate',
    'unmatched:low_confidence' => 'unmatched_low_confidence',
];
```

Unknown codes игнорируются.

- [x] **Step 2: Добавить два bounded aggregate**

После загрузки latest run:

```php
$emptyCollections = CatalogCollectionSource::query()
    ->where('provider', 'hdrezka')
    ->whereNotNull('catalog_collection_id')
    ->whereNotIn(
        'catalog_collection_id',
        CatalogCollectionItem::query()->select('catalog_collection_id'),
    )
    ->count();

$rows = CatalogCollectionSourceItem::query()
    ->whereIn(
        'catalog_collection_source_id',
        CatalogCollectionSource::query()
            ->select('id')
            ->where('provider', 'hdrezka'),
    )
    ->where('last_seen_run_id', $run->id)
    ->whereNotNull('match_method')
    ->toBase()
    ->select(['match_status', 'match_method'])
    ->selectRaw('COUNT(*) AS aggregate')
    ->groupBy(['match_status', 'match_method'])
    ->get();
```

Инициализировать все allowlisted presentation keys нулём, затем заполнить только распознанные `(status,method)`. Coverage вычислить из sanitized `matched/items`, ограничить `0..100`, denominator `0` вернуть как `0.0`.

- [x] **Step 3: Подготовить render data**

В Livewire добавить `use Illuminate\Support\Number;`. Сохранить existing `metrics`, добавить:

```php
$sourceSyncSummary['health_metrics'] = [
    [
        'label' => __('collections.sync.health.empty_collections'),
        'value' => $sourceSyncSummary['diagnostics']['empty_collections'],
    ],
    [
        'label' => __('collections.sync.health.match_coverage'),
        'value' => Number::percentage(
            $sourceSyncSummary['diagnostics']['match_coverage_percent'],
            maxPrecision: 2,
            locale: app()->currentLocale(),
        ),
    ],
];
```

`match_metrics` построить только из положительных allowlisted counts через `collections.sync.match_methods.{key}`. Blade не должен фильтровать, переводить или считать.

- [x] **Step 4: Вывести две компактные диагностические группы**

После existing operational `<dl>` добавить query-free `health_metrics` и условный `match_metrics` блок. Использовать existing light cards, `sm:grid-cols-2`, `xl:grid-cols-4`, headings из translations; без inline PHP/JS/CSS и без raw output.

- [x] **Step 5: Добавить ru/en parity**

Добавить одинаковую структуру:

```php
'health_title' => 'Здоровье подборок',
'breakdown_title' => 'Разбивка сопоставления',
'health' => [
    'empty_collections' => 'Пустых подборок',
    'match_coverage' => 'Покрытие совпадениями',
],
'match_methods' => [
    'matched_primary' => 'По основному названию',
    'matched_original' => 'По оригинальному названию',
    'matched_alias' => 'По псевдониму',
    'matched_detail_original' => 'По оригинальному названию карточки',
    'ambiguous_candidate_limit' => 'Слишком много кандидатов',
    'ambiguous_insufficient_lead' => 'Недостаточный отрыв',
    'unmatched_no_exact_candidate' => 'Нет точного кандидата',
    'unmatched_no_eligible_candidate' => 'Нет подходящего кандидата',
    'unmatched_low_confidence' => 'Недостаточная уверенность',
],
```

English file получает точные equivalent labels с теми же keys.

- [x] **Step 6: Запустить GREEN и format**

Run:

```bash
php artisan test --filter=HdRezkaCollectionPresentationTest
./vendor/bin/pint --dirty --format agent
php artisan test --filter=HdRezkaCollectionPresentationTest
```

Expected: focused test passes; Pint не форматирует foreign importer paths, потому что task manifest проверяется до запуска.

---

### Task 3: Canonical docs, verification и delivery

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/administration.md`
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/superpowers/plans/2026-07-24-editorial-collection-health-diagnostics.md`

**Interfaces:**
- Consumes: verified implementation/query/test evidence.
- Produces: current compliance report, rollback statement и commit-backed delivery evidence.

- [ ] **Step 1: Дождаться освобождения shared worktree**

Проверить:

```bash
git status --short --branch
git diff --name-only
git diff --cached --name-only
```

Не продолжать в overlapping docs/commit, пока foreign importer/dependency files не закоммичены или не убраны их владельцем безопасным способом. Не использовать stash/reset/checkout/delete.

- [x] **Step 2: Обновить canonical docs**

- `architecture.md`: latest admin summary теперь содержит allowlisted latest-run breakdown и фактический empty membership count; raw source data не читается.
- `administration.md`: описать две диагностические группы и неизменную authorization/privacy boundary.
- `performance.md`: зафиксировать два bounded aggregate и реальные `EXPLAIN` index names без latency claim.
- `README.md`: проверить актуальность; добавить осмысленную русскую запись только как фактическое изменение состояния административной диагностики.
- `CHANGELOG.md`: отдельный русский пункт с техническими identifiers.
- `current-task-plan.md`: заменить active section только после закрытия прежней задачи, добавить expected/protected/risk/compliance/verification/delivery evidence.

- [x] **Step 3: Проверить translations, legacy и diff**

Run:

```bash
php artisan test --filter=Translation
rg -n "private-internal-reason|source_path|error_summary" resources/views app/Livewire/Collections
rg -n "latestSourceSyncSummary|SOURCE_MATCH_METHOD_METRIC_KEYS|match_coverage" app tests docs lang
git diff --check
```

Expected: translation parity passes; raw diagnostic value/source fields не выводятся; duplicate diagnostics отсутствуют.

- [x] **Step 4: Выполнить полную проверку**

Run:

```bash
php artisan test --filter=HdRezkaCollection
php artisan test
npm run build
./vendor/bin/pint --dirty --format agent
```

После последнего formatter повторить focused test. Перед completion перечитать root requirements index и применимые canonical requirements, обновить эту compliance matrix фактическими `completed/already_compliant/not_applicable/unresolved`.

- [ ] **Step 5: Commit и push**

Только при чистом foreign scope:

```bash
git status --short --branch
git add app/Services/Collections/CatalogCollectionQuery.php \
  app/Livewire/Collections/CatalogCollectionAdministrationManager.php \
  resources/views/livewire/collections/catalog-collection-administration-manager.blade.php \
  lang/ru/collections.php lang/en/collections.php \
  tests/Feature/HdRezkaCollectionPresentationTest.php \
  docs/architecture.md docs/administration.md docs/performance.md \
  docs/plans/current-task-plan.md \
  docs/superpowers/plans/2026-07-24-editorial-collection-health-diagnostics.md \
  README.md CHANGELOG.md
git diff --cached --name-status
git diff --cached --check
git commit -m "feat: add editorial collection health diagnostics"
git push
```

Не выполнять commit при staged/unstaged/untracked foreign files или если pre-commit требует их поглотить. Git authentication/remote rejection записать как `unresolved`, не выдавать за успешный push.

## Execution evidence

- Наблюдаемый RED: `HdRezkaCollectionPresentationTest` завершился `3 passed / 1 failed` и упал только на отсутствующей метрике «Пустых подборок».
- GREEN и zero-denominator contract после scoped Pint: `5 tests / 48 assertions`.
- Весь `HdRezkaCollection*` набор: `73 tests / 435 assertions`.
- Canonical `composer analyse`: `0 errors`; финальный полный PHPUnit: `1 526 tests / 1 515 passed / 11 skipped / 123 698 assertions`.
- Vite/Tailwind production build: `24 modules transformed`; asset source/contract ошибок нет.
- Feature contract подтвердил по одному latest-run, empty-membership и match-breakdown query, отсутствие unknown method/source path/error summary в HTML и prepared responsive UI.
- Actual read-only baseline остаётся: 54 source collections, 39 пустых, 5 633 items, 100 matched; breakdown `primary=15`, `alias=85`, `no_exact_candidate=5215`, `no_eligible_candidate=318`.
- Provider HTTP, sync/retry command, schema migration, production DML, cache clear, queue clear, worker restart и environment mutation не выполнялись.
- Shared worktree всё ещё содержит foreign importer/player/system/dependency files; commit/push этой задачи до их безопасного освобождения остаются `unresolved`.
- Финальный снимок: normal repository, branch `main`, local `HEAD` опережает `origin/main`; общий index не изменяется этой задачей, а task manifest не закоммичен, потому что hook запрещает commit при любых foreign unstaged/untracked files.
