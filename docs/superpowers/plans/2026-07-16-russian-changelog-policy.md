# План реализации полностью русскоязычного журнала изменений

> **Для агентных исполнителей:** ОБЯЗАТЕЛЬНЫЙ ДОПОЛНИТЕЛЬНЫЙ НАВЫК: используйте `superpowers:subagent-driven-development` или `superpowers:executing-plans` для последовательного выполнения задач. Состояние каждого шага отмечается флажком `- [ ]`.

**Цель:** Полностью перевести все существующие записи `CHANGELOG.md` на русский язык без сокращений и навсегда запретить английскую прозу в новых записях через локальную, Git-hook и CI-проверку.

**Архитектура:** Тонкий shell-сценарий выбирает рабочую или staged-версию журнала, а отдельный PHP CLI-сканер проверяет Markdown построчно после удаления fenced code, inline code и адресов. PHPUnit фиксирует разрешённые технические обозначения, отклонение английской и смешанной прозы, staged-поведение и интеграцию с Git/CI; сам журнал переводится по датированным разделам с точными количественными инвариантами.

**Технологии:** Bash, PHP 8.5 CLI, PHPUnit 12.5, Symfony Process, Git hooks, существующий `scripts/ci-check.sh`, Markdown.

## Общие ограничения

- Обычный текст `CHANGELOG.md` должен быть только на русском языке.
- Ни одна из 196 существующих записей не удаляется, не объединяется и не сокращается.
- Сохраняются все шесть датированных разделов, два подзаголовка третьего уровня, даты, числа, измерения, ограничения, результаты проверок и сведения об откате.
- Точные имена классов, методов, команд, файлов, маршрутов, переменных, форматов, протоколов, продуктов и библиотек сохраняются в исходном написании.
- Технические идентификаторы по возможности заключаются в обратные кавычки; обычные английские слова переводятся.
- Новая запись о введённой политике добавляется отдельно, поэтому итоговый журнал содержит 197 пунктов и не менее 223 строк.
- Production dependencies не добавляются.
- Работа выполняется только в существующей `main`; нельзя удалять, перезаписывать или прятать параллельные пользовательские изменения.
- Коммит разрешён только после остановки других writer-процессов, чистого проверенного снимка и сведения расхождения с `origin/main` без потери истории.

---

## Структура файлов

- Создать `scripts/check-changelog-policy.php`: единственная ответственность — построчная проверка уже выбранного Markdown-файла.
- Создать `scripts/check-changelog-policy.sh`: выбор `CHANGELOG.md`, произвольного пути или staged-версии, подготовка временного файла и запуск PHP-сканера.
- Создать `tests/Unit/ChangelogPolicyScriptTest.php`: поведенческие тесты рабочего файла, смешанного текста и временного Git-репозитория.
- Изменить `tests/Unit/CiQualityGateContractTest.php`: контракт подключения проверки к `pre-commit` и backend-профилю.
- Изменить `.githooks/pre-commit`: staged-проверка журнала.
- Изменить `scripts/ci-check.sh`: проверка рабочего журнала в backend-профиле.
- Изменить `CHANGELOG.md`: полный перевод и новая отдельная запись о политике.
- Изменить `AGENTS.md`, `docs/development.md` и `README.md`: постоянное правило и команды ручной проверки.

---

### Задача 1: Зафиксировать поведение проверки красными тестами

**Файлы:**

- Создать: `tests/Unit/ChangelogPolicyScriptTest.php`
- Будет создан позже: `scripts/check-changelog-policy.sh`
- Будет создан позже: `scripts/check-changelog-policy.php`

**Интерфейсы:**

- Принимает: путь к Markdown или `--staged` через `scripts/check-changelog-policy.sh`.
- Возвращает: код `0` для допустимого журнала; ненулевой код и русскую ошибку с номером строки для нарушения.

