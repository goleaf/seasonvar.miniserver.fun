# Нормальный частичный Git commit — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Разрешить обычный частичный `git commit` в `main`, даже если рядом
остались не относящиеся к фиксации unstaged или untracked файлы, не ослабляя
защиту staged-файлов и обязательную чистоту перед `git push`.

**Architecture:** `pre-commit` проверяет только то, что действительно войдёт в
commit: ветку, конфликты, опасные staged-пути, автоматический русский
`CHANGELOG.md` и документные политики. Полностью чистое дерево остаётся
обязательным в `pre-push`, где выполняется широкий quality gate. Поведение
закрепляется интеграционным PHPUnit-тестом во временном Git repository.

**Tech Stack:** Git 2.52, Bash, PHP 8.5, Laravel 13.22, PHPUnit 12.5.

## Global Constraints

- Работа выполняется только в существующей ветке `main`.
- Существующие staged, unstaged и untracked изменения не сбрасываются, не
  прячутся и не удаляются.
- `pre-commit` продолжает запрещать конфликтный index, опасные staged-пути,
  commit вне `main` и нарушение политик README/CHANGELOG.
- `pre-push` продолжает требовать полностью чистое рабочее дерево.
- Обычный текст `CHANGELOG.md` остаётся русским; проверка не ослабляется.
- Новые зависимости, маршруты, миграции, переводы, cache keys, permissions,
  environment variables и production services не добавляются.
- Rollback возвращает два clean-tree вызова в `pre-commit`, соответствующие
  тестовые assertions и прежний текст документации.

---

### Task 1: Закрепить новый постоянный Git contract

**Files:**

- Modify: `AGENTS.md`
- Restore: `.githooks/post-commit`
- Restore: `.githooks/pre-push`
- Restore: `.github/workflows/ci.yml`
- Modify: `docs/development.md`
- Modify: `docs/ci.md`
- Modify: `docs/superpowers/specs/2026-07-25-automatic-russian-changelog-design.md`
- Modify: `docs/superpowers/specs/2026-07-19-github-actions-reliability-design.md`
- Modify: `docs/superpowers/specs/2026-07-16-canonical-ci-quality-gate-design.md`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**

- Produces: стандартный partial-commit contract для `pre-commit`.
- Preserves: `main`, conflict/sensitive-path guards, staged README/CHANGELOG
  policies, clean-tree `pre-push`.

- [ ] **Step 0: Восстановить удалённый concurrent commit baseline**

Commit `1ec68b8` во время выполнения задачи удалил все versioned Git hooks и
`.github/workflows/ci.yml`, хотя `core.hooksPath=.githooks` и канонические
документы продолжают требовать эти файлы. Восстановить `post-commit`,
`pre-push`, guard library и pinned CI workflow из проверенного parent snapshot.
`pre-commit` восстановить в состоянии до Task 54: updater уже подключён, а два
clean-tree guards ещё присутствуют, чтобы RED доказал именно меняемое
поведение.

- [ ] **Step 1: Обновить канонический owner**

В `docs/development.md` заменить требование полного clean tree до commit на:

```markdown
- `pre-commit` допускает обычный частичный commit при наличии посторонних
  unstaged/untracked файлов и проверяет только branch, conflicts, staged safe
  paths и staged documentation contracts.
- `pre-push` требует полностью clean working tree.
```

- [ ] **Step 2: Согласовать repository instructions и CI owner**

В `AGENTS.md` явно сохранить staged-only безопасность commit и clean-tree
границу push. В `docs/ci.md` описать, что `docs`-профиль остаётся commit gate,
но не получает полномочий добавлять посторонние файлы.

- [ ] **Step 3: Обновить changelog design**

Заменить «исходную чистоту дерева» на «безопасность staged-путей»; updater
по-прежнему отказывает только при отдельном unstaged-изменении самого
`CHANGELOG.md`, потому что иначе targeted staging потерял бы авторский hunk.
Historical CI designs получают датированное уточнение нового contract без
переписывания прежнего evidence.

- [ ] **Step 4: Перечитать подготовленный scope**

Run:

```bash
sed -n '/^## Task 54 — нормальный частичный Git commit/,$p' \
  docs/plans/current-task-plan.md
```

Expected: присутствуют changed/protected files, risks, cross-feature matrix,
compliance matrix, rollback и verification.

---

### Task 2: Добавить RED-регрессию реального hook behavior

**Files:**

- Modify: `tests/Unit/CiQualityGateContractTest.php`

**Interfaces:**

- Consumes: `.githooks/pre-commit` и `.githooks/lib/git-guard.sh`.
- Produces: доказательство, что unrelated unstaged tracked и untracked файлы
  не блокируют staged commit.

- [ ] **Step 1: Создать временный repository**

Тест создаёт `main`, фиксирует исходный tracked-файл, копирует hook и guard,
создаёт безопасные no-op stubs для updater/docs/README/CHANGELOG checks, затем:

