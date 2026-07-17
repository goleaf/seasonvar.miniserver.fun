# Quiet Multiple Import Counters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрепить Laravel 13.20 как минимальную версию и перевести синхронное накопление нескольких счётчиков импорта на атомарный `incrementEachQuietly()` без Eloquent model events.

**Architecture:** `SeasonvarImportPipeline::addRunCounters()` остаётся единственной синхронной boundary для этих приращений: он перечитывает актуальный `summary`, формирует allowlisted deltas и выполняет один instance-model update через Laravel 13.20. Конкурентный `SeasonvarImportRunRecorder` сохраняет существующий conditional query-builder update, чтобы не создавать гонку статуса.

**Tech Stack:** PHP 8.5, Laravel 13.20+, Eloquent, SQLite, PHPUnit 12.5, Composer 2, Laravel Pint 1.29.

## Global Constraints

- Работать и коммитить только в существующей ветке `main`; не создавать branches или worktrees.
- Минимальная версия `laravel/framework` после изменения — `^13.20`; новые production dependencies не добавляются.
- Не изменять `SeasonvarImportRunRecorder`, статусы, имена счётчиков, migrations, routes, queue topology или публичную команду `php artisan seasonvar:import`.
- Сохранить объединение `summary` через `array_merge()` и автоматическое обновление `updated_at`.
- Обычный текст `README.md` и `CHANGELOG.md` остаётся русским; пользовательскую историю не дополнять внутренней технической записью.
- PHP-реализация следует TDD: regression test должен корректно упасть до изменения production code и пройти после него.

---

### Task 1: Regression contract для тихого пакетного обновления

**Files:**
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Consumes: private `SeasonvarImportPipeline::addRunCounters(SeasonvarImportRun $run, array $counters, array $summary = []): void` через принятый в этом test suite `ReflectionMethod`.
- Produces: `test_it_updates_multiple_import_run_counters_quietly()` — focused regression для арифметики, summary, timestamp и отсутствия `updating`/`updated` events.

- [ ] **Step 1: Добавить failing regression test**

Добавить facade import рядом с существующими imports:

```php
use Illuminate\Support\Facades\Event;
```

Добавить в `SeasonvarImportMaintenanceTest` рядом с текущим counter test:

```php
public function test_it_updates_multiple_import_run_counters_quietly(): void
{
    $run = SeasonvarImportRun::query()->create([
        'mode' => 'sitemap',
        'status' => 'running',
        'force' => false,
        'forever' => false,
        'selected' => 4,
        'parsed' => 5,
        'summary' => ['existing' => true],
        'started_at' => now(),
    ]);
    $previousUpdatedAt = $run->updated_at;
    $this->assertNotNull($previousUpdatedAt);
    $this->travel(1)->second();

    Event::fake();

    $method = new \ReflectionMethod(SeasonvarImportPipeline::class, 'addRunCounters');
    $method->invoke(
        app(SeasonvarImportPipeline::class),
        $run,
        ['selected' => 2, 'parsed' => 3],
        ['batch' => ['number' => 2]],
    );

    $freshRun = SeasonvarImportRun::query()->findOrFail($run->id);

    $this->assertSame(6, $freshRun->selected);
    $this->assertSame(8, $freshRun->parsed);
    $this->assertSame([
        'existing' => true,
        'batch' => ['number' => 2],
    ], $freshRun->summary);
    $this->assertNotNull($freshRun->updated_at);
    $this->assertTrue($freshRun->updated_at->greaterThan($previousUpdatedAt));
    Event::assertNotDispatched('eloquent.updating: '.SeasonvarImportRun::class);
    Event::assertNotDispatched('eloquent.updated: '.SeasonvarImportRun::class);
}
```

- [ ] **Step 2: Запустить test и подтвердить RED**

Run:

```bash
php artisan test --filter=test_it_updates_multiple_import_run_counters_quietly
```

Expected: FAIL на `Event::assertNotDispatched()` с сообщением, что `eloquent.updating: App\Models\SeasonvarImportRun` или `eloquent.updated: App\Models\SeasonvarImportRun` был отправлен текущим `fill()->save()`.

---

### Task 2: Laravel 13.20 quiet counter implementation

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `composer.json`
- Modify: `composer.lock`

**Interfaces:**
- Consumes: Laravel 13.20 instance method `Model::incrementEachQuietly(array<string, float|int> $columns, array<string, mixed> $extra = []): int`.
- Produces: прежняя private signature `addRunCounters(SeasonvarImportRun $run, array $counters, array $summary = []): void`; callers и return contract не меняются.

- [ ] **Step 1: Заменить read-modify-save на allowlisted atomic increments**

Заменить тело `SeasonvarImportPipeline::addRunCounters()` после `$run->refresh();` на:

```php
$increments = array_filter([
    'cycles' => (int) ($counters['cycles'] ?? 0),
    'discovered' => (int) ($counters['discovered'] ?? 0),
    'stored' => (int) ($counters['stored'] ?? 0),
    'selected' => (int) ($counters['selected'] ?? 0),
    'parsed' => (int) ($counters['parsed'] ?? 0),
    'failed' => (int) ($counters['failed'] ?? 0),
    'media_attached' => (int) ($counters['media_attached'] ?? 0),
    'media_updated' => (int) ($counters['media_updated'] ?? 0),
    'media_skipped' => (int) ($counters['media_skipped'] ?? 0),
    'media_failed' => (int) ($counters['media_failed'] ?? 0),
], fn (int $amount): bool => $amount !== 0);

$run->incrementEachQuietly($increments, [
    'summary' => array_merge($run->summary ?? [], $summary),
]);
```

Не менять `SeasonvarImportRunRecorder`: его `whereKey()->whereIn()->update()` остаётся единственным атомарным conditional update для queue jobs.

- [ ] **Step 2: Закрепить минимальную версию Laravel**

В `composer.json` изменить:

```json
"laravel/framework": "^13.20"
```

Обновить lock metadata без перехода на новые package versions:

```bash
composer update --lock --no-interaction
```

Expected: `composer.lock` сохраняет `laravel/framework` `v13.20.0`, а его `content-hash` соответствует новому root constraint.

- [ ] **Step 3: Запустить test и подтвердить GREEN**

Run:

```bash
php artisan test --filter=test_it_updates_multiple_import_run_counters_quietly
```

Expected: PASS; два счётчика накоплены, summary объединён, timestamp изменён, model events отсутствуют.

- [ ] **Step 4: Запустить соседний counter contract**

Run:

```bash
php artisan test --filter=test_it_updates_import_run_counters_after_each_processed_page_chunk
```

Expected: PASS с наблюдаемыми промежуточными значениями `selected`/`parsed` `1/1`, затем `2/2`.

---

### Task 3: Документация, quality gates и commit

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: реализованный Laravel 13.20 version floor и quiet counter contract.
- Produces: русская project-state документация и чистый committed `main`.

- [ ] **Step 1: Актуализировать README без фиктивной visitor feature**

В разделе `Состояние проекта` уточнить первую строку:

```markdown
- Основной сайт работает на Laravel 13.20 или новее в ветке 13.x, PHP 8.5 и Livewire 4.3.
```

Не добавлять новый пункт в `История обновлений для посетителей`: внешний contract сайта не изменился.

- [ ] **Step 2: Добавить отдельную запись CHANGELOG**

В начало секции `## 2026-07-17` добавить:

```markdown
- Минимальная версия `laravel/framework` поднята до `^13.20`, а синхронное накопление нескольких счётчиков `SeasonvarImportRun` переведено на атомарный `incrementEachQuietly()` с сохранением объединённого `summary` и `updated_at` без событий `updating`/`updated`. Условное обновление конкурентных queue jobs осталось на query builder, чтобы статус `queued|running` проверялся в том же SQL-запросе без гонки.
```

- [ ] **Step 3: Отформатировать и проверить dependency metadata**

Run:

```bash
./vendor/bin/pint --dirty --format agent
composer validate --strict
git diff --check
```

Expected: Pint завершается без ошибок, Composer сообщает valid `composer.json`/`composer.lock`, whitespace errors отсутствуют.

- [ ] **Step 4: Выполнить focused и project-wide verification**

Run:

```bash
php artisan test --filter='test_it_updates_(multiple_import_run_counters_quietly|import_run_counters_after_each_processed_page_chunk)'
composer analyse
php artisan test
php artisan project:docs-refresh --check
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
```

Expected: все команды завершаются с exit 0; PHPUnit показывает 0 failures/errors, Larastan — 0 errors, documentation checks не изменяют файлы.

- [ ] **Step 5: Просмотреть diff и закоммитить реализацию**

Run:

```bash
git status --short --branch
git diff --check
git diff -- app/Services/Seasonvar/SeasonvarImportPipeline.php tests/Feature/SeasonvarImportMaintenanceTest.php composer.json composer.lock README.md CHANGELOG.md
git add app/Services/Seasonvar/SeasonvarImportPipeline.php tests/Feature/SeasonvarImportMaintenanceTest.php composer.json composer.lock README.md CHANGELOG.md
git diff --cached --check
git commit -m "refactor: update import counters quietly"
git status --short --branch
```

Expected: commit создаётся в `main`; после post-commit documentation refresh рабочее дерево остаётся чистым, ветка опережает `origin/main` только локальными согласованными commits.
