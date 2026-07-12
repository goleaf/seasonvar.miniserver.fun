# Seasonvar Title-Group Queue Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Исключить конкурентную запись разных страниц сезонов одного тайтла, сохранив параллельный импорт разных сериалов.

**Architecture:** `SeasonvarImportGroupKey` нормализует Seasonvar serial URL до canonical title slug и возвращает общий Redis lock key. Dispatcher и job API не меняются; DB claims продолжают гарантировать уникальность конкретной source page.

**Tech Stack:** PHP 8.5, Laravel 13.19, Redis locks, PHPUnit 12.5.

## Global Constraints

- Публичной командой остаётся только `php artisan seasonvar:import`.
- Не добавлять зависимости и не менять схему базы данных.
- Не скачивать видео-файлы.
- Не менять чужие незакоммиченные файлы.

---

### Task 1: Canonical title-group key

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarImportGroupKey.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: `SeasonvarImportGroupKey::forUrl(string $url, string $urlHash): string`.
- Produces: тот же публичный метод с canonical slug lock key и hash fallback.

- [x] **Step 1: Write the failing test**

Добавить assertions, что URL сезонов одного сериала возвращают одинаковый ключ, а другой сериал — другой:

```php
$this->assertSame(
    $keys->forUrl('https://seasonvar.ru/serial-14979-Sinij_ekzortcist_psftqae-2-season.html', 'hash-a'),
    $keys->forUrl('https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html', 'hash-b'),
);
$this->assertSame(
    $keys->forUrl('https://seasonvar.ru/serial-3177--Sinij_ekzortcist_psbdtjm.html', 'hash-first'),
    $keys->forUrl('https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html', 'hash-b'),
);
$this->assertNotSame(
    $keys->forUrl('https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html', 'hash-b'),
    $keys->forUrl('https://seasonvar.ru/serial-10532-Po_dolgu_sluzhby_pssxanb-2-season.html', 'hash-c'),
);
$this->assertSame(
    'seasonvar-page:hash-d',
    $keys->forUrl('https://seasonvar.ru/catalog/test.html', 'hash-d'),
);
$this->assertSame(
    $keys->forUrl('https://seasonvar.ru/serial-608--25_cheloveka-006-sezon.html', 'legacy-a'),
    $keys->forUrl('https://seasonvar.ru/serial-726--25_cheloveka-007-sezon.html', 'legacy-b'),
);
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter=test_import_group_key
```

Expected: FAIL, потому что текущие ключи содержат разные numeric page IDs.

- [x] **Step 3: Write minimal implementation**

В `forUrl()` последовательно проверить формат с `_ps...` и legacy season URL без него, удалить ведущие `-`/`_`, нормализовать lowercase и вернуть SHA-256 key:

```php
foreach ([
    '~^/serial-\d+-(.+?)_ps[a-z0-9]+(?:-0*\d{1,4}-+(?:season|sezon))?\.html$~iu',
    '~^/serial-\d+-(.+?)[-_]0*\d{1,4}-+(?:season|sezon)\.html$~iu',
] as $pattern) {
    if (preg_match($pattern, $path, $matches) !== 1) {
        continue;
    }

    $slug = mb_strtolower(trim($matches[1], '-_'));
}
```

Для неизвестного формата оставить `seasonvar-page:{$urlHash}`.

- [x] **Step 4: Run focused tests and formatter**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php
./vendor/bin/pint --dirty --format agent
```

Expected: все tests PASS; Pint сообщает `passed` или форматирует только изменённые PHP-файлы.

- [x] **Step 5: Run regression suite**

Run:

```bash
php artisan test
php artisan project:docs-refresh --check
```

Expected: весь PHPUnit suite PASS; документация актуальна.

- [x] **Step 6: Commit on main after Task 2**

После освобождения рабочего дерева соседней сессией:

```bash
git add .env.example config/database.php app/Jobs/ImportSeasonvarSourcePage.php app/Services/Seasonvar/SeasonvarImportGroupKey.php tests/Feature/SeasonvarParallelImportTest.php docs/architecture.md docs/performance.md docs/superpowers/specs/2026-07-12-seasonvar-title-group-lock-design.md docs/superpowers/plans/2026-07-12-seasonvar-title-group-lock.md
git commit -m "fix: serialize Seasonvar title imports"
git push origin main
```

### Task 2: Runtime key для существующего backlog

**Files:**
- Modify: `app/Jobs/ImportSeasonvarSourcePage.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: `SeasonvarImportGroupKey::forUrl(string $url, string $urlHash): string`.
- Produces: `ImportSeasonvarSourcePage::handle()` получает `SeasonvarImportGroupKey` через method injection и использует вычисленный key для Redis lock.

- [x] **Step 1: Write the failing test**

Создать page с Seasonvar URL, claim и job со старым numeric `groupKey`. Захватить canonical lock через `SeasonvarImportGroupKey`, вызвать job с fake queue interactions и проверить release на 30 секунд без вызова importer.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter=test_worker_recomputes_group_key
```

Expected: FAIL, потому что worker использует сериализованный numeric key и вызывает importer.

- [x] **Step 3: Implement runtime recomputation**

Загрузить `SourcePage` после проверки/продления claim, обработать отсутствующую page с release claim, вычислить canonical key и только затем получить Redis lock. Существующие counters, retry/release и importer transaction flow сохранить.

- [x] **Step 4: Verify focused and full suites**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php
./vendor/bin/pint --dirty --format agent app/Jobs/ImportSeasonvarSourcePage.php app/Services/Seasonvar/SeasonvarImportGroupKey.php tests/Feature/SeasonvarParallelImportTest.php
php artisan test
```

Expected: focused и full suite PASS.

### Task 3: SQLite writer transaction mode

**Files:**
- Modify: `config/database.php`
- Modify: `.env.example`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: Laravel 13 SQLite `transaction_mode` connection option.
- Produces: `DB_TRANSACTION_MODE`, default `IMMEDIATE`.

- [x] **Step 1: Add and verify the failing config test**

Assert that `config('database.connections.sqlite.transaction_mode')` is `IMMEDIATE`, then run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter=test_parallel_import_schema
```

Expected: FAIL with actual value `DEFERRED`.

- [x] **Step 2: Configure IMMEDIATE transactions**

Set the SQLite connection option to:

```php
'transaction_mode' => env('DB_TRANSACTION_MODE', 'IMMEDIATE'),
```

Document `DB_TRANSACTION_MODE=IMMEDIATE` in `.env.example`.

- [x] **Step 3: Verify importer and regression suites**

Run focused importer tests, Pint for the changed PHP files, then the full suite. Expected: focused tests PASS; any remaining full-suite failures must be proven unrelated before commit.
