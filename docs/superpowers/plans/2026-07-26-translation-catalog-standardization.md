# Translation Catalog Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Привести все PHP-каталоги `lang/ru` и `lang/en` к единому вертикальному формату, сохранить полный структурный контракт и исправить проверенные RU/EN translation errors.

**Architecture:** `lang/ru` остаётся каноническим владельцем списка файлов, recursive key paths и порядка. Один PHPUnit contract загружает все catalogs, сравнивает структуру/типы/placeholders, анализирует исходный AST на duplicate keys и вертикальный формат и проверяет единый US English. Механическое форматирование отделено от редакционных изменений exact semantic snapshot-проверкой.

**Tech Stack:** PHP 8.5, Laravel 13.22, PHPUnit 12.5, установленный `nikic/php-parser`, Laravel Pint 1.29, Vite 8, Playwright/Chromium.

## Global Constraints

- Работать только в существующей `main`; branch/worktree/PR branch не создавать.
- Foreign Task 63 staged/unstaged/untracked files не reset/stash/unstage/overwrite/commit.
- До стабилизации чужих `lang/ru/recommendations.php` и `lang/en/recommendations.php` не форматировать их и не менять их index entries.
- Не добавлять locale, package, migration, route, API, config, env, cache key, DB row, storage object, queue или production DML.
- Все непустые arrays в `lang/*/*.php` должны быть multiline, с одним item на строку и trailing comma.
- `lang/ru` задаёт exact file set, recursive keys и key order; `lang/en` повторяет их.
- Stable keys, scalar types, named placeholders и language-specific plural meaning сохраняются.
- Обычный русский UI text пишется по-русски; официальные brands/protocols/formats/shortcuts/identifiers сохраняются.
- Обычный English UI text использует US English.
- Phone/tablet/TV verification этой задачи проверяет translations и geometry; она не заявляет поддержку конкретной TV OS или remote API.

---

### Task 1: Общий RED contract каталогов

**Files:**
- Create: `tests/Unit/TranslationCatalogParityTest.php`
- Inspect: `tests/Unit/AdministrationTranslationParityTest.php`
- Inspect: `tests/Unit/BladeTemplateTest.php`

**Interfaces:**
- Consumes: `lang/ru/*.php`, `lang/en/*.php`, `config('catalog-collections.supported_locales')`.
- Produces: один repository-wide contract для file/key/order/type/placeholder/source-format parity.

- [ ] **Step 1: Создать test class с file и semantic parity**

Использовать `Tests\TestCase`, `Illuminate\Support\Facades\File`,
`PhpParser\Node`, `PhpParser\NodeFinder` и `PhpParser\ParserFactory`.
Основной semantic test должен выполнять следующий контракт:

```php
public function test_supported_translation_catalogs_match_russian_structure(): void
{
    $locales = collect((array) config('catalog-collections.supported_locales'))
        ->map(static fn (mixed $locale): string => (string) $locale)
        ->sort()
        ->values()
        ->all();

    self::assertSame(['en', 'ru'], $locales);

    $russianFiles = $this->catalogFiles('ru');

    foreach ($locales as $locale) {
        self::assertSame($russianFiles, $this->catalogFiles($locale), $locale);

        foreach ($russianFiles as $file) {
            $russian = $this->flatten($this->loadCatalog('ru', $file));
            $localized = $this->flatten($this->loadCatalog($locale, $file));

            self::assertSame(array_keys($russian), array_keys($localized), "{$locale}/{$file}");

            foreach ($russian as $key => $value) {
                self::assertSame(get_debug_type($value), get_debug_type($localized[$key]), "{$locale}/{$file}:{$key}");

                if (is_string($value)) {
                    self::assertNotSame('', trim($localized[$key]), "{$locale}/{$file}:{$key}");
                    self::assertSame(
                        $this->placeholders($value),
                        $this->placeholders($localized[$key]),
                        "{$locale}/{$file}:{$key}",
                    );
                    $this->assertPluralBranchesAreNonEmpty($localized[$key], "{$locale}/{$file}:{$key}");
                }
            }
        }
    }
}
```

