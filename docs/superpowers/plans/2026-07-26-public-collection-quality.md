# Качество публичных подборок — план реализации

Дата: 26.07.2026.

Design:
[`2026-07-26-public-collection-quality-design.md`](../specs/2026-07-26-public-collection-quality-design.md).

Статусы: `pending`, `in_progress`, `completed`, `skipped`, `unresolved`.

## 1. Анализ текущего поведения

- [completed][critical] Прочитать root `AGENTS.md`, requirement index и все
  применимые canonical/collection/demo/import/SEO/cache/security/operations
  документы. Проверка: каждый contract отражён в matrix.
- [completed][critical] Проверить PHP/Laravel/packages/frontend/database,
  branch, remote, staged/unstaged/untracked state. Риск: shared dirty tree;
  чужие hunks не stage/reset/stash.
- [completed][critical] Выполнить read-only census public collections,
  categories, membership buckets, deterministic demo provenance, source
  provenance и recommendation signals. Проверка: SQL aggregates и sample
  fingerprints согласованы.
- [completed][high] Проследить routes, Livewire/API/SEO/sitemap/profile/title
  consumers, policies, services, importer, demo stages, jobs/cache,
  factories/tests и schema indexes.

## 2. Основная функциональность и backend

- [completed][critical] Добавить один canonical public quality scope в
  `CatalogCollection`; переиспользовать его всеми существующими public
  consumers. Риск: query cost и скрытие легитимных rows. Проверка:
  categorized bounded fixture остаётся, четыре invalid класса исключены.
- [completed][critical] Закрыть write bypass: pending editorial publication,
  moderation eligibility и public/unlisted membership ceiling.
- [completed][critical] Сделать новые demo/source collections private by
  default; sync не должен перезаписывать human category/moderation.
- [completed][critical] Ограничить source recommendation signals canonical
  eligibility.
- [completed][high] Расширить readiness category/size reason codes и
  fail-closed featured query.

## 3. Изменения базы данных и repair

- [completed][high] Проверить schema/indexes/FK. Решение: migration и новый
  index предварительно не нужны; existing covering indexes соответствуют
  predicates.
- [completed][critical] Реализовать exact `DemoPublicCollectionCleaner`,
  сохраняющий memberships и identity.
- [completed][critical] Реализовать exact
  `HdRezkaPublicCollectionCleaner`, удаляющий только связанные stale
  recommendation signals и материализацию затронутых тайтлов.
- [completed][critical] Добавить dry-run-first
  `catalog-collections:repair-public-quality` с production confirmations,
  active-writer/build gates и JSON output.
- [completed][high] Интегрировать demo cleaner в существующий
  `demo:repair-user-portal`, сохранив его старые counters/contracts additively.
- [unresolved][critical] До production DML проверить backup, paused writers,
  active import/build и dry-run. При отсутствии evidence оставить apply
  `unresolved`, не обходить gate.

## 4. Frontend, validation, authorization и errors

- [completed][high] Сохранить `/discover/popular#collections`, URL-backed
  search/sort/category/page, reset/back/forward и существующий empty state.
- [completed][high] Добавить понятные RU/EN validation/readiness messages;
  Blade не выполняет query и не получает authoritative client state.
- [completed][critical] Сохранить policies/gates; repair остаётся CLI-only и не
  принимает произвольные resource IDs.
- [completed][high] Direct ineligible legacy page получает noindex/404 согласно
  public API/policy boundary без раскрытия private metadata.
- [completed][high] Ошибки repair репортятся в private log, пользователю
  возвращается generic Russian message; empty catch/debug output запрещены.

## 5. SQL, performance и security

- [completed][high] Запустить `EXPLAIN QUERY PLAN` для directory count/page,
  category counts, sitemap, API list и repair candidate queries.
- [completed][high] Проверить query count/N+1 и projection текущей страницы;
  correlated membership predicates должны использовать covering index.
- [completed][critical] Проверить SQL injection, mass assignment, CSRF/IDOR,
  secrets/logging, unbounded input/result sets и cache invalidation.
- [completed][medium] Не добавлять cache, queue, dependency или infrastructure
  без измеренной необходимости.

## 6. TDD и проверки

- [completed][critical] RED: uncategorized, empty, inactive-category и
  oversized public rows ошибочно доступны; valid combined filters остаются.
- [completed][critical] RED: new HDRezka/demo rows публикуются, moderation
  принимает invalid row, source signals доверяют unreviewed source.
- [completed][critical] RED: dry-run/repair provenance, production flags,
  active import/build, preservation и idempotence.
- [completed][critical] GREEN минимальными domain changes.
- [completed][high] Focused collection/demo/import/recommendation/API/SEO tests:
  78 tests / 6 183 assertions. Full 1G suite reached 1 893 tests with
  1 877 pass and 196 813 assertions; four unrelated concurrent failures and
  one absent importer class remain `unresolved`.
- [completed][high] Exact-manifest Pint, scoped PHPStan (0 errors), Rector
  dry-run (0 changes), `npm run build`, Composer, route/config/migration and
  dry-run checks.
