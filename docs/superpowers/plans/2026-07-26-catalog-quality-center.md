# План реализации центра качества каталога

> **For Codex:** выполнять по TDD, немедленно обновлять statuses/evidence при
> discovery и перед завершением перечитать canonical requirements.

**Цель:** для каждой активной карточки каталога хранить объяснимый
`quality_score 0..100`, поддерживать bounded recalculation и дать администраторам
быструю индексируемую очередь проблем.

**Архитектура:** versioned persisted snapshot + normalized current issues,
batch input loader/evaluator/recalculator, bounded command/schedule и read-only
full-page Livewire center.

**Стек:** PHP 8.5, Laravel 13.22, Livewire 4.3, Tailwind 4.3, SQLite, PHPUnit
12.5.

## 1. Critical — подготовка и существующее поведение

1. `[completed]` Перечитать root instructions, requirements index и все
   применимые canonical owners.
   - Что/почему: закрепить обязательные architecture, admin, DB, UI, operations,
     test и Git boundaries до кода.
   - Файлы: `AGENTS.md`, `docs/requirements/*`, architecture/development/
     administration/authorization/importer/data/security/performance/caching/
     UI/frontend/testing/queues/deployment/operations/audits.
   - Зависимости: repository docs.
   - Риск: пропустить cross-feature правило.
   - Проверка: compliance matrix и linked design.
2. `[completed]` Проверить версии, branch, remote, dirty staged/unstaged/
   untracked scope.
   - Что/почему: писать Laravel 13/Livewire 4 code и не захватить foreign work.
   - Файлы: Composer/npm locks, Git metadata.
   - Риск: общий dirty checkout.
   - Проверка: PHP/Laravel/packages/SQLite/main/origin facts записаны.
3. `[completed]` Трассировать models/schema/import/tag/media/admin/scheduler/
   tests и фактические data distributions.
   - Что/почему: thresholds и indexes должны опираться на реальные данные.
   - Модули: `CatalogTitle`, `SourcePage`, `Season`, `Episode`,
     `LicensedMedia`, ratings/tags/provenance, admin registry/routes.
   - Риск: абсолютный episode limit даст false positive на 3 538 серий.
   - Проверка: safe aggregate SQL и конкретный Flower-of-Evil fixture.
4. `[completed]` Сравнить on-request, single-column и snapshot/issues designs.
   - Что/почему: выбрать объяснимую и производительную модель.
   - Файл: linked design.
   - Проверка: persisted normalized design approved under explicit autonomy.

## 2. Critical — canonical design, files и compatibility

1. `[completed]` Записать design, scoring rules, privacy, indexes, rollout и
   rollback.
   - Файл: `docs/superpowers/specs/2026-07-26-catalog-quality-center-design.md`.
   - Риск: документация обещает непроверенный результат.
   - Проверка: status остаётся implementation pending до GREEN.
2. `[completed]` Перечислить expected files и protected contracts до edit.
   - Зависимости: actual code inventory.
   - Проверка: manifests ниже и Task 84 current plan.
3. `[completed]` Обновить canonical product/data/admin/operations owner до
   implementation.
   - Файлы: новый `docs/catalog-quality.md`, `docs/README.md`,
     `docs/DATA_RELATIONS.md`, `docs/administration.md`, `docs/importer.md`,
     `docs/performance.md`, `docs/deployment.md`, `docs/views.md`.
   - Риск: duplicate source of truth.
   - Проверка: ownership map содержит единственного owner, остальные только
     интеграционные ссылки.

## 3. Critical — TDD RED

1. `[completed]` Создать schema/model regression.
   - Что: up/down, FK cascade, unique issue code, queue/refresh indexes,
     score casts/relations.
   - Файлы: `tests/Feature/CatalogQualitySchemaTest.php`.
   - Риск: SQLite migration проходит без реального FK/index contract.
   - Проверка: intended RED до migration/models.
2. `[completed]` Создать evaluator unit tests.
   - Что: healthy 100-ish case; each signal; boundaries; score clamp;
     idempotent issue codes; Flower-of-Evil relevance/provenance.
   - Файл: `tests/Unit/CatalogTitleQualityEvaluatorTest.php`.
   - Риск: тест копирует private implementation.
   - Проверка: assertions только public result/code/category/penalty/evidence.
3. `[completed]` Создать recalculator/command tests.
   - Что: batched facts, aggregate gaps/media, first-detected preservation,
     resolved deletion, missing/dirty/version/stale ordering, bounded limit.
   - Файлы: `tests/Feature/CatalogQualityRecalculationTest.php`,
     `tests/Feature/CatalogQualityRefreshCommandTest.php`.
   - Риск: N+1 скрыт маленькой fixture.
   - Проверка: query delta 1/20 и no network.
