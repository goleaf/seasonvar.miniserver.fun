# План provenance-first исправления качества тегов

> **For Codex:** выполнять этот план через `superpowers:executing-plans`, соблюдая TDD и обновляя статусы/evidence после каждого discovery.

**Цель:** удалить доказанный deterministic demo-noise из глобальных тегов, предотвратить повторное загрязнение и сохранить все подтверждённые provider/editorial назначения.

**Архитектура:** существующий demo repair command orchestration + новый bounded `DemoPublicTagAssignmentCleaner`; никаких новых route/API/UI/schema boundaries.

**Стек:** PHP 8.5, Laravel 13.22, Eloquent/query builder, SQLite, PHPUnit 12.5.

## Task 1 — Critical: requirements, baseline и root cause

- `[completed]` Прочитать root `AGENTS.md`, `docs/requirements/index.md`, все canonical owners и относящиеся Markdown.
  - Почему: постоянные требования имеют приоритет и требуют подготовки до production PHP.
  - Файлы: read-only `AGENTS.md`, requirements, importer/tag/search/recommendation/demo/ops docs.
  - Зависимости: отсутствуют.
  - Риск: пропустить cross-feature owner.
  - Проверка: task matrix перечисляет прочитанные owners и protected contracts.
- `[completed]` Проверить версии, stack, DB, branch, remote и чужие изменения.
  - Почему: версия API и безопасная изоляция commit зависят от фактического snapshot.
  - Файлы: `composer.lock`, `package-lock.json`, Git metadata, Boost application info.
  - Зависимости: рабочий checkout.
  - Риск: parallel dirty tree/index.
  - Проверка: PHP/Laravel/Livewire/PHPUnit/Tailwind/SQLite и `main` записаны evidence.
- `[completed]` Воспроизвести «Цветок зла» по БД, snapshot и historical algorithm.
  - Почему: исправляется причина, а не визуальный симптом.
  - Модули: `DemoOrganizationStage`, `DemoStableValue`, `DemoTitleSelector`, tag pivots/provenance.
  - Зависимости: production read-only DB.
  - Риск: принять provider data за demo data.
  - Проверка: exact 8/8 IDs совпали; snapshots не содержат noise; catalog-wide census получен.

## Task 2 — Critical: canonical contract и design

- `[completed]` Сравнить one-title detach, broad orphan cleanup и exact deterministic repair.
  - Почему: минимизировать false-positive deletion.
  - Файлы: design document.
  - Риск: широкий cleanup удалит legacy editorial data.
  - Проверка: выбранный вариант сохраняет current provenance и unrelated pairs.
- `[completed]` Сначала обновить canonical global-tag/demo boundary.
  - Почему: новое permanent rule должно появиться у владельца до кода.
  - Файлы: `docs/DATA_RELATIONS.md`, demo design.
  - Риск: документация обещает непроверенное поведение.
  - Проверка: статус остаётся implementation pending до GREEN.
- `[completed]` Записать data safety, rollback, cache/recommendation/search/SEO impact.
  - Почему: cleanup production-affecting и затрагивает несколько derived domains.
  - Файлы: approved design и этот plan.
  - Проверка: explicit backup/writer/import/build gates и recovery path.

## Task 3 — Critical: TDD RED

- `[completed]` Создать `DemoPublicTagAssignmentCleanerTest`.
  - Почему: поведение должно быть доказано до implementation.
  - Файлы: новый `tests/Feature/DemoData/DemoPublicTagAssignmentCleanerTest.php`.
  - Зависимости: factories/tag schema.
  - Риски: тест повторяет реализацию или не различает provenance.
  - Проверка: отсутствующий service даёт ожидаемый RED; затем assertions проверяют exact boundary.
- `[completed]` Обновить organization-stage test на отсутствие новых global assignments.
  - Почему: regression должен запрещать первопричину.
  - Файлы: `tests/Feature/DemoData/DemoCatalogCorpusStageTest.php`.
  - Проверка: stage сохраняет personal tags/collections, но не создаёт/назначает public tags.
- `[completed]` Обновить repair command test.
  - Почему: dry-run/force/production safety — публичный operational contract.
  - Файлы: `tests/Feature/DemoData/DemoUserPortalRepairCommandTest.php`.
  - Проверка: counters, safety flags, active-run fail closed и idempotency.

## Task 4 — Critical: backend implementation