```php
$this->writeAndStage($repositoryPath, 'staged.txt', "подготовлено\n");
File::append($repositoryPath.'/tracked.txt', "не в индексе\n");
File::put($repositoryPath.'/untracked.txt', "не отслеживается\n");
```

- [ ] **Step 2: Проверить желаемое поведение**

```php
$process = new Process(['bash', '.githooks/pre-commit'], $repositoryPath);
$process->run();

$this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
```

- [ ] **Step 3: Запустить RED**

Run:

```bash
php artisan test tests/Unit/CiQualityGateContractTest.php \
  --filter=partial_commit
```

Expected: FAIL с `есть unstaged tracked changes` на старом hook.

---

### Task 3: Реализовать минимальный GREEN

**Files:**

- Modify: `.githooks/pre-commit`
- Modify: `.githooks/lib/git-guard.sh`
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Verify: `.githooks/pre-push`
- Verify: `.github/workflows/ci.yml`

**Interfaces:**

- Preserves calls:
  `seasonvar_git_guard_require_main`,
  `seasonvar_git_guard_require_no_conflicts`,
  `seasonvar_git_guard_require_safe_paths staged`.
- Removes commit-only calls:
  `seasonvar_git_guard_require_no_unstaged_changes`,
  `seasonvar_git_guard_require_no_untracked_files`.
- Preserves `seasonvar_git_guard_require_clean_tree` for `.githooks/pre-push`.

- [ ] **Step 1: Удалить два вызова из `pre-commit`**

Оставить последовательность:

```bash
seasonvar_git_guard_require_main
seasonvar_git_guard_require_no_conflicts
seasonvar_git_guard_require_safe_paths staged

"$repo_root/scripts/update-changelog-for-staged-code.sh"
```

- [ ] **Step 2: Удалить dead helper functions**

Удалить из `.githooks/lib/git-guard.sh` только:

```bash
seasonvar_git_guard_require_no_unstaged_changes
seasonvar_git_guard_require_no_untracked_files
```

`seasonvar_git_guard_require_clean_tree` не менять.

- [ ] **Step 3: Обновить order assertions**

В contract tests использовать позицию
`seasonvar_git_guard_require_safe_paths staged` как последнюю commit guard и
явно доказать отсутствие двух удалённых вызовов.

- [ ] **Step 4: Запустить GREEN**

Run:

```bash
php artisan test tests/Unit/CiQualityGateContractTest.php \
  --filter=partial_commit
```

Expected: PASS.

---

### Task 4: Исправить текущий русский CHANGELOG без ослабления policy

**Files:**

- Modify: `CHANGELOG.md`

**Interfaces:**

- Consumes: текущие содержательные записи 25.07.2026.
- Produces: русский обычный текст; точные команды, классы, маршруты, keys и
  форматы остаются в backticks.

- [ ] **Step 1: Получить полный список violations**

Run existing scanner repeatedly after coherent translation batches:

```bash
scripts/check-changelog-policy.sh CHANGELOG.md
```

Expected before fix: FAIL начиная с `mode-specific`.

- [ ] **Step 2: Перевести prose**

Перевести обычные английские слова и сочетания. Не добавлять их в allowlist и
не скрывать обычную прозу в backticks. Точные class/method/route/key identifiers
сохранить как технические обозначения.

- [ ] **Step 3: Запустить policy**

Run:

```bash
scripts/check-changelog-policy.sh CHANGELOG.md
```

Expected: exit `0`.

---

### Task 5: Обновить документацию и завершить verification

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Verify all files above.

**Interfaces:**

- Produces: фактическое описание удобного partial commit для разработчика.
- Preserves: последний H2 README — пользовательская история; visitor-visible
  product behavior не меняется.

- [ ] **Step 1: Обновить README**

В разделе Git добавить одну строку о partial commit. Не добавлять фиктивную
запись в visitor history, потому что поведение сайта не изменилось.

- [ ] **Step 2: Обновить CHANGELOG**

Добавить русскую датированную запись о hook contract, тесте, сохранённой
безопасности и rollback.

- [ ] **Step 3: Проверить syntax и focused tests**

Run:

```bash
bash -n .githooks/pre-commit .githooks/pre-push \
  .githooks/post-commit .githooks/lib/git-guard.sh
php artisan test tests/Unit/CiQualityGateContractTest.php \
  tests/Unit/AutomaticChangelogUpdateScriptTest.php \
  tests/Unit/ChangelogPolicyScriptTest.php
```

- [ ] **Step 4: Проверить документацию**

Run:

```bash
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check
```

Contract test также обязан подтвердить восстановленный pinned workflow и
clean-tree `pre-push`.

- [ ] **Step 5: Воспроизвести успешный hook**

Run:

```bash
bash .githooks/pre-commit
```

Expected: exit `0` несмотря на unrelated unstaged/untracked files.

- [ ] **Step 6: Git delivery**

Проверить `git status --short --branch`, зафиксировать только проверенный
coherent scope в `main` и отправить configured remote. Если общий index не
позволяет безопасно отделить task-owned hunks, не сбрасывать его и отметить
delivery как `unresolved_shared_index`, не выдавая это за успешный commit.