4. `[completed]` Создать Livewire/admin tests.
   - Что: route/middleware/permission, seven queues, combined filters,
     zero/min/max, invalid values, URL state, reset, pagination/sort,
     empty/unavailable/error and safe output.
   - Файл: `tests/Feature/Administration/CatalogQualityCenterTest.php`.
   - Риск: проверять только markup, не SQL behavior.
   - Проверка: fixtures с реальными snapshot/issues + query budget.

## 4. Critical — database и domain model

1. `[completed]` Создать additive reversible migration через Artisan.
   - Файл: `database/migrations/2026_07_26_045634_create_catalog_title_quality_tables.php`.
   - Зависимости: `catalog_titles`.
   - Риск: index name/SQLite FK/large migration lock.
   - Проверка: migrate/rollback/migrate на disposable SQLite, FK/index list.
2. `[completed]` Добавить enums severity/category и DTO result/facts/view data.
   - Файлы: `app/Enums/CatalogQuality*`,
     `app/DTOs/CatalogQuality/*`.
   - Риск: translated values попадут в identity.
   - Проверка: stable values, RU/EN labels только translation layer.
3. `[completed]` Добавить snapshot/issue models и relations.
   - Файлы: `app/Models/CatalogTitleQualitySnapshot.php`,
     `app/Models/CatalogTitleQualityIssue.php`, `CatalogTitle`.
   - Риск: mass assignment/casts/soft-delete mismatch.
   - Проверка: fillable/casts/generic relations/FK cascade tests.

## 5. Critical — scoring backend

1. `[completed]` Реализовать batch fact loader.
   - Файл: `app/Services/Catalog/Quality/CatalogTitleQualityInputLoader.php`.
   - Зависимости: selected columns, grouped aggregates, current provenance.
   - Риск: episode/media materialization и N+1.
   - Проверка: no full episode/media models; query delta and memory bounds.
2. `[completed]` Реализовать deterministic versioned evaluator.
   - Файл: `CatalogTitleQualityEvaluator.php`.
   - Зависимости: project normalization and config thresholds.
   - Риск: false-positive tag/episode/translation anomalies.
   - Проверка: conservative fixtures, provenance bypass, ratio-based gaps,
     production distributions.
3. `[completed]` Реализовать transactional recalculator.
   - Файл: `CatalogTitleQualityRecalculator.php`.
   - Зависимости: snapshots/issues unique indexes.
   - Риск: stale/partial issue set, SQLite write duration.
   - Проверка: per-title short transaction, upsert + resolved cleanup,
     idempotency/rollback tests.
4. `[completed]` Реализовать dirty tracker.
   - Файл: `CatalogTitleQualityDirtyTracker.php`.
   - Интеграции: exact catalog cache invalidation, importer/media health.
   - Риск: global invalidation превращается в 33k update.
   - Проверка: только bounded exact IDs; missing snapshot требует no write.
5. `[completed]` Реализовать bounded refresh command и schedule.
   - Файлы: `app/Console/Commands/RefreshCatalogQuality.php`,
     `routes/console.php`, `config/catalog-quality.php`.
   - Риск: overlapping scheduler/long writer/no progress.
   - Проверка: missing-first deterministic IDs, max limit, no network,
     `withoutOverlapping`/`onOneServer`, repeat progress.

## 6. High — admin query и frontend

1. `[completed]` Реализовать allowlisted queue query/summary/presenter.
   - Файл: `app/Services/Catalog/Quality/CatalogQualityQueueQuery.php`.
   - Зависимости: issue category/severity indexes.
   - Риск: correlated subquery/full table scan/N+1.
   - Проверка: `whereIn` indexed subquery, selected columns, paginator,
     `EXPLAIN QUERY PLAN`, query delta.
2. `[completed]` Реализовать full-page Livewire component.
   - Файл: `app/Livewire/Administration/CatalogQualityCenterPage.php`.
   - Зависимости: `content.view`, URL attributes, WithPagination.
   - Риск: tampered public properties/unbounded search/per-page.
   - Проверка: Validator allowlists, normalization, 15/25/50, score 0..100,
     resetPage and tests.
3. `[completed]` Добавить route/navigation и translations.
   - Файлы: `routes/web.php`, `AdminNavigationRegistry`,
     `lang/{ru,en}/administration.php`, `lang/{ru,en}/catalog-quality.php`.
   - Риск: нарушить existing route names/order/parity.
   - Проверка: route list/security/nav/translation parity.
4. `[completed]` Реализовать responsive Blade.
   - Файл: `resources/views/livewire/administration/catalog-quality-center.blade.php`.
   - Зависимости: existing UI components/classes.
   - Риск: DB/business logic in Blade, XSS, unreadable mobile list.
   - Проверка: escaped prepared data, no `@php`/queries/inline CSS/JS,
     loading/empty/error, 44px targets, desktop/mobile Playwright/axe.

## 7. High — validation, authorization, security и errors

