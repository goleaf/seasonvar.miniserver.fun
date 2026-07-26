# Homepage Subtitle Facet Snapshot Implementation Plan

> **Для исполнителя:** выполнять пункты последовательно в существующей ветке
> `main`. Branch, worktree, PR и subagent не создавать. После discovery,
> меняющего scope, сразу обновить этот план и
> `docs/plans/current-task-plan.md`.

**Цель:** убрать повторный correlated subtitle-tag count из Homepage-only
rebuild, сохранив точный публичный payload и authoritative database fallback.

**Архитектура:** прежний constrained Eloquent query остаётся владельцем
данных, но выполняется rebuild callback отдельного compact resource в
существующем `CatalogFacetSnapshotCache`. Outer Homepage snapshot, API/HTML
projection, cache version/TTL и write boundaries не меняются.

**Стек:** PHP 8.5, Laravel 13.22, Livewire 4.3, Eloquent, TieredCache, SQLite,
PHPUnit 12.5.

## Глобальные ограничения

- Не изменять parallel Task 92–94 files/hunks, кроме чистых task-specific
  файлов `CatalogHomeSnapshotCache` и `CatalogHomePerformanceTest`.
- Не выполнять reset, stash, restore, clean, force push или blind `git add .`.
- Не добавлять migration, index, DML, package, queue, scheduler, route, config
  или environment variable.
- Сохранить canonical и legacy Tag schema paths.
- Сохранить `CatalogTitleQuery::constrainVisible($query, null)` и все
  publication/audience/window/soft-delete ограничения.
- Кэшировать только scalar array, не Eloquent model и не private/user state.
- Все timing values описывать как локальную диагностику, не SLA/p95.
- Final commit собирать из exact Task 95 paths/hunks через изолированный index.

---

## Этап 1. Baseline и границы

### Task 1.1 — Зафиксировать фактический stack и shared Git state

**Приоритет:** critical
**Статус:** completed

**Что и почему:** подтвердить runtime/packages/database/frontend/current branch
и отделить чужие незавершённые изменения до собственных edits.

**Файлы/модули:** `composer.lock`, `package-lock.json`, `bootstrap/app.php`,
`routes/{web,api}.php`, Git index/worktree.

**Зависимости:** root `AGENTS.md`, `docs/requirements/index.md`, Laravel Boost
`application_info`.

**Риски:** случайно включить Tasks 92–94 в Task 95 commit.

**Проверка:** `git status --short --branch`, `git diff`, `git diff --cached`,
`git remote -v`, Boost application info.

### Task 1.2 — Проследить Homepage/cache/query/invalidation path

**Приоритет:** critical
**Статус:** completed

**Что и почему:** доказать владельцев read/write/cache contracts до изменения.

**Файлы/модули:** `CatalogHomeSnapshotCache`,
`CatalogFacetSnapshotCache`, `CatalogHomePageBuilder`,
`CatalogCacheInvalidator`, `TagCacheInvalidator`, `TieredCache`,
`CacheVersionRegistry`, Tag/CatalogTitle models.

**Зависимости:** canonical caching/performance/security/architecture
requirements.

**Риски:** кэшировать значение в неправильном домене или пропустить mutation
path.

**Проверка:** repository-wide usage search; подтвердить, что public catalog и
tag writes after commit повышают `Homepage` и `CatalogFacets`.

### Task 1.3 — Измерить baseline и SQL plan

**Приоритет:** critical
**Статус:** completed

**Что и почему:** выбрать оптимизацию на основе фактического bottleneck, не
догадки.

**Файлы/модули:** read-only production-like SQLite,
`CatalogHomeSnapshotCache::build()`.

**Зависимости:** schema memo/year facet warm state.

**Риски:** принять за improvement шум wall time; повредить production data.

**Проверка:** `PRAGMA query_only=ON`, DB listener, `EXPLAIN QUERY PLAN`;
фиксировать SQL count/time и planner indexes без DML.

---