`catalogFiles()` возвращает sorted basename list. `loadCatalog()` требует
array return. `flatten()` сохраняет insertion order и dot paths.
`placeholders()` извлекает unique sorted
`/:([A-Za-z_][A-Za-z0-9_]*)/`.

- [ ] **Step 2: Добавить AST duplicate и vertical-source contract**

Для каждого файла parser должен найти все `Node\Expr\Array_`. Recursive
duplicate scan принимает только literal `String_`/`Int_` keys и fail-ится,
если один key повторён на одном array level.

Для каждого non-empty array:

```php
self::assertLessThan($array->getEndLine(), $array->getStartLine(), $label);

$itemLines = array_map(
    static fn (Node\ArrayItem $item): int => $item->getStartLine(),
    array_values(array_filter($array->items)),
);

self::assertCount(count(array_unique($itemLines)), $itemLines, $label);
self::assertGreaterThan($array->getStartLine(), min($itemLines), $label);
self::assertLessThan($array->getEndLine(), max($itemLines), $label);
```

Это доказывает перенос открывающей/закрывающей скобки и один item на строку.

- [ ] **Step 3: Добавить US English contract**

Flatten все `lang/en` values и fail на обычных UK variants:

```php
private const FORBIDDEN_UK_ENGLISH = [
    'acknowledgement',
    'behaviour',
    'cancelled',
    'catalogue',
    'centre',
    'labelled',
    'programme',
];
```

Проверка использует Unicode-safe word boundary и не проверяет keys.

- [ ] **Step 4: Запустить RED**

Run:

```bash
php artisan test tests/Unit/TranslationCatalogParityTest.php
```

Expected: semantic/file/placeholder tests проходят, source-format test
сообщает current horizontal arrays, `en/tags.php` сообщает order drift, US
English test сообщает перечисленные UK variants.

- [ ] **Step 5: Записать RED evidence в Task 64**

Зафиксировать exact tests/assertions/failure categories без вставки полного
runner log.

---

### Task 2: Безопасная механическая вертикализация

**Files:**
- Modify: `lang/ru/*.php`
- Modify: `lang/en/*.php`
- Temporary only: `/tmp/seasonvar-translation-semantic-before.json`
- Temporary only: `/tmp/seasonvar-vertical-translation-formatter.php`

**Interfaces:**
- Consumes: current PHP translation arrays.
- Produces: source-only formatting with exact semantic equality.

- [ ] **Step 1: Определить exact safe file set**

Run `git status --short -- lang`. Если foreign recommendation files всё ещё
изменены, исключить только:

```text
lang/ru/recommendations.php
lang/en/recommendations.php
```

Остальные locale files форматируются только если их worktree content не
принадлежит чужому незавершённому scope.

- [ ] **Step 2: Сохранить semantic snapshot**

Snapshot содержит для каждого файла/locale ordered recursive entries:

```php
[
    'path' => 'admin.readiness_count',
    'type' => 'string',
    'value' => 'Доступно гостю: :visible из :total · минимум: :required',
]
```

JSON пишется только в `/tmp`, UTF-8 остаётся неэкранированным.

- [ ] **Step 3: Создать temporary AST formatter**

Temporary pretty-printer наследует `PhpParser\PrettyPrinter\Standard` и
переопределяет только array printing:

```php
final class VerticalTranslationPrettyPrinter extends Standard
{
    protected function pExpr_Array(Array_ $node): string
    {
        if ($node->items === []) {
            return '[]';
        }

        return '['
            .$this->pCommaSeparatedMultiline($node->items, true)
            .$this->nl
            .']';
    }
}
```

Parser читает только allowlisted safe files; formatter пишет каждый файл
атомарно через sibling temporary file + rename. Это bulk mechanical
formatting, не application runtime.

- [ ] **Step 4: Выполнить formatter**

Expected: каждый выбранный file остаётся syntactically valid, strings/keys
не переупорядочиваются printer-ом.

- [ ] **Step 5: Доказать exact semantic equality**

Повторно построить ordered snapshot и сравнить с before snapshot. Любое
изменение path/type/value останавливает работу; не принимать почти равный
diff.