- [completed][high] Browser desktop/mobile/tablet QA: directory,
  category/search/sort,
  empty/loading/error, no overflow/console/page/HTTP errors.

## 7. Документация, финальная проверка, commit и push

- [completed][high] Обновить canonical `architecture.md`,
  `DATA_RELATIONS.md`, collection search/views, importer/demo/deployment
  docs; старый auto-public contract отметить superseded.
- [completed][critical] Обновить `README.md`, русскую dated запись
  `CHANGELOG.md`, эту live checklist и current-task compliance evidence.
- [completed][critical] Перечитать применимые requirements; найти duplicate
  public scopes, old auto-public/demo visibility, stale signals, TODO/debug
  и связанные legacy paths.
- [completed][critical] Проверить `git status`, branch/remote, exact
  staged/unstaged/untracked diff, secrets, debug и formatting noise.
- [completed][critical] Через изолированный exact manifest зафиксировать
  только Task 67 в существующей `main`; commit `cce9fa6` прошёл штатные
  hooks, foreign working-tree/index не включён.
- [completed_with_unresolved_push][critical] Выполнить non-force push
  configured `main`; GitHub отклонил HTTPS-доступ до передачи данных:
  `fatal: could not read Username for 'https://github.com': No such device or address`.

## Ожидаемые изменяемые файлы

- `app/Models/CatalogCollection.php`;
- `app/Enums/CatalogCollectionReadinessReason.php`;
- `app/Services/Collections/CatalogCollectionPublicationReadiness.php`;
- `app/Services/Collections/CatalogCollectionService.php`;
- `app/Services/Collections/CatalogCollectionModerationService.php`;
- `app/Services/Collections/CatalogCollectionItemService.php`;
- `app/Services/Collections/Import/HdRezkaCollectionReconciler.php`;
- `app/Services/Collections/Import/HdRezkaCollectionSignalSynchronizer.php`;
- новые collection/demo quality cleaner/repair command;
- `app/Services/DemoData/Stages/DemoOrganizationStage.php`;
- `app/Services/DemoData/DemoUserPortalRepairer.php`;
- `config/catalog-collections.php`;
- `lang/ru/collections.php`, `lang/en/collections.php`;
- focused collection/demo/import/API/SEO tests;
- canonical/topic docs, `README.md`, `CHANGELOG.md`,
  `docs/plans/current-task-plan.md`.

## Защищённые contracts

- существующая ветка `main`, routes/names и единственный public directory;
- Livewire query keys, pagination, reset и locale aliases;
- collection stable ID/public UUID/slug history/category relation/item order;
- policy/gate/admin audit/cache domains;
- `seasonvar:import` и отдельный `catalog-collections:sync-hdrezka`;
- source provenance и exact title matching;
- API resource shape и deprecated `cover_url=null`;
- text-only cards, no collection images;
- comments/reports/users/memberships/private data;
- SQLite compatibility, queue/cache/import workers;
- все foreign staged/unstaged/untracked изменения shared tree.

## Task-specific compliance matrix

| Requirement/domain | Статус | Evidence / gate |
| --- | --- | --- |
| Root/index/canonical requirements | `completed` | Fresh read 26.07.2026 |
| Versions/stack/database | `completed` | PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, SQLite |
| Official Laravel 13 behavior | `completed` | Boost docs checked for scopes/relationship queries/chunking |
| Existing implementation first | `completed` | Public/import/demo/readiness/signal paths traced |
| Read-only dataset evidence | `completed` | 501 = 447 exact demo + 54 exact source; all uncategorized |
| Alternatives/design/user authority | `completed` | Unified scope + reversible repair selected |
| Migration/index | `not_applicable` | Existing indexes selected by SQLite EXPLAIN; no duplicate write cost added |
| Routes/API/cache/dependencies | `already_compliant` | Existing names/shapes/domains retained; no package or infrastructure |
| Authorization/security/privacy | `completed` | Canonical policy/API scope, CLI-only exact repair, generic error and no secret/raw IDs |
| Production/rollback/data safety | `unresolved` | Dry-run complete; one active importer and no verified backup/writer-pause evidence, so no DML |
| TDD implementation | `completed` | RED/GREEN public scope, moderation, generation, signals, repair and idempotence |
| Frontend/mobile/a11y | `completed` | Existing component/state retained; managed Chromium desktop/mobile/tablet 3/3 |
| Docs/README/CHANGELOG | `completed` | Canonical/product/operations owners updated |
| Full default PHPUnit process | `unresolved` | XML `256M` limit exhausts accumulated suite; explicit 1G run has 4 foreign concurrent failures and 1 absent foreign importer class |
| Managed documentation refresh | `completed` | Exact staged tree прошёл `project:docs-refresh --check`; generated blocks взяты из task-only export без чужой untracked migration |
| Commit/push | `completed_commit_unresolved_push_authentication` | Exact 54-file commit `cce9fa6` создан в `main`; configured push завершился кодом 128 до передачи данных из-за отсутствующей GitHub-аутентификации |