- [ ] **Шаг 1: Создать тест рабочего файла до реализации сценария**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ChangelogPolicyScriptTest extends TestCase
{
    private string $changelogPath;

    private ?string $repositoryPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changelogPath = sys_get_temp_dir().'/seasonvar-changelog-policy-'.bin2hex(random_bytes(6)).'.md';
    }

    protected function tearDown(): void
    {
        File::delete($this->changelogPath);

        if ($this->repositoryPath !== null) {
            File::deleteDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_it_accepts_russian_prose_with_exact_technical_identifiers(): void
    {
        File::put($this->changelogPath, <<<'MARKDOWN'
# Журнал изменений

## 2026-07-16

- Laravel запускает `php artisan test`, проверяет `GET /titles` и сохраняет JSON через HTTPS.
MARKDOWN);

        $process = $this->runPolicyCheck($this->changelogPath);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    #[DataProvider('invalidChangelogs')]
    public function test_it_rejects_english_prose(string $contents, string $expectedMessage): void
    {
        File::put($this->changelogPath, $contents);

        $process = $this->runPolicyCheck($this->changelogPath);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString($expectedMessage, $process->getErrorOutput());
    }

    public static function invalidChangelogs(): array
    {
        return [
            'полностью английская строка' => [
                "# Журнал изменений\n\n- Added a complete search workflow.\n",
                'Строка 3 должна содержать русский текст',
            ],
            'английское предложение после русского начала' => [
                "# Журнал изменений\n\n- Добавлен поиск. The old behavior remains unchanged.\n",
                'Строка 3 содержит английский обычный текст',
            ],
        ];
    }

    private function runPolicyCheck(string ...$arguments): Process
    {
        $process = new Process(['bash', base_path('scripts/check-changelog-policy.sh'), ...$arguments]);
        $process->run();

        return $process;
    }
}
```

- [ ] **Шаг 2: Запустить тест и подтвердить правильный красный результат**

Команда:

```bash
php artisan test tests/Unit/ChangelogPolicyScriptTest.php
```

Ожидается: отказ, потому что `scripts/check-changelog-policy.sh` ещё отсутствует; тест не должен падать из-за синтаксиса самого теста.

---

### Задача 2: Реализовать минимальный сканер рабочего файла

**Файлы:**

- Создать: `scripts/check-changelog-policy.php`
- Создать: `scripts/check-changelog-policy.sh`
- Проверить: `tests/Unit/ChangelogPolicyScriptTest.php`

**Интерфейсы:**

- `scripts/check-changelog-policy.php <path>` читает один готовый файл и возвращает код процесса.
- `scripts/check-changelog-policy.sh [path|--staged]` выбирает источник и делегирует проверку PHP-сканеру.

- [ ] **Шаг 1: Добавить PHP-сканер с точным разрешающим списком**

Сканер должен:

```php
<?php

declare(strict_types=1);

$path = $argv[1] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Проверка CHANGELOG: файл не найден.\n");
    exit(2);
}

$allowedTokens = array_fill_keys([
    'Artisan', 'Codex', 'Composer', 'Chromium', 'Eloquent', 'FontAwesome',
    'Git', 'GitHub', 'Google', 'HDRezka', 'IMDb', 'Kinopoisk', 'Laravel', 'Larastan',
    'Livewire', 'Markdown', 'Memcached', 'OpenAI', 'OpenAPI', 'PHPStan',
    'PHPUnit', 'Pint', 'Playwright', 'Plyr', 'Rector', 'Redis', 'Sanctum',
    'Seasonvar', 'SQLite', 'Tailwind', 'Vite', 'WebP', 'systemd',
], true);

$fence = null;
$lines = file($path, FILE_IGNORE_NEW_LINES);

foreach ($lines === false ? [] : $lines as $index => $line) {
    $lineNumber = $index + 1;
    $trimmed = ltrim($line);

    if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
        $marker = substr($trimmed, 0, 3);
        $fence = $fence === null ? $marker : ($fence === $marker ? null : $fence);
        continue;
    }

    if ($fence !== null || $trimmed === '' || str_starts_with($trimmed, '<!--')) {
        continue;
    }

    if (preg_match('/^#{1,6}\s+\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        continue;
    }

    $plain = preg_replace([
        '/`[^`]*`/u',
        '~https?://[^\s)]+~u',
        '/\]\([^)]*\)/u',
    ], ' ', $line) ?? $line;

    if (! preg_match('/[\p{L}\p{N}]/u', $plain)) {
        continue;
    }

    if (! preg_match('/\p{Cyrillic}/u', $plain)) {
        fwrite(STDERR, "Проверка CHANGELOG: Строка {$lineNumber} должна содержать русский текст.\n");
        exit(1);
    }

    preg_match_all('/[A-Za-z][A-Za-z0-9.+_-]*/', $plain, $matches);

    foreach ($matches[0] as $token) {
        if (isset($allowedTokens[$token]) || preg_match('/^[A-Z][A-Z0-9-]{1,11}$/', $token) || preg_match('/^v\d+$/i', $token)) {
            continue;
        }

        fwrite(STDERR, "Проверка CHANGELOG: Строка {$lineNumber} содержит английский обычный текст: {$token}.\n");
        exit(1);
    }
}
```

- [ ] **Шаг 2: Добавить shell-обёртку для обычного файла**

```bash
#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
target="${1:-CHANGELOG.md}"

php "$script_dir/check-changelog-policy.php" "$target"
```

- [ ] **Шаг 3: Проверить синтаксис и зелёный рабочий режим**

```bash
bash -n scripts/check-changelog-policy.sh
php -l scripts/check-changelog-policy.php
php artisan test tests/Unit/ChangelogPolicyScriptTest.php
```

Ожидается: три успешных команды; тесты русского и технического текста проходят, английские строки отклоняются.

---

### Задача 3: Добавить staged-поведение через красный и зелёный тест

**Файлы:**

- Изменить: `tests/Unit/ChangelogPolicyScriptTest.php`
- При необходимости изменить: `scripts/check-changelog-policy.sh`

**Интерфейсы:**

- `--staged` всегда читает `:CHANGELOG.md` из индекса текущего Git-репозитория.

- [ ] **Шаг 1: Добавить тест временного Git-репозитория**

```php
public function test_staged_mode_reads_the_git_index_instead_of_the_working_file(): void
{
    $this->repositoryPath = sys_get_temp_dir().'/seasonvar-changelog-repository-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->repositoryPath, recursive: true);
    File::put($this->repositoryPath.'/CHANGELOG.md', "# Журнал изменений\n\n- Добавлен поиск.\n");

    $this->runGit('init', '-b', 'main');
    $this->runGit('config', 'user.name', 'Seasonvar Test');
    $this->runGit('config', 'user.email', 'seasonvar@example.com');
    $this->runGit('add', 'CHANGELOG.md');

    File::put($this->repositoryPath.'/CHANGELOG.md', "# Changelog\n\n- Added search.\n");

    $process = new Process(['bash', base_path('scripts/check-changelog-policy.sh'), '--staged']);
    $process->setWorkingDirectory($this->repositoryPath);
    $process->run();

    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
}

private function runGit(string ...$arguments): void
{
    $process = new Process(['git', ...$arguments]);
    $process->setWorkingDirectory($this->repositoryPath);
    $process->mustRun();
}
```

- [ ] **Шаг 2: Запустить новый тест до реализации staged-ветки и подтвердить красный результат**

Команда:

```bash
php artisan test tests/Unit/ChangelogPolicyScriptTest.php --filter=staged_mode
```

Ожидается: тест завершается отказом, потому что shell-обёртка ещё воспринимает `--staged` как обычный путь и не умеет читать индекс Git.

- [ ] **Шаг 3: Реализовать staged-ветку в shell-обёртке**

Между определением `target` и запуском PHP добавить:

```bash
if [[ "$target" == "--staged" ]]; then
    repo_root="$(git rev-parse --show-toplevel)"
    temporary_file="$(mktemp)"
    trap 'rm -f "$temporary_file"' EXIT

    if ! git -C "$repo_root" show :CHANGELOG.md > "$temporary_file"; then
        echo "Проверка CHANGELOG: файл отсутствует в индексе Git." >&2
        exit 2
    fi

    target="$temporary_file"
fi
```

- [ ] **Шаг 4: Подтвердить зелёный результат**

```bash
php artisan test tests/Unit/ChangelogPolicyScriptTest.php
```

Ожидается: все тесты сценария проходят.

---

### Задача 4: Подключить правило к Git-хуку и backend-профилю

**Файлы:**

- Изменить: `tests/Unit/CiQualityGateContractTest.php`
- Изменить: `.githooks/pre-commit`
- Изменить: `scripts/ci-check.sh`

**Интерфейсы:**

- Pre-commit вызывает `"$repo_root/scripts/check-changelog-policy.sh" --staged`.
- Backend вызывает `bash scripts/check-changelog-policy.sh CHANGELOG.md` до дорогих анализаторов.

- [ ] **Шаг 1: Добавить красный контрактный тест**

```php
public function test_changelog_russian_policy_runs_before_commit_and_in_backend_ci(): void
{
    $hook = File::get(base_path('.githooks/pre-commit'));
    $qualityGate = File::get(base_path('scripts/ci-check.sh'));

    $this->assertStringContainsString(
        '"$repo_root/scripts/check-changelog-policy.sh" --staged',
        $hook,
    );
    $this->assertStringContainsString(
        'bash scripts/check-changelog-policy.sh CHANGELOG.md',
        $qualityGate,
    );
}
```

- [ ] **Шаг 2: Запустить тест и подтвердить ожидаемый отказ**

```bash
php artisan test tests/Unit/CiQualityGateContractTest.php --filter=changelog_russian_policy
```

Ожидается: отказ по отсутствующему вызову.

- [ ] **Шаг 3: Добавить вызовы в production-сценарии**

В `.githooks/pre-commit` после проверки `README.md`:

```bash
"$repo_root/scripts/check-changelog-policy.sh" --staged
```

В `run_backend()` после `composer audit`:

```bash
bash scripts/check-changelog-policy.sh CHANGELOG.md
```

- [ ] **Шаг 4: Подтвердить зелёный контракт**

```bash
php artisan test tests/Unit/CiQualityGateContractTest.php --filter=changelog_russian_policy
bash -n .githooks/pre-commit scripts/ci-check.sh scripts/check-changelog-policy.sh
```

Ожидается: тест и shell-синтаксис проходят.

---

### Задача 5: Полностью перевести раздел 16 июля

**Файлы:**

- Изменить: `CHANGELOG.md:1-83`

**Интерфейсы:**

- Сохраняет заголовок, раздел `2026-07-16`, оба существующих подзаголовка и каждую существующую запись.
- Добавляет одну самостоятельную русскую запись о переводе и автоматической политике.

- [ ] **Шаг 1: Перевести заголовок и все записи до раздела 15 июля**

Требования к содержанию:

- `# Changelog` заменить на `# Журнал изменений`.
- `### Recommendation and discovery architecture` заменить на `### Архитектура рекомендаций и поиска контента`.
- Перевести каждое английское предложение и каждый обычный английский термин в уже смешанных записях.
- Не менять значения в обратных кавычках, коды состояний, переменные окружения, маршруты, числа и версии.
- Не переносить несколько существующих пунктов в один.

- [ ] **Шаг 2: Добавить отдельную запись о новой политике**

```markdown
- Весь технический журнал изменений полностью переведён на русский язык без сокращения прежних записей. Новая локальная проверка, `Git`-хук и проверка непрерывной интеграции запрещают английский обычный текст, сохраняя точные технические идентификаторы, команды, маршруты, протоколы и названия продуктов.
```

- [ ] **Шаг 3: Проверить только переведённый диапазон**

```bash
translated_section="$(mktemp)"
trap 'rm -f "$translated_section"' EXIT
sed -n '1,/^## 2026-07-15$/p' CHANGELOG.md > "$translated_section"
bash scripts/check-changelog-policy.sh "$translated_section"
```

Ожидается: диапазон проходит без английской прозы.

---

### Задача 6: Полностью перевести разделы 15 и 14 июля

**Файлы:**

- Изменить: `CHANGELOG.md`, разделы `2026-07-15` и `2026-07-14`

**Интерфейсы:**

- Сохраняет 21 существующую запись двух разделов отдельными пунктами.

- [ ] **Шаг 1: Перевести все записи 15 июля**

Перевести обычный текст про теги, персональные теги, отзывы, обсуждения, подборки, кеширование, производственную готовность, диагностику, импорт и пользовательский портал. Сохранить точные маршруты, модели, числа, версии и названия инструментов.

- [ ] **Шаг 2: Перевести все записи 14 июля**

Перевести обычный текст про мобильную синхронизацию, поиск только по названиям, воспроизведение, состояние владельца, аутентификацию, публичный API и пагинацию. Не менять `/api/v1`, OpenAPI, Sanctum и числовые сроки хранения.

- [ ] **Шаг 3: Проверить оба диапазона**

```bash
translated_section="$(mktemp)"
trap 'rm -f "$translated_section"' EXIT
sed -n '/^## 2026-07-15$/,/^## 2026-07-13$/p' CHANGELOG.md > "$translated_section"
bash scripts/check-changelog-policy.sh "$translated_section"
```

Ожидается: проверка проходит.

---

### Задача 7: Полностью перевести раздел 13 июля

**Файлы:**

- Изменить: `CHANGELOG.md`, раздел `2026-07-13`

**Интерфейсы:**

- Сохраняет каждую запись крупнейшего исторического раздела отдельным пунктом и в прежнем порядке.

- [ ] **Шаг 1: Перевести архитектурные, поисковые и эксплуатационные записи**

Перевести обычный текст про аудит, фильтры, рекомендации, права правообладателей, справочники, FTS, Seasonvar, фоновые обновления, карточки, кеш, производительность и документацию.

- [ ] **Шаг 2: Перевести административные, безопасностные, импортные и пользовательские записи**

Перевести обычный текст про `/admin/catalog`, `/admin/imports`, SSRF, лимиты, медиа, доступ, избранное, рейтинги, просмотр, прогресс и жизненный цикл проигрывателя. Все точные состояния, маршруты, индексы, интервалы, измерения и идентификаторы сохранить.

- [ ] **Шаг 3: Проверить диапазон 13 июля**

```bash
translated_section="$(mktemp)"
trap 'rm -f "$translated_section"' EXIT
sed -n '/^## 2026-07-13$/,/^## 2026-07-12$/p' CHANGELOG.md > "$translated_section"
bash scripts/check-changelog-policy.sh "$translated_section"
```

Ожидается: проверка проходит.

---

### Задача 8: Полностью перевести разделы 12 и 9 июля

**Файлы:**

- Изменить: `CHANGELOG.md`, разделы `2026-07-12` и `2026-07-09`

**Интерфейсы:**

- Сохраняет все записи, их порядок и точные технические ссылки.

- [ ] **Шаг 1: Перевести раздел 12 июля**

Перевести обычный текст про фасеты, фильтрацию, поиск, сортировку, URL-состояние, индексы, Livewire и PHPUnit. Сохранить NFKC, HTTP `429`, `650 мс`, имена классов и маршруты.

- [ ] **Шаг 2: Перевести раздел 9 июля**

Перевести обычный текст про уведомления импорта, приватные загрузки, формы, Eloquent, документацию, GitHub Actions, окружение, Vite, JSON API, очередь, заголовки безопасности, ограничитель `/stats`, локальное хранилище и тесты.

- [ ] **Шаг 3: Проверить весь журнал и количественные инварианты**

```bash
bash scripts/check-changelog-policy.sh CHANGELOG.md
test "$(rg -c '^## 20[0-9]{2}-[0-9]{2}-[0-9]{2}$' CHANGELOG.md)" = 6
test "$(rg -c '^### ' CHANGELOG.md)" = 2
test "$(rg -c '^- ' CHANGELOG.md)" = 197
test "$(wc -l < CHANGELOG.md)" -ge 223
```

Ожидается: все четыре проверки проходят.

---

### Задача 9: Закрепить правило в документации проекта

**Файлы:**

- Изменить: `AGENTS.md`
- Изменить: `docs/development.md`
- Изменить: `README.md`

**Интерфейсы:**

- Агенты, разработчики, Git-hook и CI используют одно правило русского обычного текста.

- [ ] **Шаг 1: Добавить обязательное правило в `AGENTS.md`**

После определения владельца `CHANGELOG.md` добавить:

```markdown
- Весь обычный текст `CHANGELOG.md` должен быть на русском языке. Точные имена технологий, классов, методов, команд, параметров, маршрутов, путей, переменных окружения, протоколов и форматов сохраняются в исходном написании и оформляются как технические идентификаторы.
- Нельзя сокращать, объединять или удалять прежние записи `CHANGELOG.md` при переводе или обновлении; новая запись добавляется отдельным пунктом в соответствующую дату.
```

- [ ] **Шаг 2: Обновить рабочий процесс в `docs/development.md`**

Рядом с проверкой README добавить описание staged/CI-проверки и команды:

```bash
bash -n scripts/check-changelog-policy.sh
php -l scripts/check-changelog-policy.php
scripts/check-changelog-policy.sh CHANGELOG.md
```

- [ ] **Шаг 3: Обновить раздел правил Git в `README.md`**

Добавить русскую запись о том, что подробный технический журнал ведётся полностью на русском и проверяется перед коммитом и в CI. Пользовательскую историю не дополнять, потому что изменение не добавляет посетителю новую функцию.

- [ ] **Шаг 4: Проверить обе документационные политики**

```bash
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
php artisan project:docs-refresh --check --no-interaction
```

Ожидается: все проверки проходят, управляемые блоки не требуют ручной правки.

---

### Задача 10: Выполнить финальную проверку, свести Git и опубликовать

**Файлы:**

- Проверить все перечисленные в плане файлы.
- Не изменять посторонние файлы ради прохождения проверки без отдельного доказанного сбоя.

**Интерфейсы:**

- Локальный результат, Git-hook и GitHub Actions используют одинаковую политику.

- [ ] **Шаг 1: Выполнить узкие проверки на стабильном дереве**

```bash
bash -n scripts/check-changelog-policy.sh .githooks/pre-commit scripts/ci-check.sh
php -l scripts/check-changelog-policy.php
php artisan test tests/Unit/ChangelogPolicyScriptTest.php tests/Unit/CiQualityGateContractTest.php
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
php artisan project:docs-refresh --check --no-interaction
git diff --check
```

Ожидается: каждая команда завершается с кодом `0`.

- [ ] **Шаг 2: Выполнить полный общий профиль после остановки writer-процессов**

```bash
bash scripts/ci-check.sh full
```

Ожидается: backend, frontend и browser-проверки завершаются успешно на одном неизменном снимке.

- [ ] **Шаг 3: Проверить ветку и безопасно свести удалённую историю**

```bash
git status --short --branch
git branch --show-current
git fetch origin main
git log --left-right --cherry-pick --oneline HEAD...origin/main
```

Ожидается: ветка `main`; другие процессы не меняют файлы. Если ветки разошлись, выполнить обычное слияние `origin/main` без удаления локальных коммитов и повторить полный профиль.

- [ ] **Шаг 4: Подготовить все согласованные изменения и создать коммит**

```bash
git add -A
git status --short --branch
git commit -m "docs: перевести журнал изменений на русский"
```

Ожидается: pre-commit подтверждает чистое staged-состояние, обе языковые политики и безопасные пути.

- [ ] **Шаг 5: Отправить `main` и дождаться успешного GitHub Actions**

```bash
git push origin main
```

После push получить run для точного финального SHA через GitHub API и ждать завершения заданий Backend, Frontend и Browser. При сбое определить конкретный шаг, исправить через воспроизводящий тест, повторить полный профиль, новый коммит, push и наблюдение.

- [ ] **Шаг 6: Подтвердить доставку**

```bash
test "$(git rev-parse HEAD)" = "$(git ls-remote --heads origin main | awk '{print $1}')"
git status --short --branch
```

Ожидается: локальный и удалённый SHA совпадают, рабочее дерево чистое, GitHub Actions для этого SHA полностью успешен.