- [ ] **Step 6: Выполнить PHP syntax и formatter test**

Run:

```bash
find lang -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php artisan test tests/Unit/TranslationCatalogParityTest.php
```

Expected: syntax проходит; test может оставаться RED только из-за
неотформатированных foreign recommendation files, tags order и UK wording.

---

### Task 3: Order repair и проверенные editorial corrections

**Files:**
- Modify: `lang/en/tags.php`
- Modify: `lang/en/administration.php`
- Modify: `lang/en/calendar.php`
- Modify: `lang/en/help.php`
- Modify: `lang/en/library.php`
- Modify: `lang/en/premium.php`
- Modify: `lang/en/requests.php`
- Modify: `lang/en/settings.php`
- Modify: остальные `lang/en/*.php`, содержащие forbidden UK variants
- Modify: `lang/ru/administration.php`
- Modify: `lang/ru/help.php`
- Modify: `lang/ru/premium.php`
- Modify: `lang/ru/tags.php`

**Interfaces:**
- Consumes: stable keys and placeholders.
- Produces: ordered US English and Russian ordinary prose without avoidable English fragments.

- [ ] **Step 1: Исправить exact nested order `en/tags.php`**

Порядок должен совпасть с `ru/tags.php`:

```text
admin.alias_moderated
admin.deleted_title
admin.synonym_saved
```

Значения не менять в этом step.

- [ ] **Step 2: Стандартизировать US English**

В обычных values выполнить редакционные замены:

```text
acknowledgement → acknowledgment
behaviour       → behavior
cancelled       → canceled
catalogue       → catalog
centre          → center
labelled        → labeled
programme       → program
```

Сохранять capitalization, placeholders и punctuation. Keys не
переименовывать.

- [ ] **Step 3: Исправить exact Russian administration prose**

Исправить значения следующих keys, сохранив смысл и placeholders:

```text
dashboard.section_descriptions.operations
errors.revoked_membership_immutable
users.filters.search_placeholder
users.restriction_impact
users.merge_unavailable
audit.description
catalog.title_panel_description
catalog.media_description
operations.description
operations.health_scope
operations.capabilities.log_browser
operations.capabilities.feature_flags
operations.capability_descriptions.cache_versions
operations.capability_descriptions.scheduler
operations.capability_descriptions.queues
operations.capability_descriptions.payment_provider
operations.capability_descriptions.external_search
operations.capability_descriptions.log_browser
operations.capability_descriptions.feature_flags
operations.capability_descriptions.browser_settings_editor
operations.cache_description
operations.reindex_impact
```

Использовать русские варианты: «необработанная диагностика», «процесс»,
«браузерные сеансы», «мобильные токены», «закрытые заметки»,
«необработанные данные», «значения поставщика», «разрешённый HTTPS-хост»,
«секреты», «необработанные журналы», «высокая доступность»,
«аварийное переключение», «работа без простоя», «функциональные
переключатели», «реестр», «платёжный шлюз», «планировщик»,
«асинхронный драйвер», «полный сброс кеша» и «переиндексация».

- [ ] **Step 4: Исправить остальные подтверждённые Russian prose keys**

```text
help.admin.editorial_details:
    «SEO, выделенный блок и порядок»
premium.admin.providers_empty:
    «Не настроен ни один адаптер платёжного провайдера.»
tags.validation.imported_code:
    «Импортированному тегу нужно стабильное сопоставление с провайдером…»
tags.validation.imported_creation:
    «Импортированные теги создаются только через проверенное сопоставление с провайдером.»
```

`queued|running|merged|archived`, `route:/help:`, `User-Agent`, `HTTP`,
`HTTPS`, `PNG`, `JPEG`, `WebP`, brands и keyboard shortcuts остаются exact
technical codes/names.

- [ ] **Step 5: Повторно проверить placeholders и order**

Run:

```bash
php artisan test tests/Unit/TranslationCatalogParityTest.php
php artisan test tests/Unit/AdministrationTranslationParityTest.php
php artisan test --filter=CatalogPlayerCopyTest
php artisan test --filter=BladeTemplateTest
```