## Этап 2. Дизайн и подготовка

### Task 2.1 — Сравнить варианты

**Приоритет:** high
**Статус:** completed

**Что и почему:** выбрать минимальную boundary с контролируемым rollback.

**Варианты:** существующий `CatalogFacetSnapshotCache` (выбран); новый/forced
index; denormalized counter/materialized table; удаление count/current
Homepage-only cache (отклонены).

**Файлы/модули:** design spec, migrations/index inventory, cache services.

**Зависимости:** baseline/EXPLAIN и invalidation trace.

**Риски:** создать новый источник истины или write amplification.

**Проверка:** design фиксирует причины выбора, non-goals, rollout и rollback.

### Task 2.2 — Зафиксировать design до production code

**Приоритет:** high
**Статус:** completed

**Файлы:** `docs/superpowers/specs/2026-07-26-homepage-subtitle-facet-snapshot-design.md`.

**Зависимости:** Task 2.1.

**Риски:** смешать design commit с чужим dirty tree.

**Проверка:** isolated docs commit содержит только design file; branch `main`.

### Task 2.3 — Обновить живой план и compliance matrix

**Приоритет:** critical
**Статус:** completed

**Что и почему:** выполнить обязательный project workflow и обеспечить
проверяемый checklist.

**Файлы:** этот plan, `docs/plans/current-task-plan.md`.

**Зависимости:** design commit.

**Риски:** перезаписать parallel Task 94 section.

**Проверка:** Task 95 добавлен отдельным append-only разделом; expected files,
protected contracts, matrices и statuses перечислены.

---

## Этап 3. TDD RED

### Task 3.1 — Добавить cache-lifecycle regression

**Приоритет:** critical
**Статус:** completed

**Что:** в `CatalogHomePerformanceTest` создать canonical публичный subtitle
tag, видимый и скрытый связанные тайтлы; слушать только subtitle count SQL.

**Почему:** тест должен проверять observable query lifecycle и exact payload,
а не повторять приватную реализацию.

**Файлы:** `tests/Feature/CatalogHomePerformanceTest.php`.

**Зависимости:** существующие factories, `RefreshDatabase`,
`CacheVersionRegistry`.

**Риски:** listener ошибочно поймает нерелевантные Tag queries; cache state
другого теста исказит результат.

**Проверка:** перед первым refresh повысить `CatalogFacets`; query matcher
проверяет `from tags`, `catalog_titles_count`, `subtitle-available`.

### Task 3.2 — Доказать RED

**Приоритет:** critical
**Статус:** completed

**Команда:**

```bash
php artisan test --filter=test_home_snapshot_reuses_subtitle_tag_until_the_facet_version_changes
```

**Ожидание:** текущий production code выполняет второй subtitle count при
повторном refresh, поэтому assertion `count=1` падает. Application boot,
fixture и payload assertions не должны падать.

**Риск:** false RED из-за неверного fixture/schema.

**Проверка:** сохранить точный failure message и line; не менять production
code до этого evidence.

---

## Этап 4. Минимальная реализация

### Task 4.1 — Вынести прежний query в private subtitleTag

**Приоритет:** critical
**Статус:** completed

**Что:** заменить inline query в `build()` на
`$subtitleTag = $this->subtitleTag()` и добавить typed private method.

**Почему:** isolate compact cache resource без нового architectural layer.

**Файлы:** `app/Services/Catalog/CatalogHomeSnapshotCache.php`.

**Зависимости:** уже injected `CatalogFacetSnapshotCache`.

**Риски:** потерять nullable behavior, casts или exact attributes.

**Проверка:** method возвращает `array<string,mixed>|null`; `emptySnapshot()`
не меняется.

### Task 4.2 — Добавить compact CatalogFacets resource

**Приоритет:** critical
**Статус:** completed

**Что:** использовать resource `homepage-subtitle-tag-v1`, dimensions
`audience=public` и `schema=canonical|legacy`; rebuild возвращает `[]` либо
один scalar attributes array.

