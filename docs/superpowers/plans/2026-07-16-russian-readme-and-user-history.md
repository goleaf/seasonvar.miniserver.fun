# План русскоязычного README и пользовательской истории

> **Для агентных исполнителей:** ОБЯЗАТЕЛЬНЫЙ ДОПОЛНИТЕЛЬНЫЙ НАВЫК: используйте `superpowers:subagent-driven-development` или `superpowers:executing-plans` для пошагового выполнения. Шаги отслеживаются флажками (`- [ ]`).

**Цель:** Полностью обновить обычный текст `README.md` на русском языке, добавить проверяемую дорожную карту и пользовательскую историю в конце файла, а также закрепить их обновление правилом агента и Git-hook.

**Архитектура:** `README.md` остаётся обзором и быстрым стартом, а тематические контракты остаются в документах-владельцах из `docs/README.md`. Правило в `AGENTS.md` охватывает каждый запрос пользователя, а независимый Bash-скрипт проверяет индексную версию README из `pre-commit` и требует её обновления для изменений продукта.

**Технологии:** Markdown, Bash, Git hooks, PHPUnit 12, Symfony Process, Laravel 13.

## Общие ограничения

- Обычный текст `README.md` пишется по-русски; точные технические идентификаторы сохраняются в исходном написании.
- Управляемые блоки между `project-docs:start` и `project-docs:end` вручную не редактируются.
- Технические обращения и двуязычная главная отмечаются как функции в разработке до завершения их текущих незакоммиченных изменений и проверок.
- История обновлений для посетителей является последним разделом второго уровня в `README.md`.
- Изменения выполняются только в существующей ветке `main`, без новых веток и worktree.

---

### Задача 1: Исполняемая политика README

**Файлы:**

- Создать: `scripts/check-readme-policy.sh`
- Создать: `tests/Unit/ReadmePolicyScriptTest.php`
- Изменить: `.githooks/pre-commit`

**Интерфейсы:**

- Принимает: путь к Markdown-файлу или параметр `--staged`.
- Возвращает: код `0` для корректного README; код `1` и русское сообщение для нарушения.
- Hook вызывает: `scripts/check-readme-policy.sh --staged`.

- [x] **Шаг 1: Написать падающие PHPUnit-тесты**

Добавить тест корректного русского файла и набор ошибочных вариантов:

```php
#[DataProvider('invalidReadmes')]
public function test_it_rejects_invalid_readme(string $contents, string $message): void
{
    File::put($this->readmePath, $contents);

    $process = new Process(['bash', base_path('scripts/check-readme-policy.sh'), $this->readmePath]);
    $process->run();

    $this->assertFalse($process->isSuccessful());
    $this->assertStringContainsString($message, $process->getErrorOutput());
}
```

В поставщик данных включить отсутствие дорожной карты, новый раздел после истории и строку `English only sentence.`.

- [x] **Шаг 2: Убедиться, что тесты падают без скрипта**

Выполнить:

```bash
php artisan test --filter=ReadmePolicyScriptTest
```

Ожидаемый результат: тест корректного файла и отрицательные сценарии не могут запустить отсутствующий скрипт.

- [x] **Шаг 3: Реализовать минимальный Bash-скрипт**

Реализация читает индексную версию через `git show :README.md`, проверяет staged-пути продукта, точные обязательные заголовки и последний заголовок второго уровня. Затем она пропускает ограждённые блоки кода, пустые строки, Markdown-разделители и HTML-комментарии, а для остальных строк требует символ из класса Unicode `Cyrillic`.

Product-path allowlist: `app/*`, `bootstrap/*`, `config/*`, `database/*`, `deploy/*`, `lang/*`, `public/*`, `resources/*`, `routes/*`, `composer.json`, `composer.lock`, `package.json`, lock-файл npm и конфигурация Vite.

- [x] **Шаг 4: Подключить скрипт к pre-commit**

После существующих Git guard вызовов добавить:

```bash
"$repo_root/scripts/check-readme-policy.sh" --staged
```

- [x] **Шаг 5: Запустить focused-проверки**

```bash
bash -n scripts/check-readme-policy.sh .githooks/pre-commit
php artisan test --filter=ReadmePolicyScriptTest
```

Ожидаемый результат: синтаксис корректен, все сценарии теста проходят.

### Задача 2: Русскоязычный README и постоянное правило

**Файлы:**

- Изменить: `README.md`
- Изменить: `AGENTS.md`
- Изменить: `docs/README.md`
- Изменить: `docs/development.md`

**Интерфейсы:**

- `README.md` предоставляет обзор посетителю, быстрый старт разработчику, дорожную карту и пользовательскую историю.
- `AGENTS.md` задаёт обязательное поведение после каждого запроса.
- `docs/development.md` документирует проверку перед коммитом.

- [x] **Шаг 1: Переписать README по согласованной структуре**

Сохранить фактические команды и ссылки, удалить повторы и англоязычные предложения, разделить готовые и находящиеся в разработке функции. Последними заголовками второго уровня сделать:

```markdown
## Дорожная карта

### В разработке

### Запланировано

## История обновлений для посетителей

### 16 июля 2026 года
```

- [x] **Шаг 2: Добавить правило в AGENTS.md**

Зафиксировать проверку после каждого запроса, обновление только при реальном изменении состояния продукта, русский обычный текст и запрет ручного редактирования управляемого блока.

- [x] **Шаг 3: Обновить документацию Git workflow**

В `docs/development.md` описать языковую проверку, обязательные разделы и требование staged README для продуктовых изменений.

- [x] **Шаг 4: Обновить управляемые блоки только командой**

```bash
php artisan project:docs-refresh
```

Проверить diff и не принимать изменения вне управляемых блоков автоматически.

### Задача 3: Полная проверка и публикация

**Файлы:**

- Проверить все файлы задач 1–2, спецификацию и этот план.

- [x] **Шаг 1: Форматирование и focused-тест**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=ReadmePolicyScriptTest
```

- [x] **Шаг 2: Проверки документации и shell**

```bash
bash -n .githooks/pre-commit .githooks/pre-push .githooks/post-commit .githooks/lib/git-guard.sh scripts/check-readme-policy.sh scripts/docs-autocommit-push.sh
scripts/check-readme-policy.sh README.md
php artisan project:docs-refresh --check
git diff --check
```

- [x] **Шаг 3: Изолировать только изменения задачи**

Существующее состояние было сохранено в именованном stash. После появления новых параллельных изменений файлы задачи изолируются точным списком staged-путей без временного удаления активных чужих файлов; перед commit ещё раз проверяются `git status --short --branch` и `git diff --cached --name-only`.

- [x] **Шаг 4: Создать атомарный коммит в main**

```bash
git add README.md AGENTS.md .githooks/pre-commit scripts/check-readme-policy.sh tests/Unit/ReadmePolicyScriptTest.php docs/README.md docs/development.md docs/superpowers/specs/2026-07-16-russian-readme-and-user-history-design.md docs/superpowers/plans/2026-07-16-russian-readme-and-user-history.md
git commit -m "docs: add Russian user-facing project roadmap"
```

- [x] **Шаг 5: Вернуть постороннее рабочее состояние и отправить коммит**

После безопасного восстановления чужих изменений проверить, что они сохранились, затем отправить новый `main` в `origin` и сравнить локальный и удалённый SHA.

Итог выполнения: девять файлов реализации вошли в коммит `5a84293` и были подтверждены в `origin/main`; параллельные изменения не вошли в этот коммит и сохранили своё staged/unstaged состояние.
