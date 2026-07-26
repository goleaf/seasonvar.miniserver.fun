# План реализации provenance метаданных каталога

> Выполнять как живой checklist через TDD. Статус меняется сразу после
> проверки evidence, а не по факту написания кода.

## Critical — анализ и постоянный контракт

- `[completed]` Прочитать root `AGENTS.md`, requirements index, все
  применимые canonical/feature Markdown и skills.
  - Почему: постоянные требования определяют architecture, production и Git.
  - Файлы: read-only requirements, quality/import/admin/data/security/ops docs.
  - Зависимости: repository snapshot.
  - Риск: дублировать существующий provenance boundary.
  - Проверка: compliance matrix и design перечисляют найденные owners.
- `[completed]` Проверить runtime/packages/frontend/DB/routes/models/services/
  migrations/tests/CI/Git.
  - Почему: Laravel/API/SQLite и dirty tree должны быть фактическими.
  - Риск: version drift или включение чужих изменений.
  - Проверка: PHP/Laravel/Boost/Livewire/PHPUnit/Tailwind/SQLite и main
    записаны в current plan.
- `[completed]` Проследить текущие write/read paths.
  - Модули: `SeasonvarCatalogImporter`, `SeasonvarEditorialFieldResolver`,
    relation/tag sync, `CatalogAdministrationService`, quality
    loader/recalculator/command/query/Livewire.
  - Проверка: design сохраняет существующие boundaries.
- `[completed]` Сначала обновить canonical owner.
  - Файл: `docs/catalog-quality.md`.
  - Риск: обещать автоматическую mutation.
  - Проверка: документ разделяет evidence, confidence и read-only review.

## Critical — TDD и схема

- `[completed]` Написать RED schema test для четырёх новых таблиц и nullable
  run links существующего quality storage.
  - Файлы: `tests/Feature/CatalogMetadataProvenanceSchemaTest.php`.
  - Зависимости: migrations.
  - Риск: слабые/дублирующие индексы.
  - Проверка: test падает на отсутствующей таблице/колонке.
- `[completed]` Написать RED recorder tests.
  - Файлы: `tests/Feature/CatalogMetadataProvenanceRecorderTest.php`.
  - Сценарии: initial provider, idempotent confirmation, changed selected
    value, editorial conflict, conflict resolution, normalized arrays/null.
  - Риск: тестирует реализацию вместо поведения.
  - Проверка: отсутствующие классы/таблицы дают ожидаемый RED.
- `[completed]` Создать additive reversible migrations через Artisan.
  - Таблицы: observations/conflicts/runs/versions; nullable quality run FKs.
  - Риск: SQLite FK/drop order, rolling deployment.
  - Проверка: migrate:fresh, rollback/forward, foreign_key_check, schema test.
- `[completed]` Добавить Eloquent models/relationships/casts/fillable.
  - Риск: mass assignment или lazy-loading.
  - Проверка: model assertions и relationship tests.

## High — backend provenance

- `[completed]` Реализовать нормализацию/hash/confidence policy.
  - Файлы: `app/Enums/CatalogMetadata*`,
    `app/Services/Catalog/Quality/CatalogMetadataProvenanceRecorder.php`.
  - Зависимости: validated DTO/admin input.
  - Риск: разные hashes эквивалентных строк/arrays.
  - Проверка: Unicode/whitespace/order/null unit cases.
- `[completed]` Реализовать atomic observation/version/conflict transition.
  - Почему: concurrent import/admin save не должен давать две current rows.
  - Зависимости: существующие outer transactions и retry policy.
  - Риск: nested transaction/race/version duplicate.
  - Проверка: idempotency, unique constraints, transaction rollback.
- `[completed]` Интегрировать provider snapshot в import transaction и
  metadata backfill через общий Seasonvar adapter.
  - Файл: `SeasonvarCatalogImporter`.
  - Зависимости: title/relations уже сохранены.
  - Риск: write amplification/partial snapshot.
  - Проверка: importer test, query count, incomplete taxonomy behavior.
- `[completed]` Интегрировать editorial selected fields.
  - Файл: `CatalogAdministrationService`.
  - Зависимости: current policy/version/audit transaction.
  - Риск: provenance без фактического изменения.
  - Проверка: authorized edit creates one version; no-op does not.
- `[completed]` Использовать observations в quality loader с fallback на
  `provider_field_values`.
  - Риск: миграция ещё не развернута.
  - Проверка: old-schema fallback и grouped query.
- `[completed]` Интегрировать `catalog_quality_runs` в существующую команду.
  - Файлы: command/recalculator/models.
  - Риск: exception скрыт или run остаётся running.
  - Проверка: success/empty/failure/invalid limit tests.

## High — frontend/admin UX

- `[completed]` Написать RED Livewire/query tests для provenance rows.
  - Поля: год/provider/date/98%, тег without provenance/12%/проверить.
  - Security: guest/insufficient permission.
  - Performance: constant page-level query count.