**Почему:** `CatalogFacetSnapshotCache::remember()` канонически возвращает
list и уже управляет version/TTL/lock/fallback.

**Файлы:** `CatalogHomeSnapshotCache.php`.

**Зависимости:** Tag canonical-schema detection и constrained `withCount`.

**Риски:** cross-schema collision во время rolling deployment; object
serialization; locale contract drift.

**Проверка:** schema mode входит в key; только `id/name/slug/count`; текущий
query и порядок `select()` затем `withCount()` сохранены по Laravel 13 docs.

### Task 4.3 — Отформатировать и проверить syntax

**Приоритет:** high
**Статус:** completed

**Команды:**

```bash
./vendor/bin/pint --dirty --format agent
php -l app/Services/Catalog/CatalogHomeSnapshotCache.php
php -l tests/Feature/CatalogHomePerformanceTest.php
```

**Риск:** Pint затронет foreign dirty PHP.

**Проверка:** перед/после сравнить `git diff --name-only`; если Pint изменил
foreign paths, не включать их в Task 95 commit и зафиксировать как shared
state.

---

## Этап 5. GREEN, invalidation и query evidence

### Task 5.1 — Получить targeted GREEN

**Приоритет:** critical
**Статус:** completed

**Проверка:** два refresh до bump выполняют один query и возвращают одинаковый
payload; DB mutation без bump не протекает; bump `CatalogFacets` выполняет
второй query и возвращает новый count.

**Evidence:** три task-specific теста прошли с 12 утверждениями; весь
`CatalogHomePerformanceTest` — 14 тестов / 68 утверждений.

### Task 5.2 — Проверить отсутствие/legacy path

**Приоритет:** high
**Статус:** completed

**Что:** existing tests либо новые assertions подтверждают `null` при
отсутствии тега и прежний `slug=subtitry` path при отключённой canonical
schema.

**Риски:** общий resource пустого результата маскирует добавление тега без
инвалидации.

**Проверка:** canonical write boundary обязательно bump-ит CatalogFacets;
focused invalidator regression проходит.

**Evidence:** null-result и legacy-slug lifecycle покрыты отдельными тестами;
cache/invalidation/API/web matrix прошла 38 тестов / 831 утверждение.

### Task 5.3 — Проверить SQL count и EXPLAIN

**Приоритет:** high
**Статус:** completed

**Что:** fresh-process profile первого и второго refresh в одной
`CatalogFacets` generation.

**Ожидание:** первый refresh выполняет один subtitle query, второй — ноль;
Homepage build сокращается на один SQL statement. Первый query сохраняет
planner indexes.

**Риски:** warmed outer Homepage скрывает callback.

**Проверка:** использовать `refresh()`, а не `snapshot()`; отдельно считать
целевой SQL и общий statement count.

**Evidence:** пять изолированных read-only samples: first generation
`10 SQL / 1 subtitle query`, repeat Homepage rebuild
`8 SQL / 0 subtitle query`; count `2 917` и SHA-256 payload одинаковы.
`EXPLAIN QUERY PLAN` сохранил `tags_code_unique`, covering reverse pivot
index и integer primary key.

---

## Этап 6. Связанный review

### Task 6.1 — Архитектура и качество

**Приоритет:** high
**Статус:** completed

**Проверить:** thin Livewire/controller boundary, отсутствие Blade queries,
duplicate service, static state, dead/debug/TODO code, unused imports, long
methods и нарушение Laravel conventions только в task-related path.

**Файлы:** Home snapshot/facet/builder/tests.

**Риск:** уйти в несвязанный refactor.

**Проверка:** repository searches и scoped static analysis; менять только
проблемы, влияющие на задачу.

**Evidence:** private helper остался в существующем service, новый слой не
создан; exact-file Pint, syntax, PHPStan и Rector завершились без ошибок.

### Task 6.2 — Безопасность и privacy

**Приоритет:** critical
**Статус:** completed

