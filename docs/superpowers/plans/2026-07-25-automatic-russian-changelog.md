# План реализации автоматического русского CHANGELOG

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Автоматически добавлять в `CHANGELOG.md` русскую датированную запись
при каждом staged-изменении кода, если разработчик не подготовил запись
вручную.

**Architecture:** NUL-safe shell wrapper читает staged Git paths, обеспечивает
границы индекса и точечно stage-ит только `CHANGELOG.md`. Чистый PHP-обработчик
классифицирует пути и идемпотентно вставляет фактическую русскую запись.
`pre-commit` вызывает updater до существующих проверок документации и языка.

**Tech Stack:** Bash, PHP 8.5, Git hooks, PHPUnit 12.5.

## Global Constraints

- Работа выполняется только в существующей ветке `main`.
- Нельзя stage/reset/stash/delete чужие изменения общего рабочего дерева.
- Обычный текст `CHANGELOG.md` только русский; точные технические обозначения
  остаются в исходном написании.
- Прежние записи журнала нельзя сокращать, объединять, удалять или
  перестраивать.
- Автоматизация изменяет и добавляет в индекс только `CHANGELOG.md`.
- Абсолютные пути, diff, секреты и содержимое файлов в запись не попадают.
- Изменения только Markdown-документации не создают автоматическую запись.
- Новые зависимости, маршруты, миграции, cache keys, permissions и production
  actions не добавляются.

---

### Task 1: Закрепить постоянное правило и task boundaries

**Files:**

- Modify: `AGENTS.md`
- Modify: `docs/plans/current-task-plan.md`
- Read: `docs/development.md`
- Read: `README.md`

**Interfaces:**

- Produces: каноническое правило обязательного автоматического обновления
  `CHANGELOG.md`.
- Preserves: существующие ограничения русского текста, сохранности истории,
  работы только в `main` и запрета секретов.

- [x] **Step 1: Добавить каноническое правило**

В разделе источников документации `AGENTS.md` добавить:

```markdown
- Каждый commit с изменением кода обязан включать датированное изменение
  `CHANGELOG.md`. Если содержательная staged-запись отсутствует, установленный
  `pre-commit` автоматически добавляет краткую фактическую русскую запись по
  категориям изменённых файлов и stage-ит только `CHANGELOG.md`; ручная запись
  имеет приоритет.
```

- [x] **Step 2: Обновить current-task plan**

Добавить H2-раздел с:

- ожидаемыми файлами;
- защищёнными контрактами;
- рисками Git index mutation;
- cross-feature matrix;
- requirement-compliance matrix;
- rollback и verification gates.

- [x] **Step 3: Перечитать подготовленный scope**

Run:

```bash
sed -n '/^## Активная задача — автоматическое ведение русского CHANGELOG/,$p' \
  docs/plans/current-task-plan.md
```

Expected: полный task scope без `pending` requirement-read gates.

---

### Task 2: Написать RED-контракты автоматического updater

**Files:**

- Create: `tests/Unit/AutomaticChangelogUpdateScriptTest.php`

**Interfaces:**

- Consumes: будущий CLI
  `scripts/update-changelog-for-staged-code.sh`.
- Produces: исполнимый контракт staged Git behavior.

- [x] **Step 1: Создать тестовый класс**

Тест использует отдельный временный Git repository, фиксирует исходный русский
`CHANGELOG.md`, stage-ит тестовые файлы и запускает wrapper через
`Symfony\Component\Process\Process`.

Обязательные тесты:

```php
public function test_it_does_nothing_for_documentation_only_changes(): void;
public function test_it_creates_a_new_dated_section_for_staged_code(): void;
public function test_it_inserts_into_an_existing_dated_section(): void;
public function test_it_classifies_code_paths_and_counts_only_relevant_files(): void;
public function test_it_preserves_manual_staged_changelog_changes(): void;
public function test_it_is_idempotent_after_automatic_staging(): void;
public function test_it_rejects_unstaged_changelog_changes(): void;
public function test_generated_entry_passes_the_russian_policy(): void;
```

Каждый запуск передаёт:

```php
$process->setEnv([
    'PATH' => (string) getenv('PATH'),
    'SEASONVAR_CHANGELOG_DATE' => '2026-07-25',
]);
```

- [x] **Step 2: Запустить RED**

Run:

```bash
php artisan test tests/Unit/AutomaticChangelogUpdateScriptTest.php
```

Expected: FAIL, потому что
`scripts/update-changelog-for-staged-code.sh` отсутствует.

---

### Task 3: Реализовать детерминированный PHP-обработчик

**Files:**

- Create: `scripts/update-changelog-for-staged-code.php`
- Test: `tests/Unit/AutomaticChangelogUpdateScriptTest.php`

**Interfaces:**

- Consumes:
  `php scripts/update-changelog-for-staged-code.php CHANGELOG.md YYYY-MM-DD`
  и NUL-разделённые repository-relative paths из STDIN.
- Produces: exit `0` и неизменённый файл либо одну новую русскую запись; exit
  `1|2` с русской диагностикой при неправильном input.

