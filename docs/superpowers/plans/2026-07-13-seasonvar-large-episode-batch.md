# Seasonvar Large Episode Batch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Импортировать Seasonvar-страницы с тысячами серий без превышения SQLite bind-variable limit.

**Architecture:** `SeasonvarCatalogImporter` продолжает использовать Eloquent bulk operations, но получает существующие regular-серии без большого `whereIn(number)` и выполняет `Episode::upsert()` пакетами по 50 строк. Регрессия проверяется реальным feature-импортом страницы с 2600 сериями.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, SQLite, PHPUnit 12.5.

## Global Constraints

- Работать только в существующей ветке `main`.
- Не добавлять dependencies и не менять схему базы.
- Сохранять `php artisan seasonvar:import` единственной публичной командой импорта.
- Не скачивать видео-файлы.

---

### Task 1: Regression test and bounded episode writes

**Files:**
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `docs/performance.md`

**Interfaces:**
- Consumes: `SeasonvarCatalogImporter::syncEpisodes(array $seasons, SourcePage $page, array $episodes, ?callable $progress = null): void`.
- Produces: тот же private interface с bounded read/write queries.

- [x] **Step 1: Write the failing feature test**

Добавить тест `test_it_imports_more_episodes_than_a_single_sqlite_upsert_can_bind`, который создаёт массив из 2600 названий серий, подменяет Seasonvar HTTP через `Http::fake()`, запускает `seasonvar:import` для одного URL и проверяет exit code `0`, количество `2600` и последнюю серию.

- [x] **Step 2: Verify RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarParsePageCommandTest.php --filter=test_it_imports_more_episodes_than_a_single_sqlite_upsert_can_bind
```

Expected: FAIL с `too many SQL variables` или ненулевым кодом команды.

- [x] **Step 3: Implement bounded reads and writes**

В `syncEpisodes()` удалить большой `whereIn('number', ...)` из запроса существующих серий. Значения `$rowsForUpsert` разбить через `chunk(50)` и вызвать существующий `Episode::query()->upsert(...)` для каждого пакета с прежними unique/update columns.

- [x] **Step 4: Verify GREEN**

Run:

```bash
php artisan test tests/Feature/SeasonvarParsePageCommandTest.php --filter=test_it_imports_more_episodes_than_a_single_sqlite_upsert_can_bind
php artisan test tests/Feature/SeasonvarParsePageCommandTest.php
```

Expected: PASS.

- [x] **Step 5: Format and verify broadly**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test
php artisan project:docs-refresh --check
git diff --check
```

Expected: все команды завершаются успешно.

- [ ] **Step 6: Commit, push and reload workers**

Run:

```bash
git add app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarParsePageCommandTest.php docs/performance.md docs/superpowers/specs/2026-07-13-seasonvar-large-episode-batch-design.md docs/superpowers/plans/2026-07-13-seasonvar-large-episode-batch.md
git commit -m "fix: chunk large Seasonvar episode imports"
git push origin main
php artisan queue:restart
```

Expected: `main` синхронизирован, десять workers снова active, целевая failed-страница успешно повторяется.