- `[completed]` Реализовать bounded scan exact historical footprint.
  - Почему: доказуемая ownership boundary.
  - Файлы: новый `app/Services/DemoData/DemoPublicTagAssignmentCleaner.php`.
  - Зависимости: `DemoDataOptions`, `DemoStableValue`, `DemoTitleSelector`, tag schema.
  - Риски: memory/query explosion, changed catalog/tag pool.
  - Проверка: chunked projections, density/fingerprint guard, query-count assertion/measurement.
- `[completed]` Реализовать атомарный repair.
  - Почему: partial delete недопустим.
  - Файлы: cleaner и существующие catalog/tag/recommendation services.
  - Зависимости: no active import/build, unique indexes.
  - Риски: race, long SQLite writer lock, stale recommendation rows.
  - Проверка: transaction rollback test, current-provenance recheck, repeat no-op.
- `[completed]` Инвалидировать derived state.
  - Почему: DB truth не должна расходиться с cache/recommendations.
  - Модули: `TagCacheInvalidator`, `CatalogRecommendationDirtyTitleTracker`, `catalog_title_recommendations`.
  - Риск: targeted cache cap оставит stale title pages.
  - Проверка: global generation bump, affected recommendations removed, dirty IDs marked.
- `[completed]` Подключить cleaner к existing demo orchestration/repair.
  - Почему: не создавать второй production command.
  - Файлы: `DemoOrganizationStage`, `DemoUserPortalRepairer`, `RepairDemoUserPortal`.
  - Риск: production flags обходятся внутренним вызовом.
  - Проверка: seeder только dev/testing; production command сохраняет текущий explicit gate.
- `[completed]` Удалить future public tag generation/assignment из demo stage.
  - Почему: первопричина не должна повторяться.
  - Файлы: `DemoOrganizationStage` и связанные imports/tests.
  - Риск: сломать personal tag/collection demo coverage.
  - Проверка: existing personal/collection assertions зелёные.

## Task 5 — High: database/query review

- `[completed]` Проверить schema/indexes и не добавлять migration без evidence.
  - Почему: существующие composite indexes покрывают repair.
  - Файлы: migrations read-only; новая migration ожидается `not_applicable`.
  - Проверка: schema inventory и `EXPLAIN QUERY PLAN`.
- `[completed]` Проверить N+1, result bounds и statement count.
  - Почему: 123k rows нельзя обрабатывать per-row.
  - Файлы: cleaner/test instrumentation.
  - Проверка: chunked reads и tag-group deletes; никаких model loops с запросом на pair.
- `[completed]` Проверить concurrency и active lifecycle.
  - Почему: import/recommendation build может создать lost update.
  - Модули: `SeasonvarImportActivity`, recommendation builds, command.
  - Проверка: active-state test блокирует write без mutation.

## Task 6 — High: security/authorization/error handling

- `[completed]` Сохранить production safety flags и fail-closed errors.
  - Почему: command меняет production DB.
  - Файлы: command/repairer/cleaner.
  - Риски: ложное подтверждение backup/writers.
  - Проверка: production tests для отсутствующих flags; final report не заявляет неподтверждённое.
- `[completed]` Проверить отсутствие user input/raw interpolation/secrets/log payload.
  - Почему: SQL и operational output не должны раскрывать provider/internal data.
  - Проверка: bindings/query builder, aggregate counters only, secret scan.
- `[completed]` Не добавлять UI/API/write route.
  - Почему: это operator repair, не пользовательская функция.
  - Проверка: route diff empty; auth/policies `not_applicable` с объяснением.

## Task 7 — High: focused verification

- `[completed]` Выполнить RED → GREEN exact cleaner tests.
- `[completed]` Выполнить DemoData organization/repair/orchestrator tests.
- `[completed]` Выполнить tag import/query/cache/recommendation focused matrix.
- `[completed]` Запустить task-scoped Pint.
- `[completed]` Запустить task-scoped PHPStan и Rector dry-run.
- `[completed]` Проверить SQLite schema/foreign keys и query plans; migration не требуется.
- `[completed]` Выполнить production read-only dry-run до write.

Для каждого пункта:

- Почему: отдельные boundaries ловят semantic, SQL, style и lifecycle regressions.
- Файлы: только изменённые PHP/tests и canonical consumers.
- Зависимости: vendor installed, test SQLite.
- Риск: parallel working tree даёт посторонние failures.
- Проверка: exact command/output записывается в Task 63 evidence; failure исправляется или честно классифицируется.

## Task 8 — Medium: broad compatibility