- [x] **Step 1: Реализовать валидацию**

Проверить:

```php
if ($argc !== 3 || ! is_file($argv[1])) {
    fwrite(STDERR, "Автообновление CHANGELOG: файл не найден.\n");
    exit(2);
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $argv[2]);

if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $argv[2]) {
    fwrite(STDERR, "Автообновление CHANGELOG: дата должна иметь формат YYYY-MM-DD.\n");
    exit(2);
}
```

- [x] **Step 2: Реализовать классификацию**

Стабильный порядок категорий:

```php
$categoryMatchers = [
    'серверная логика' => static fn (string $path): bool =>
        str_starts_with($path, 'app/') || str_starts_with($path, 'bootstrap/'),
    'конфигурация' => static fn (string $path): bool =>
        str_starts_with($path, 'config/') || $path === '.env.example',
    'структура и работа с данными' => static fn (string $path): bool =>
        str_starts_with($path, 'database/'),
    'маршруты' => static fn (string $path): bool =>
        str_starts_with($path, 'routes/'),
    'переводы' => static fn (string $path): bool =>
        str_starts_with($path, 'lang/'),
    'интерфейс и клиентские ресурсы' => static fn (string $path): bool =>
        str_starts_with($path, 'resources/')
        || str_starts_with($path, 'public/')
        || str_starts_with($path, 'vite.config.'),
    'инструменты разработки и проверки' => static fn (string $path): bool =>
        str_starts_with($path, 'scripts/')
        || str_starts_with($path, '.githooks/')
        || str_starts_with($path, 'tests/')
        || str_starts_with($path, 'phpunit.xml')
        || str_starts_with($path, 'phpstan')
        || str_starts_with($path, 'rector'),
    'зависимости и сборка' => static fn (string $path): bool => in_array(
        $path,
        ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'],
        true,
    ),
];
```

Путь учитывается в количестве один раз и получает первую совпавшую категорию.

- [x] **Step 3: Реализовать русскую запись**

Формат:

```php
$entry = sprintf(
    '- Автоматически зафиксировано обновление кода. Области: %s. Количество изменённых файлов: %d.',
    $categorySummary,
    count($relevantPaths),
);
```

Для списка категорий использовать запятую и русское `и` перед последним
элементом.

- [x] **Step 4: Реализовать идемпотентную вставку**

При существующем `## YYYY-MM-DD` вставить запись сразу после заголовка и пустой
строки. Иначе вставить новый раздел после `# Журнал изменений`. Существующее
содержимое копируется без перестройки; заключительный перевод строки
сохраняется.

- [x] **Step 5: Проверить синтаксис**

Run:

```bash
php -l scripts/update-changelog-for-staged-code.php
```

Expected: `No syntax errors detected`.

---

### Task 4: Реализовать безопасную Git-границу

**Files:**

- Create: `scripts/update-changelog-for-staged-code.sh`
- Test: `tests/Unit/AutomaticChangelogUpdateScriptTest.php`

**Interfaces:**

- Consumes: staged Git index текущего repository.
- Produces: no-op либо targeted `git add -- CHANGELOG.md`.

- [x] **Step 1: Реализовать wrapper**

Wrapper обязан:

```bash
repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

git ls-files --error-unmatch CHANGELOG.md >/dev/null 2>&1 \
    || fail "CHANGELOG.md не отслеживается Git."

if ! git diff --cached --quiet -- CHANGELOG.md; then
    exit 0
fi

if ! git diff --quiet -- CHANGELOG.md; then
    fail "CHANGELOG.md содержит изменения вне индекса."
fi
```

Дата:

```bash
changelog_date="${SEASONVAR_CHANGELOG_DATE:-$(TZ=Europe/Vilnius date +%F)}"
```

Staged paths сохраняются во временный файл через:

```bash
git diff --cached --name-only --diff-filter=ACMRD -z -- > "$paths_file"
```

PHP вызывается со стандартным вводом из этого файла. После изменения выполняется
только:

```bash
git add -- CHANGELOG.md
```

- [x] **Step 2: Проверить оболочку**

Run:

```bash
bash -n scripts/update-changelog-for-staged-code.sh
```

Expected: exit `0`.

- [x] **Step 3: Запустить GREEN**

Run:

```bash
php artisan test tests/Unit/AutomaticChangelogUpdateScriptTest.php
```

Expected: все тесты проходят.

---

### Task 5: Подключить updater к pre-commit

**Files:**

- Modify: `.githooks/pre-commit`
- Modify: `tests/Unit/CiQualityGateContractTest.php`

**Interfaces:**

- Consumes: успешные Git guards.
- Produces: updater запускается до docs/README/CHANGELOG validation.

- [x] **Step 1: Добавить RED-контракт порядка**

Тест должен проверить:

```php
$updaterPosition = strpos(
    $hook,
    '"$repo_root/scripts/update-changelog-for-staged-code.sh"',
);
$docsPosition = strpos($hook, 'bash "$repo_root/scripts/ci-check.sh" docs');
$policyPosition = strpos($hook, 'check-changelog-policy.sh');

$this->assertIsInt($updaterPosition);
$this->assertTrue($updaterPosition < $docsPosition);
$this->assertTrue($updaterPosition < $policyPosition);
```