Expected: все проходят, кроме возможного source-format failure на двух
foreign recommendation files.

---

### Task 4: Безопасная интеграция concurrent recommendation catalogs

**Files:**
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`

**Interfaces:**
- Consumes: завершённые Task 63 values/keys after their owner commits.
- Produces: same Task 63 semantics in Task 64 vertical source standard.

- [ ] **Step 1: Дождаться стабильного ownership boundary**

Условие начала: Task 63 recommendation translation changes либо находятся в
новом `HEAD`, либо их владелец явно завершил запись и exact worktree blobs
зафиксированы. Не stage/commit чужие незавершённые values.

- [ ] **Step 2: Обновить Task 64 baseline**

Пересчитать file/key/type/placeholder parity после concurrent additions.
Новый count заменяет исторические 4 962 в evidence; это не key drift, если
оба locale получили одинаковые approved Task 63 keys.

- [ ] **Step 3: Сделать exact before snapshot пары recommendation files**

Snapshot строится после ownership gate.

- [ ] **Step 4: Применить тот же vertical formatter**

Форматировать только два recommendation files.

- [ ] **Step 5: Доказать exact semantic equality**

Before/after arrays должны быть идентичны. Task 64 не редактирует новые
recommendation meanings без отдельной обнаруженной linguistic error.

- [ ] **Step 6: Запустить полный global contract**

Run:

```bash
php artisan test tests/Unit/TranslationCatalogParityTest.php
```

Expected: GREEN, zero horizontal non-empty arrays in both locale trees.

---

### Task 5: Laravel/style/static verification

**Files:**
- Verify: all Task 64 changed PHP files and tests.

**Interfaces:**
- Consumes: final Task 64 tree.
- Produces: syntax/style/static evidence.

- [ ] **Step 1: Выполнить exact PHP syntax**

```bash
find lang -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -l tests/Unit/TranslationCatalogParityTest.php
```

- [ ] **Step 2: Выполнить Pint**

```bash
./vendor/bin/pint --dirty --format agent
./vendor/bin/pint --test --format agent lang tests/Unit/TranslationCatalogParityTest.php
```

После Pint global contract обязан остаться GREEN; Pint не должен снова
сжимать arrays.

- [ ] **Step 3: Выполнить focused translation matrix**

```bash
php artisan test tests/Unit/TranslationCatalogParityTest.php
php artisan test tests/Unit/AdministrationTranslationParityTest.php
php artisan test --filter=CatalogPlayerCopyTest
php artisan test --filter=BladeTemplateTest
```

- [ ] **Step 4: Выполнить relevant wider tests**

Запустить tests, использующие locale route, fallback, public page cache
translation fingerprint, notifications/mail и administration. Exact names
берутся из repository search и записываются в evidence до запуска.

- [ ] **Step 5: Выполнить scoped PHPStan**

```bash
./vendor/bin/phpstan analyse tests/Unit/TranslationCatalogParityTest.php --no-progress
```

Expected: `0 errors`.

- [ ] **Step 6: Проверить structural metrics**

Read-only report должен показать:

```text
locale files: ru=21, en=21 (или одинаковый concurrent-approved count)
recursive keys: exact parity
horizontal non-empty arrays: ru=0, en=0
duplicate keys: 0
placeholder mismatches: 0
type mismatches: 0
recursive order mismatches: 0
forbidden UK variants: 0
```

---

### Task 6: Phone/tablet/desktop/TV-like translation verification

**Files:**
- Modify when necessary: existing relevant `tests/browser/*.spec.js`
- Do not modify layout/CSS in Task 64 unless a translation change exposes a direct regression and scope is documented first.

**Interfaces:**
- Consumes: final RU/EN catalog content.
- Produces: representative geometry/translation evidence, not a TV platform claim.

- [ ] **Step 1: Build frontend**

```bash
npm run build
```

- [ ] **Step 2: Prepare isolated browser fixtures**

Использовать существующий Playwright database/fixture workflow. Production
DB/cache/session не изменять.

- [ ] **Step 3: Проверить viewport matrix**

Representative pages покрывают public shell, catalog/discover, title,
authentication/private fallback и administration:

```text
320×720
390×844
768×1024
1024×768
1440×1200
1920×1080
```

На каждой применимой RU/EN странице проверить:

```js
expect(document.documentElement.scrollWidth)
    .toBeLessThanOrEqual(document.documentElement.clientWidth + 1);
```

Дополнительно: raw keys отсутствуют; primary controls имеют accessible name;
long labels не clipped; console/page/first-party response errors отсутствуют.

- [ ] **Step 4: Проверить keyboard/TV-like focus**

На `1920×1080` пройти header/search/main actions клавишами
`Tab`, `Shift+Tab`, `Enter`, `Escape`; visible focus не должен теряться.
Arrow-key support проверяется только там, где компонент уже заявляет
соответствующий widget contract.

- [ ] **Step 5: Записать ограничения**

Отсутствие реального TV OS, remote-control browser и physical screen
пометить `not_performed`; Chromium viewport/keyboard evidence не называть
полной TV compatibility.

---

### Task 7: Документация, финальный compliance и delivery

**Files:**
- Modify: `docs/development.md`
- Modify: `docs/frontend.md`
- Modify: `docs/testing.md` when the new global contract changes documented checks
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/superpowers/plans/2026-07-26-translation-catalog-standardization.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: verified final behavior/evidence.
- Produces: canonical maintenance guidance, visitor history and exact Git delivery.

- [ ] **Step 1: Обновить development owner**

В «Добавление interface translations» записать canonical RU structure,
vertical-only arrays, US English и global test command. Не копировать весь
multilingual requirement.

- [ ] **Step 2: Обновить frontend/testing owners**

Зафиксировать long-label device matrix и global translation contract только
по фактически выполненной проверке.

- [ ] **Step 3: Проверить README**

Поскольку visitor-visible wording исправляется, добавить короткий русский
пункт в последнюю секцию «История обновлений для посетителей». Managed block
не редактировать вручную.

- [ ] **Step 4: Обновить русский CHANGELOG**

Отдельная датированная запись перечисляет vertical catalogs, structural
contract, US English, Russian wording fixes и фактические verification
results без обычного английского prose.

- [ ] **Step 5: Финально перечитать requirements и выполнить legacy scan**

Проверить все `lang/*`, translation calls/tests/config/fingerprint/docs на
второй loader, stale format, duplicate tests, raw keys, unresolved order и
unfinished markers.

- [ ] **Step 6: Выполнить managed docs и diff checks**

```bash
php artisan project:docs-refresh --check --no-interaction
git diff --check
```

- [ ] **Step 7: Заполнить Task 64 compliance matrix**

Каждая строка получает `completed`, `already_compliant`,
`not_applicable` или честный `unresolved` с evidence.

- [ ] **Step 8: Создать exact commits в `main`**

Использовать alternate index, если shared tree остаётся mixed. Commits не
включают Task 63 code/data/migrations/docs за пределами exact translation
integration boundary.

- [ ] **Step 9: Выполнить configured push**

```bash
git push origin main
```

HTTPS/SSH/network rejection записать как `unresolved`; force push,
credential mutation и новая ветка запрещены.

## Verification checklist

- [ ] Exact locale file parity.
- [ ] Recursive key/order/type parity.
- [ ] Placeholder parity.
- [ ] Duplicate-key scan.
- [ ] Non-empty plural branches.
- [ ] Zero horizontal non-empty arrays.
- [ ] Zero forbidden approved UK variants.
- [ ] Reviewed Russian English-prose candidates.
- [ ] PHP syntax.
- [ ] Pint.
- [ ] Focused and wider translation tests.
- [ ] Scoped PHPStan.
- [ ] Vite build.
- [ ] RU/EN phone/tablet/desktop/TV-like Chromium evidence.
- [ ] README/CHANGELOG/canonical docs.
- [ ] Final requirement reread and repository scan.
- [ ] Exact `main` commit and configured push result.

## Rollback

Revert Task 64 implementation/docs commits. No schema, row, storage, queue,
worker, cache-store or production-content rollback is required. Existing
translation fingerprint naturally returns to the previous content on code
rollback; broad cache flush is not part of rollback.