**Проверить:** public-only visibility, no IDOR/private state, no raw URL,
object deserialization, SQL injection, XSS, secret logging и unbounded input.

**Файлы:** snapshot payload, query owner, API/web rehydration.

**Проверка:** exact attribute keys, bindings/Eloquent, public guest tests,
secret scan.

**Evidence:** payload ограничен `id/name/slug/catalog_titles_count`,
constrained Eloquent использует bindings; owner/source/private attributes,
raw SQL, request input, write endpoint и secret/debug output отсутствуют.

### Task 6.3 — Cross-feature compatibility

**Приоритет:** high
**Статус:** completed

**Проверить:** auth, API, SEO, locale, search, recommendations, player,
Premium/region/legal, importer/admin/tag invalidation, warming, cache outage,
mobile/service worker/deploy/rollback.

**Ожидание:** все домены, кроме shared cache read path, `already_compliant`
или `not_applicable`; никакой public contract не меняется.

**Evidence:** existing Homepage/CatalogFacets after-commit invalidation
прослежено; cache/API/web regressions и managed Chromium сохранили route,
nullable payload, UI-ссылку, locale/SEO shell и guest visibility.

---

## Этап 7. Документация

### Task 7.1 — Обновить canonical cache/performance owners

**Приоритет:** high
**Статус:** completed

**Файлы:** `docs/caching.md`, `docs/performance.md`.

**Что:** описать resource identity, payload, invalidation/fallback,
EXPLAIN/query-count/timing evidence и отсутствие migration/index.

**Риск:** заявить SLA по локальному measurement.

**Проверка:** формулировки явно называют evidence диагностическим.

**Evidence:** resource, dimensions, fallback/invalidation, measured SQL,
`EXPLAIN`, rejected index/materialization и rollback записаны в canonical
owners без SLA/p95 claim.

### Task 7.2 — Обновить README и CHANGELOG

**Приоритет:** critical
**Статус:** completed

**Файлы:** `README.md`, `CHANGELOG.md`.

**Что:** добавить visitor-visible эффект ускорения в последний раздел истории
README и отдельный русский датированный технический пункт CHANGELOG.

**Зависимости:** verified GREEN/performance result.

**Риски:** вручную изменить managed `project-docs` block либо перезаписать
parallel entries.

**Проверка:** append exact task hunk; `project:docs-refresh --check`,
README/changelog policy scripts.

**Evidence:** visitor history и отдельный русский технический пункт
подготовлены; policy/docs gates выполнены с отдельно зафиксированными
foreign shared-tree замечаниями.

### Task 7.3 — Завершить current plan/compliance evidence

**Приоритет:** critical
**Статус:** completed

**Что:** внести точные команды, counts, failures/skips и unresolved.

**Проверка:** каждый applicable requirement имеет evidence, честный
`unresolved` или `not_applicable`.

**Evidence:** task-specific и current compliance matrices обновлены точными
test/build/browser/full-suite результатами и внешними unresolved failures.

---

## Этап 8. Verification

### Task 8.1 — Focused и related PHPUnit

**Приоритет:** critical
**Статус:** completed

**Команды:**

```bash
php artisan test --filter=test_home_snapshot_reuses_subtitle_tag_until_the_facet_version_changes
php artisan test tests/Feature/CatalogHomePerformanceTest.php
php artisan test tests/Feature/CatalogFacetCacheTest.php tests/Feature/CatalogCacheInvalidatorTest.php
php artisan test tests/Feature/CatalogPageTest.php tests/Feature/Api/V1/CatalogHomeApiTest.php
```

Фактический API contract находится в
`tests/Feature/Api/V1/CatalogDiscoveryTest.php` и
`CatalogRelatedContentTest.php`; вместе с cache/web regression matrix
прошли 38 тестов / 831 утверждение. Focused performance class прошёл
14/68.

### Task 8.2 — Full и static checks

**Приоритет:** high
**Статус:** completed

**Команды по доступности:**