- [x] **Step 2: Запустить RED**

Run:

```bash
php artisan test --filter=changelog
```

Expected: новый hook-order test FAIL.

- [x] **Step 3: Подключить updater**

После clean-tree guards добавить:

```bash
"$repo_root/scripts/update-changelog-for-staged-code.sh"
```

- [x] **Step 4: Запустить GREEN**

Run:

```bash
php artisan test tests/Unit/AutomaticChangelogUpdateScriptTest.php \
  tests/Unit/ChangelogPolicyScriptTest.php \
  tests/Unit/CiQualityGateContractTest.php
```

Expected: все тесты проходят.

---

### Task 6: Обновить workflow-документацию и журнал

**Files:**

- Modify: `docs/development.md`
- Modify: `docs/ci.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**

- Produces: единое русское объяснение автоматической мутации
  `CHANGELOG.md`.
- Preserves: управляемые `project-docs` блоки.

- [x] **Step 1: Обновить development workflow**

Зафиксировать:

- автоматическое обновление выполняется после исходных clean-tree guards;
- ручное staged-изменение имеет приоритет;
- единственная автоматическая мутация/staging — `CHANGELOG.md`;
- документационный diff без кода остаётся no-op;
- ошибка последующей проверки оставляет запись видимой для review.
- `docs/ci.md` заменяет прежний абсолютный запрет мутаций хука точным
  исключением для автоматического `git add -- CHANGELOG.md`.

- [x] **Step 2: Обновить README**

В разделе Git правил заменить прежнее описание на фактическое:

```markdown
- При изменении кода `pre-commit` автоматически добавляет в `CHANGELOG.md`
  краткую датированную запись на русском языке и включает файл в тот же коммит,
  если содержательная запись не подготовлена вручную.
```

Добавить фактическую датированную запись истории для разработчиков только если
это соответствует текущей структуре README и не создаёт фиктивного изменения
для посетителей.

- [x] **Step 3: Добавить содержательную запись CHANGELOG**

В `## 2026-07-25` описать постоянное правило, NUL-safe staged classification,
ручной приоритет, targeted staging, тесты, безопасность, совместимость и
ограничение общего dirty worktree.

- [x] **Step 4: Завершить compliance matrix**

Зафиксировать точные результаты RED/GREEN, syntax, docs, README, invariant,
commit/push и cross-feature statuses.

---

### Task 7: Финальная проверка

**Files:**

- Verify all files above.

**Interfaces:**

- Produces: evidence-backed completion report.

- [x] **Step 1: Форматирование PHP**

Run:

```bash
./vendor/bin/pint \
  scripts/update-changelog-for-staged-code.php \
  tests/Unit/AutomaticChangelogUpdateScriptTest.php \
  tests/Unit/CiQualityGateContractTest.php \
  --format agent
```

В общем dirty worktree применяется точный список task-owned PHP-файлов, чтобы
не форматировать посторонние незавершённые изменения.

- [x] **Step 2: Направленные тесты**

Run:

```bash
php artisan test tests/Unit/AutomaticChangelogUpdateScriptTest.php \
  tests/Unit/ChangelogPolicyScriptTest.php \
  tests/Unit/CiQualityGateContractTest.php
```

- [x] **Step 3: Синтаксис и политики**

Run:

```bash
php -l scripts/update-changelog-for-staged-code.php
bash -n scripts/update-changelog-for-staged-code.sh .githooks/pre-commit
scripts/check-changelog-policy.sh CHANGELOG.md
scripts/check-readme-policy.sh README.md
```

- [x] **Step 4: Документация**

Run:

```bash
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check -- \
  AGENTS.md CHANGELOG.md README.md .githooks/pre-commit \
  scripts/update-changelog-for-staged-code.php \
  scripts/update-changelog-for-staged-code.sh \
  tests/Unit/AutomaticChangelogUpdateScriptTest.php \
  tests/Unit/CiQualityGateContractTest.php \
  docs/ci.md docs/development.md docs/plans/current-task-plan.md \
  docs/superpowers/specs/2026-07-25-automatic-russian-changelog-design.md \
  docs/superpowers/plans/2026-07-25-automatic-russian-changelog.md
```

- [x] **Step 5: Проверка совместимости**

Подтвердить, что routes, migrations, translations, cache keys, permissions,
dependencies, runtime и production services не изменены.

- [x] **Step 6: Git delivery**

Run:

```bash
git status --short --branch
```

Commit/push разрешены только если task-owned scope можно безопасно отделить и
обязательный clean-tree hook может пройти без захвата чужих изменений. Иначе
зафиксировать `unresolved_shared_worktree`.

Итог: `unresolved_shared_worktree`. Ветка `main` подтверждена, но общий
tracked/untracked diff содержит многочисленные несвязанные изменения, поэтому
безопасные selective staging, commit и push невозможны.