1. `[completed]` Валидировать queue/search/score/sort/per-page перед query.
   - Почему: Livewire state является untrusted.
   - Проверка: invalid/empty/zero/min/max/impossible range tests.
2. `[completed]` Сохранить canonical admin middleware и Gate.
   - Проверка: guest redirect, ordinary/content-less forbidden,
     content viewer allowed.
3. `[completed]` Ограничить evidence и errors.
   - Проверка: no source/playback URLs, provider raw payload, secrets,
     exceptions or personal data in DB/HTML/log assertions.
4. `[completed]` Проверить SQL/XSS/CSRF/mass-assignment/IDOR/rate bounds.
   - Проверка: bindings/escaped output/read-only route/allowlisted sort;
     no write action значит CSRF not applicable.

## 8. High — performance, compatibility и cross-feature

1. `[completed]` Выполнить `EXPLAIN QUERY PLAN` для due refresh и queue
   variants.
   - Проверка: named indexes используются; temporary sort bounded paginator.
2. `[completed]` Проверить request query count и payload.
   - Проверка: 1 vs 20 rows delta <=2; reasons bounded.
3. `[completed]` Проверить importer/admin/tag/media invalidations.
   - Риск: score остаётся stale после изменения.
   - Проверка: exact title gets `needs_refresh`; global no-ID invalidation не
     запускает mass update; scheduler repairs by age.
4. `[completed]` Проверить public compatibility.
   - Защищены: routes/API/search/recommendations/SEO/cache/player/import/
     notifications/Premium/region/legal/privacy.
   - Проверка: no public response/schema/ranking diff; related tests.

## 9. High — проверки

1. `[completed]` Запустить intended RED и зафиксировать точную причину.
2. `[completed]` После минимальной реализации запустить focused GREEN.
3. `[completed]` Запустить Pint по точному Task 84 PHP scope: общий
   `--dirty` захватил бы foreign shared-tree изменения.
4. `[completed]` Запустить PHP syntax, scoped Larastan и Rector dry-run.
5. `[completed]` Запустить migration/rollback/FK/index/EXPLAIN checks.
6. `[completed]` Запустить related admin/import/tag/media/catalog tests.
7. `[completed_with_foreign_failures]` Запустить полный PHPUnit: 2 020
   tests, 2 004 passed, 11 skipped; три concurrent header/account failures и
   одна missing foreign import-dispatch class error, Task 84 failures нет.
8. `[completed]` Запустить `composer validate --strict`, audits и docs gate.
9. `[completed]` Запустить `npm run build`.
10. `[completed]` Запустить Playwright desktop `1440×1200`, mobile `390×844`
    и tablet,
    проверить screenshot/axe/console/network/overflow.

Каждый failure разбирается по systematic-debugging: воспроизведение, root
cause, minimal fix, повторный focused и затем broad gate; `|| true` запрещён.

## 10. Medium — документация и final delivery

1. `[completed]` Обновить canonical owner/integration docs, README visitor
   history и русский CHANGELOG.
2. `[completed]` Перечитать requirements/design/plan, закрыть compliance matrix.
3. `[completed]` Repository-wide search legacy/duplicate/dead/debug/TODO/secrets.
4. `[pending]` Проверить `git status`, staged/unstaged/untracked,
   `git diff`, `--stat`, `--cached`, branch/remote и exact task scope.
5. `[pending]` Создать один logical exact-scope commit в existing `main`.
6. `[pending]` Выполнить обычный non-force `git push origin main`; внешний
   auth/network/rules failure оставить честным `unresolved`.

## Expected changed files

- migration/config/enums/models/DTOs under catalog quality domain;
- `CatalogTitle` relation;
- quality loader/evaluator/recalculator/dirty/query services;
- bounded command and `routes/console.php`;
- exact integration hunks in catalog invalidation/import/media;
- admin Livewire class/view, route/navigation, RU/EN translations;
- focused unit/feature/admin/browser tests;
- canonical quality/data/admin/import/performance/deployment/view docs;
- design/plan/current compliance evidence, README and CHANGELOG.

Discovery may reduce this list, but any expansion of public API, dependency,
environment, permission identity, cache key or production data mutation must
first update design/plan.

## Protected contracts

- existing `main`, foreign dirty changes and all unrelated files;
- every existing public/admin route name and middleware;
- public JSON/API Resources/OpenAPI, search/recommendation/SEO ranking;
- `CatalogTitle` route binding and catalog relations;
- one public `seasonvar:import` command;
- media/source URL secrecy and no video download/storage;
- current tags/provenance and provenance-first repair;
- cache keys/TTL/invalidation semantics;
- permissions/roles/policies/audit identities;
- user/private/Premium/payment/region/legal/player/notification state;
- no `.env`, secret, vendor/node_modules/storage/generated artifacts in Git.