- `[unresolved_shared_tree]` Запустить полный `php artisan test`: default run остановлен накопительным GD memory limit; exact GD test зелёный, следующий широкий run дошёл до foreign concurrent recommendation failure.
- `[completed]` Запустить `composer validate --strict`, `composer audit`, `npm audit` и `npm run build`: manifests валидны, advisories/vulnerabilities отсутствуют, Vite собрал 25 modules.
- `[unresolved_shared_docs]` Запустить managed docs gates: `project:docs-refresh --check` и профиль `docs` остановлены только foreign dirty `docs/MAINTENANCE_LOG.md`; current-plan policy отдельно видит исторический второй H1 вне Task 63 scope.
- `[completed]` Проверить routes/config/views только process-scoped caches.
- `[completed]` Искать legacy writer paths, `TODO`/debug и duplicate tag assignment.

Почему: глобальные tags влияют на search/recommendation/SEO, а dirty shared tree требует regressions. Browser/UI проверка `not_applicable`, если HTML/JS/CSS не меняются; build всё равно подтверждает совместимость.

## Task 9 — Critical: production data repair

- `[unresolved_active_import]` Убедиться, что import terminal, recommendation build не activatable, writers реально остановлены: run `#1255` остаётся `running`, 4 live claims и 9 379 pending jobs подтверждены.
- `[skipped_safety_gate]` Создать согласованный SQLite backup вне tracked/public storage: write window не наступил, поэтому backup/force sequence намеренно не имитировался.
- `[completed]` Запустить `demo:repair-user-portal --dry-run --json`: 123 305 expected, 123 121 matched, 64 protected current, 123 076 кандидатов, 229 owned tags, 16 464 affected titles и 9 985 basis points.
- `[skipped_safety_gate]` Выполнить guarded force repair: запрещено active-import guard и отсутствует реальное подтверждённое writer-pause окно.
- `[completed_read_only]` Проверить «Цветок зла»: план удаляет exact восемь demo pairs и сохраняет `дорама`, `полиция`, `психопат`, `серийные убийства`; post-write/no-op остаются `unresolved_active_import`.
- `[skipped_safety_gate]` Возобновить writers и выполнить post-write smoke: writers не останавливались и production data не менялись.

Риск: active importer сейчас подтверждён; write остаётся `unresolved`, пока безопасное окно реально не наступило. Нельзя снимать этот gate только ради формального completion.

## Task 10 — High: docs и delivery

- `[completed]` Обновить `docs/development.md`, `docs/deployment.md`, `docs/importer.md`, `docs/caching.md`, canonical tag relation и historical demo design/plan.
- `[completed]` Проверить и осмысленно обновить `README.md` для visitor-visible исправления качества тегов.
- `[completed]` Добавить русскую датированную запись `CHANGELOG.md`.
- `[completed]` Перечитать applicable requirements и закрыть compliance matrix; production write честно оставлен unresolved.
- `[completed]` Проверить `git status`, unstaged/staged/untracked, diff/stat, secrets/debug/mass formatting.
- `[completed]` Создать exact isolated commit только task-файлов в `main`: `ee17708e8a1833d512ec6ad467918fa7cc395db9`.
- `[unresolved_external_auth]` Обычный `git push origin main` завершился кодом 128: `fatal: could not read Username for 'https://github.com': No such device or address`.

## Expected changed files

- `app/Services/DemoData/DemoPublicTagAssignmentCleaner.php` (new);
- `app/Services/DemoData/Stages/DemoOrganizationStage.php`;
- `app/Services/DemoData/DemoUserPortalRepairer.php`;
- `app/Console/Commands/RepairDemoUserPortal.php`;
- focused DemoData tests;
- `docs/DATA_RELATIONS.md`, demo design, development/deployment and current plan;
- this design/plan, `README.md`, `CHANGELOG.md`.

No migration, route, controller, Form Request, policy, middleware, API Resource, frontend component, JS/CSS, queue/job, scheduler, dependency or environment setting is expected.

## Protected contracts

- `seasonvar:import` remains the only Seasonvar import command;
- all public routes/names/API/query/filter/pagination/JSON shapes;
- Tag IDs/slugs/provider mappings/translations/aliases/synonyms and current provenance;
- explicit editorial assignment/suppression;
- personal tags and all user portal state;
- source pages/snapshots/import counters and media;
- recommendation algorithm/config/cache namespace; only stale rows for affected titles are invalidated;
- collection/search/SEO public identities;
- production secrets and private provider URLs;
- all foreign dirty/staged/untracked files and shared `main` history.