```bash
php artisan test
./vendor/bin/phpstan analyse app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php
./vendor/bin/rector process --dry-run app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php
composer validate --no-check-publish
php artisan route:list
php artisan config:show cache-architecture
```

**Риск:** full suite падает на parallel dirty Task 92–94.

**Проверка:** исследовать каждую ошибку; не скрывать `|| true`; task-related
failure исправить, foreign failure записать с test name и evidence.

**Evidence:** Pint, syntax, task-scoped PHPStan/Rector и Composer validation
прошли. Стандартный full suite упёрся в закреплённый `256M`; идентичная
временная конфигурация с `1G` завершила 2 065 тестов: 2 046 passed,
11 skipped, 7 foreign failures и 1 foreign error. Ни одного Task 95
failure нет; точные имена и owners записаны в current plan.

### Task 8.3 — Frontend/build/browser

**Приоритет:** medium
**Статус:** completed

**Что:** PHP-only изменение не требует новых assets, но shared homepage
contracts и обязательный широкий риск требуют `npm run build` и managed
Chromium проверки `/`, `/ru`, `/api/v1/home`.

**Проверка:** desktop/mobile status, H1, no horizontal overflow, no
console/network/local-asset errors, unchanged subtitle facet.

**Evidence:** `npm run build` собрал 26 модулей. Managed Chromium:
`1440×1200` — HTTP 200 / `2 685 ms`, `390×844` — HTTP 200 /
`1 646 ms`, API — HTTP 200; H1 и subtitle link/facet присутствуют,
overflow/console/page/request/local-asset errors отсутствуют. Артефакты
находятся в ignored `output/playwright/task95-home/`.

---

## Этап 9. Final audit, commit и push

### Task 9.1 — Перечитать требования и проверить legacy/duplicates

**Приоритет:** critical
**Статус:** completed

**Что:** перечитать применимые canonical requirements, искать old inline
subtitle query, duplicate resource, stale cache key, TODO/debug/secrets.

**Проверка:** один authoritative query callback, одна resource identity, все
usage paths объяснены.

**Evidence:** final canonical reread выполнен; repository search нашёл одну
resource identity и один private callback. Старого inline count, duplicate
cache service, Blade query, TODO/debug или diff secret не найдено.

### Task 9.2 — Проверить exact diff/index

**Приоритет:** critical
**Статус:** completed

**Команды:** `git status`, `git diff`, `git diff --stat`,
`git diff --cached`, untracked/branch/remote checks, task-scoped
`git diff --check`, secret/debug/binary/formatting scan.

**Риск:** shared index содержит чужие staged paths.

**Проверка:** isolated temporary index основан на текущем HEAD и содержит
только exact Task 95 files/hunks; staged diff, stat, whitespace, secret,
debug и binary checks прочитаны полностью.

### Task 9.3 — Commit

**Приоритет:** critical
**Статус:** completed

**Ожидаемый commit:** `perf: reuse homepage subtitle facet snapshot`.

Design commit уже отделён как
`docs: design homepage subtitle facet snapshot`.

**Проверка:** branch `main`, commit tree содержит только заявленные Task 95
изменения, hook/policy results зафиксированы.

**Evidence:** hook-enabled commit
`c31bb5974c40631d175ca734060e5fa9435eb743`
(`perf: reuse homepage subtitle facet snapshot`) содержит восемь exact Task
95 файлов/hunks.

### Task 9.4 — Push

**Приоритет:** critical
**Статус:** unresolved

**Команда:** обычный `git push origin main` либо configured upstream push, без
force.

**Риск:** HTTPS remote не имеет credentials.

**Проверка:** сохранить фактический stdout/stderr. При отказе оставить
локальные commits, указать branch/hash/command/error и отметить
`unresolved`.

**Evidence:** `GIT_TERMINAL_PROMPT=0 git push origin main` завершился с
кодом `128` до передачи данных:
`fatal: could not read Username for 'https://github.com': terminal prompts disabled`.