- `[completed]` Реализовать page-bounded provenance query/presenter DTO.
  - Зависимости: field versions, observations, conflicts, canonical tag
    provenance/mappings.
  - Риск: N+1, private value leak, unbounded text.
  - Проверка: selected columns/grouped queries and escaped/truncated output.
- `[completed]` Добавить раскрываемый блок в существующую quality card.
  - Файлы: Livewire Blade + ru/en translation owner.
  - UX: native `details`, keyboard accessible, mobile one-column/table-like
    layout, empty state.
  - Проверка: Livewire feature test, Blade contract, browser desktop/mobile.

## High — validation, authorization, security, errors

- `[completed]` Подтвердить server-owned allowlists field/source/status.
- `[completed]` Сохранить `content.view` gate и отсутствие нового public write.
- `[completed]` Проверить Blade escaping, description bounds, отсутствие raw
  source/media URLs и exception messages.
- `[completed]` Проверить SQL bindings, fillable, nullable/FK delete behavior.
- `[completed]` Проверить safe schema-unavailable fallback и reported errors.

Для каждого пункта:

- Почему: provenance содержит внутреннюю диагностику и не должна расширять
  public/API/privacy boundary.
- Проверка: focused auth/security tests, static search and exact diff review.

## High — SQL и производительность

- `[completed]` Проверить индексы против фактических current/conflict/version/
  run queries.
- `[completed]` Проверить EXPLAIN для current observations/conflict queue и
  query budget grouped page presenter.
- `[completed]` Исключить model/query loops и выбрать только display columns.
- `[completed]` Зафиксировать constant quality-page SQL budget для 1/25 rows.

Риск: evidence history увеличивает INSERT cost. Mitigation: upsert identical
observation, no-op selected version, page-bounded reads, no synchronous
backfill.

## Medium — совместимость и verification

- `[completed]` Focused provenance/schema/import/admin/quality/Livewire tests.
- `[completed]` `./vendor/bin/pint --dirty --format agent`.
- `[completed]` Related quality/tag/import/admin/security tests: `81` tests,
  `479` assertions.
- `[completed_with_foreign_failures]` Full `php artisan test`: штатный
  `256M` остановился на накопительном memory limit; временный `1G` config
  выполнил `2056` tests (`2031` passed, `13` failed, `1` error, `11`
  skipped). Все failures принадлежат параллельным home/card/translation/
  sitemap/account/importer changes; Task 92 focused/related suites GREEN.
- `[completed]` Existing PHPStan/Rector dry-run if configured.
- `[completed]` `composer validate --strict` и audit.
- `[completed]` `npm run build` и Playwright admin quality
  desktop/mobile/tablet (`3/3`).
- `[completed]` Migration rollback/forward and SQLite integrity checks.

Failure не игнорируется: исправляется либо классифицируется как foreign/
external с точной командой и выводом.

## High — документация и production

- `[completed]` Обновить `docs/catalog-quality.md`, `DATA_RELATIONS.md`,
  importer/admin/testing owners по фактическому GREEN.
- `[completed]` Проверить/обновить README visitor history.
- `[completed]` Добавить отдельную русскую запись CHANGELOG.
- `[completed]` Зафиксировать deploy order, backup, rollback, partial deploy,
  stale cache/provider failure recovery.
- `[completed]` Перечитать applicable requirements и закрыть matrix.

## Critical — commit и push

- `[pending]` Проверить `git status`, branch, remote, diff/stat, staged/
  unstaged/untracked, secrets/debug/mass formatting/unrelated files.
- `[pending]` Подготовить только task paths/hunks, не использовать blind add.
- `[pending]` Проверить cached diff и создать точный commit в `main`.
- `[pending]` Повторно проверить clean tree requirement; foreign dirtiness
  является blocker pre-push и фиксируется честно.
- `[pending]` Выполнить обычный `git push origin main` без force.

## Expected changed files

- новые migrations/models/enums/DTO/provenance recorder/query;
- `CatalogTitle`, quality snapshot/issue relationships;
- `SeasonvarCatalogImporter`, `SeasonvarCatalogMetadataBackfill` и общий
  `SeasonvarCatalogMetadataProvenance`;
- `CatalogAdministrationService`;
- quality loader/recalculator/command/query/Livewire Blade;
- focused schema/recorder/import/admin/command/Livewire tests;
- `lang/ru/catalog-quality.php`, `lang/en/catalog-quality.php`;
- canonical quality/data/import/admin/testing docs;
- current plan, design/plan, README, CHANGELOG.

## Protected contracts

- `main` и весь foreign staged/unstaged/untracked scope;
- `seasonvar:import` как единственная публичная import command;
- public routes/names/API/resources/query/filter/pagination JSON;
- `CatalogTitle` binding по slug и current catalog relations;
- `catalog_title_tag_sources`/provider mapping как единственный tag
  provenance owner;
- public tag moderation/visibility/eligibility;
- current quality score formula/queues and read-only behavior;
- admin policies/gates/audit/optimistic lock;
- search/recommendation/SEO/cache/player/notifications/premium/region/legal;
- secrets, source HTML/URLs, media URLs и production data.
