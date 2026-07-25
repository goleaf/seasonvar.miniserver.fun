# Native Git SSH Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking. This repository must not use
> subagents, branches or worktrees for this plan.

**Goal:** Добавить безопасную локальную диагностику Git workflow, настроить
repository-scoped SSH deploy key вне Git и доказать обычный
`git commit → git push origin main` без PAT и обхода hooks.

**Architecture:** `scripts/git-doctor.sh` является read-only Bash boundary,
которая проверяет branch, hooks, remote, credential capability, dirty counts,
conflicts и локальное divergence; `--remote` добавляет bounded
`git ls-remote`. Authentication остаётся user-level: отдельный Ed25519 deploy
key закрепляется только за одним repository, а local `.git/config` выбирает
exact identity через `core.sshCommand`.

**Tech Stack:** Git 2.52, Bash, PHP 8.5, Laravel 13.22, PHPUnit 12.5,
Composer 2, GitHub SSH.

## Global Constraints

- Работа выполняется только в существующей `main`; branch/worktree/PR не
  создаются.
- Существующие staged Task 52 files не сбрасываются, не переписываются и не
  включаются в Task 55 commits.
- Любая production implementation начинается только после наблюдаемого RED.
- `pre-commit` продолжает разрешать unrelated dirty files и проверять
  staged scope; `pre-push` продолжает требовать clean tree.
- Private/public SSH key, exact user-level path и credential не попадают в
  tracked files, logs, changelog или final summary.
- Новые Composer/npm/system production dependencies не добавляются.
- GitHub ruleset, Actions permissions, secret scanning, push protection и
  pinned workflow не изменяются.
- Laravel routes, schema, data, translations, cache keys, permissions,
  queues, browser и visitor product не изменяются.
- Обычный текст README/CHANGELOG остаётся русским; visitor history не получает
  фиктивную запись.
- Rollback: HTTPS remote → revoke deploy key → exact
  `core.sshCommand`/key removal → обычный revert commit для repository code.

---

### Task 1: Зафиксировать RED-контракт Git doctor

**Files:**

- Create: `tests/Unit/GitWorkflowDoctorTest.php`
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**

- Consumes: будущий executable `scripts/git-doctor.sh`.
- Produces: CLI contract exit `0|1|2`, стабильные `[OK]`/`[WARN]`/`[FAIL]`
  diagnostics и Composer integration expectation.
- Preserves: реальные hooks копируются в fixture только для syntax/tracked
  checks; их application behavior не подменяется.

- [x] **Step 1: Создать fixture и happy-path test**

Создать `tests/Unit/GitWorkflowDoctorTest.php` со следующей структурой:

```php
<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GitWorkflowDoctorTest extends TestCase
{
    private ?string $repositoryPath = null;

    protected function tearDown(): void
    {
        if ($this->repositoryPath !== null) {
            File::deleteDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_clean_main_repository_with_versioned_hooks_passes_local_diagnostics(): void
    {
        $this->makeRepository();

        $process = $this->runDoctor();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('[OK] Текущая ветка: main.', $process->getOutput());
        $this->assertStringContainsString('[OK] core.hooksPath=.githooks.', $process->getOutput());
        $this->assertStringContainsString('[OK] origin использует SSH.', $process->getOutput());
        $this->assertStringContainsString(
            '[WARN] Локальный origin/main отсутствует; ahead/behind не вычислен.',
            $process->getOutput(),
        );
    }

    private function makeRepository(string $branch = 'main'): string
    {
        $this->repositoryPath = sys_get_temp_dir().'/seasonvar-git-doctor-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->repositoryPath);
        $this->runGit('init', '-b', $branch);
        $this->runGit('config', 'user.name', 'Seasonvar Test');
        $this->runGit('config', 'user.email', 'seasonvar@example.com');

        File::put($this->repositoryPath.'/tracked.txt', "исходное состояние\n");
        $this->runGit('add', '--', 'tracked.txt');
        $this->runGit('commit', '-m', 'Исходное состояние');

        foreach ([
            '.githooks/pre-commit',
            '.githooks/pre-push',
            '.githooks/post-commit',
            '.githooks/lib/git-guard.sh',
        ] as $hook) {
            File::ensureDirectoryExists(dirname($this->repositoryPath.'/'.$hook));
            File::copy(base_path($hook), $this->repositoryPath.'/'.$hook);
            chmod($this->repositoryPath.'/'.$hook, 0755);
        }

        $this->runGit('add', '--', '.githooks');
        $this->runGit('commit', '-m', 'Добавлены hooks');
        $this->runGit('config', 'core.hooksPath', '.githooks');
        $this->runGit(
            'remote',
            'add',
            'origin',
            'git@github.com:goleaf/seasonvar.miniserver.fun.git',
        );

        return $this->repositoryPath;
    }

    private function runDoctor(string ...$arguments): Process
    {
        $process = new Process(
            ['bash', base_path('scripts/git-doctor.sh'), ...$arguments],
            $this->repositoryPath,
        );
        $process->setEnv([
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'LC_ALL' => 'C.UTF-8',
        ]);
        $process->run();

        return $process;
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->mustRun();
    }
}
```

- [x] **Step 2: Добавить failure/partial/privacy tests**

Добавить в тот же класс до private helpers:

```php
public function test_missing_hook_and_wrong_hooks_path_are_blockers(): void
{
    $this->makeRepository();
    File::delete($this->repositoryPath.'/.githooks/pre-push');
    $this->runGit('config', 'core.hooksPath', '.git/hooks');

    $process = $this->runDoctor();

    $this->assertSame(1, $process->getExitCode());
    $this->assertStringContainsString('[FAIL] core.hooksPath должен быть .githooks.', $process->getOutput());
    $this->assertStringContainsString('[FAIL] Отсутствует hook: .githooks/pre-push.', $process->getOutput());
}

public function test_branch_other_than_main_is_a_blocker(): void
{
    $this->makeRepository('temporary');

    $process = $this->runDoctor();

    $this->assertSame(1, $process->getExitCode());
    $this->assertStringContainsString(
        '[FAIL] Работа разрешена только в main; текущая ветка: temporary.',
        $process->getOutput(),
    );
}

public function test_partial_dirty_state_reports_counts_without_disclosing_contents(): void
{
    $this->makeRepository();
    File::put($this->repositoryPath.'/staged-secret-name.txt', "подготовлено\n");
    $this->runGit('add', '--', 'staged-secret-name.txt');
    File::append($this->repositoryPath.'/tracked.txt', "не в индексе\n");
    File::put($this->repositoryPath.'/untracked-secret-name.txt', "не отслеживается\n");

    $process = $this->runDoctor();

    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    $this->assertStringContainsString(
        '[WARN] Рабочее дерево: staged=1, unstaged=1, untracked=1.',
        $process->getOutput(),
    );
    $this->assertStringNotContainsString('staged-secret-name.txt', $process->getOutput());
    $this->assertStringNotContainsString('untracked-secret-name.txt', $process->getOutput());
}

public function test_https_remote_with_embedded_credentials_is_rejected_without_echoing_them(): void
{
    $this->makeRepository();
    $this->runGit(
        'remote',
        'set-url',
        'origin',
        'https://user:super-secret-token@github.com/goleaf/seasonvar.miniserver.fun.git',
    );

    $process = $this->runDoctor();

    $this->assertSame(1, $process->getExitCode());
    $this->assertStringContainsString(
        '[FAIL] HTTPS origin содержит запрещённые embedded credentials.',
        $process->getOutput(),
    );
    $this->assertStringNotContainsString('super-secret-token', $process->getOutput());
    $this->assertStringNotContainsString('user:', $process->getOutput());
}

public function test_unknown_argument_returns_usage_error(): void
{
    $this->makeRepository();

    $process = $this->runDoctor('--unknown');

    $this->assertSame(2, $process->getExitCode());
    $this->assertStringContainsString(
        'Использование: bash scripts/git-doctor.sh [--remote]',
        $process->getErrorOutput(),
    );
}

public function test_default_mode_does_not_contact_remote(): void
{
    $this->makeRepository();
    $this->runGit('remote', 'set-url', 'origin', 'ssh://127.0.0.1:1/never/contact.git');

    $process = $this->runDoctor();

    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    $this->assertStringContainsString('[OK] origin использует SSH.', $process->getOutput());
    $this->assertStringNotContainsString('Remote main доступен', $process->getOutput());
}
```

- [x] **Step 3: Добавить Composer/contract expectations**

В `tests/Unit/CiQualityGateContractTest.php` добавить:

```php
public function test_git_doctor_is_exposed_without_replacing_versioned_hooks(): void
{
    $composer = json_decode(
        File::get(base_path('composer.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $this->assertSame('bash scripts/git-doctor.sh', $composer['scripts']['git:doctor'] ?? null);
    $this->assertFileExists(base_path('scripts/git-doctor.sh'));
    $this->assertTrue(is_executable(base_path('scripts/git-doctor.sh')));

    $preCommit = File::get(base_path('.githooks/pre-commit'));
    $prePush = File::get(base_path('.githooks/pre-push'));

    $this->assertStringContainsString('seasonvar_git_guard_require_safe_paths staged', $preCommit);
    $this->assertStringContainsString('seasonvar_git_guard_require_clean_tree', $prePush);
}
```

- [x] **Step 4: Запустить RED**

Run:

```bash
php artisan test tests/Unit/GitWorkflowDoctorTest.php \
  tests/Unit/CiQualityGateContractTest.php
```

Expected: FAIL из-за отсутствующих `scripts/git-doctor.sh` и
`scripts.git:doctor`; failure не должен быть вызван неверной temp-repository
fixture.

- [x] **Step 5: Записать RED evidence в Task 55 matrix**

В `docs/plans/current-task-plan.md` сохранить exact test count и первые
ожидаемые diagnostics. Не менять Task 52 hunks и не выполнять broad
`git add`.

---

### Task 2: Реализовать минимальный GREEN Bash doctor

**Files:**

- Create: `scripts/git-doctor.sh`
- Modify: `composer.json`
- Test: `tests/Unit/GitWorkflowDoctorTest.php`
- Test: `tests/Unit/CiQualityGateContractTest.php`

**Interfaces:**

- Command: `bash scripts/git-doctor.sh [--remote]`.
- Exit codes: `0` pass/warnings, `1` blocker, `2` usage.
- Output: stable `[OK]`, `[WARN]`, `[FAIL]`; no filenames from dirty state,
  credentials, remote userinfo or key path.

- [x] **Step 1: Создать executable script**

Создать `scripts/git-doctor.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

remote_check=0
ok_count=0
warning_count=0
failure_count=0

usage() {
    echo "Использование: bash scripts/git-doctor.sh [--remote]" >&2
}

ok() {
    ok_count=$((ok_count + 1))
    echo "[OK] $1"
}

warn() {
    warning_count=$((warning_count + 1))
    echo "[WARN] $1"
}

fail() {
    failure_count=$((failure_count + 1))
    echo "[FAIL] $1"
}

count_nul_items() {
    awk 'BEGIN { RS = "\\0"; count = 0 } length($0) > 0 { count++ } END { print count }'
}

case "${1:-}" in
    "")
        ;;
    --remote)
        remote_check=1
        ;;
    *)
        usage
        exit 2
        ;;
esac

if [[ "$#" -gt 1 ]]; then
    usage
    exit 2
fi

if ! repo_root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    fail "Текущий каталог не принадлежит Git repository."
    echo "Итог: ok=$ok_count warnings=$warning_count failures=$failure_count."
    exit 1
fi

cd "$repo_root"
ok "Git repository обнаружен."

branch="$(git symbolic-ref --quiet --short HEAD 2>/dev/null || true)"
if [[ "$branch" == "main" ]]; then
    ok "Текущая ветка: main."
else
    fail "Работа разрешена только в main; текущая ветка: ${branch:-detached}."
fi

if [[ -n "$(git ls-files --unmerged)" ]]; then
    fail "Обнаружены unresolved conflicts."
else
    ok "Unresolved conflicts отсутствуют."
fi

hooks_path="$(git config --local --get core.hooksPath 2>/dev/null || true)"
if [[ "$hooks_path" == ".githooks" ]]; then
    ok "core.hooksPath=.githooks."
else
    fail "core.hooksPath должен быть .githooks."
fi

hooks=(
    ".githooks/pre-commit"
    ".githooks/pre-push"
    ".githooks/post-commit"
    ".githooks/lib/git-guard.sh"
)

for hook in "${hooks[@]}"; do
    if [[ ! -f "$hook" ]]; then
        fail "Отсутствует hook: $hook."
        continue
    fi

    if ! git ls-files --error-unmatch "$hook" >/dev/null 2>&1; then
        fail "Hook не отслеживается Git: $hook."
    elif [[ ! -x "$hook" ]]; then
        fail "Hook не имеет executable bit: $hook."
    elif ! bash -n "$hook"; then
        fail "Hook содержит ошибку Bash syntax: $hook."
    else
        ok "Hook готов: $hook."
    fi
done

origin_url="$(git remote get-url origin 2>/dev/null || true)"
transport=""

if [[ -z "$origin_url" ]]; then
    fail "Remote origin не настроен."
elif [[ "$origin_url" =~ ^https:// ]]; then
    authority="${origin_url#https://}"
    authority="${authority%%/*}"

    if [[ "$authority" == *"@"* ]]; then
        fail "HTTPS origin содержит запрещённые embedded credentials."
    else
        transport="https"
        ok "origin использует HTTPS без embedded credentials."

        if git config --get-all credential.helper 2>/dev/null | grep -q . \
            || command -v gh >/dev/null 2>&1 \
            || command -v git-credential-manager >/dev/null 2>&1 \
            || command -v git-credential-manager-core >/dev/null 2>&1; then
            ok "Обнаружен HTTPS credential mechanism."
        else
            fail "HTTPS remote не имеет обнаруженного credential helper."
        fi
    fi
elif [[ "$origin_url" =~ ^ssh:// ]] || [[ "$origin_url" =~ ^[^/]+@[^:]+:.+ ]]; then
    transport="ssh"
    ok "origin использует SSH."

    if git config --local --get core.sshCommand >/dev/null 2>&1 \
        || [[ -n "${SSH_AUTH_SOCK:-}" ]]; then
        ok "Обнаружен локальный SSH identity mechanism."
    else
        warn "SSH identity будет окончательно проверена только режимом --remote."
    fi
else
    fail "Remote origin использует неподдерживаемый transport."
fi

staged_count="$(git diff --cached --name-only --diff-filter=ACMRD -z -- | count_nul_items)"
unstaged_count="$(git diff --name-only --diff-filter=ACMRD -z -- | count_nul_items)"
untracked_count="$(git ls-files --others --exclude-standard -z -- | count_nul_items)"

if [[ "$staged_count" == "0" && "$unstaged_count" == "0" && "$untracked_count" == "0" ]]; then
    ok "Рабочее дерево чистое."
else
    warn "Рабочее дерево: staged=$staged_count, unstaged=$unstaged_count, untracked=$untracked_count."
fi

if git show-ref --verify --quiet refs/remotes/origin/main; then
    read -r behind_count ahead_count < <(
        git rev-list --left-right --count refs/remotes/origin/main...HEAD
    )
    ok "Локальный snapshot: ahead=$ahead_count, behind=$behind_count."
else
    warn "Локальный origin/main отсутствует; ahead/behind не вычислен."
fi

if [[ "$remote_check" == "1" && "$failure_count" == "0" ]]; then
    if command -v timeout >/dev/null 2>&1; then
        if GIT_TERMINAL_PROMPT=0 timeout 20 git ls-remote origin refs/heads/main >/dev/null 2>&1; then
            ok "Remote main доступен через $transport."
        else
            fail "Remote main недоступен; transport, credentials или provider требуют проверки."
        fi
    elif GIT_TERMINAL_PROMPT=0 git ls-remote origin refs/heads/main >/dev/null 2>&1; then
        ok "Remote main доступен через $transport."
    else
        fail "Remote main недоступен; transport, credentials или provider требуют проверки."
    fi
fi

echo "Итог: ok=$ok_count warnings=$warning_count failures=$failure_count."

if [[ "$failure_count" -gt 0 ]]; then
    exit 1
fi
```

Установить tracked executable mode `0755`.

- [x] **Step 2: Добавить Composer alias**

В `composer.json` рядом с `hooks:install` добавить:

```json
"git:doctor": "bash scripts/git-doctor.sh",
```

Не менять package manifests или lock files.

- [x] **Step 3: Запустить GREEN**

Run:

```bash
php artisan test tests/Unit/GitWorkflowDoctorTest.php \
  tests/Unit/CiQualityGateContractTest.php
```

Expected: все doctor и CI contract tests PASS.

- [x] **Step 4: Проверить реальный локальный diagnostic**

Run:

```bash
bash -n scripts/git-doctor.sh
composer git:doctor
```

Expected до SSH rollout: exit `1` только по доказанному HTTPS
credential-helper blocker; branch/hooks/conflicts checks `[OK]`, dirty Task 52
files показаны только counts.

- [x] **Step 5: Refactor только после GREEN**

Если shellcheck-подобная читаемость требует изменения, сохранять один
responsibility на helper и повторно запускать оба focused test files. Не
добавлять auto-fix, token arguments, key generation или push wrapper.

---

### Task 3: Обновить каноническую документацию и compliance

**Files:**

- Modify: `docs/development.md`
- Modify: `docs/ci.md`
- Modify: `docs/mcp.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**

- `docs/development.md` владеет local Git/SSH flow.
- `docs/ci.md` владеет границей doctor → hooks → CI.
- `docs/mcp.md` владеет connector-vs-Git credential boundary.
- README получает только developer quick-start; visitor history не меняется.

- [x] **Step 1: Обновить development owner**

Добавить exact команды:

```bash
composer hooks:install
composer git:doctor
composer git:doctor -- --remote
```

Описать, что private deploy key находится вне Git, `--remote` read-only, а
write access доказывает только normal push после clean-tree `pre-push`.

- [x] **Step 2: Обновить CI owner**

Зафиксировать:

- doctor не заменяет docs/backend/frontend/browser gates;
- default doctor не использует сеть;
- `--remote` не меняет ref;
- strict pre-push остаётся единственным local publish gate.

- [x] **Step 3: Обновить MCP owner**

Добавить:

- connector доступен и имеет repository permissions;
- connector session не экспортирует credential локальному Git;
- project `.codex/config.toml` не получает GitHub token/MCP secret;
- connector-created commit не является fallback обычного local push.

- [x] **Step 4: Обновить README и русский CHANGELOG**

В `README.md` Git-раздел добавить `composer git:doctor` и `--remote`.
Не добавлять visitor-history entry.

В `CHANGELOG.md` добавить отдельный русский пункт 25.07.2026:

```markdown
- Добавлена read-only команда `composer git:doctor`, которая проверяет
  ветку `main`, versioned hooks, безопасный remote, локальный credential
  mechanism, conflicts, dirty counts и ahead/behind без раскрытия путей или
  секретов. Опциональный `--remote` выполняет только bounded read-проверку.
  Нативная отправка переводится на отдельный repository-scoped SSH deploy
  key вне Git; partial `pre-commit`, clean-tree `pre-push`, GitHub ruleset и
  CI не ослаблены.
```

- [x] **Step 5: Обновить compliance evidence**

В Task 55 matrix отметить RED/GREEN, exact tests/assertions, files,
cross-feature state и оставшийся deploy-key owner gate. Не изменять Task 52.

- [x] **Step 6: Проверить документацию**

Run:

```bash
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check
```

Expected: exit `0`.

---

### Task 4: Зафиксировать repository implementation отдельно от чужого index

**Files:**

- Commit exact Task 55 tracked paths only.
- Preserve staged:
  `docs/plans/current-task-plan.md` Task 52 hunks and
  `docs/superpowers/plans/2026-07-25-collection-classification-cockpit.md`.

**Interfaces:**

- Produces: normal partial commit in `main`.
- Preserves: соседние staged semantics и рабочее содержимое.

- [x] **Step 1: Проверить exact scope**

Run:

```bash
git status --short --branch
git diff --cached --name-status
git diff --name-status
git diff --check
```

Expected: `main`; Task 55 paths известны; чужие Task 52 paths явно отделены.

- [x] **Step 2: Запустить pre-commit на exact temporary index**

Создать temporary index из current `HEAD`, добавить Task 55 code/docs paths,
а из `docs/plans/current-task-plan.md` применить только Task 55 hunk. Запустить
на нём настоящий `.githooks/pre-commit`. Проверить, что основной index blob
для двух Task 52 paths не изменился.

- [x] **Step 3: Создать path-limited implementation commit**

Commit message:

```text
feat: add native Git workflow diagnostics
```

Не использовать `--no-verify`, reset, stash, checkout или broad add.

- [x] **Step 4: Синхронизировать основной index без потери Task 52**

Если alternate-index commit оставил основной index относительно прежнего
HEAD, восстановить только semantic staged Task 52 state из неизменённого
worktree и сравнить staged diff до/после. Не включать Task 55 повторно.

---

### Task 5: Создать и подключить repository-scoped SSH deploy key

**Files:**

- Create outside Git: user-level Ed25519 private/public pair.
- Modify outside Git: local `.git/config` only after remote authentication.
- Modify remote after owner action: one repository deploy key with write.

**Interfaces:**

- Local remote: `git@github.com:goleaf/seasonvar.miniserver.fun.git`.
- Identity selection: local `core.sshCommand` with exact key and
  `IdentitiesOnly=yes`.
- No tracked credential files or values.

- [x] **Step 1: Resolve safe user-level SSH directory**

Run:

```bash
seasonvar_user_home="$(getent passwd "$(id -u)" | cut -d: -f6)"
seasonvar_ssh_dir="$seasonvar_user_home/.ssh"
seasonvar_key_path="$seasonvar_ssh_dir/seasonvar_github_deploy"
test -n "$seasonvar_user_home"
install -d -m 0700 "$seasonvar_ssh_dir"
```

Не выводить resolved private path в tracked documentation/final summary.

- [x] **Step 2: Проверить отсутствие exact key pair**

Run:

```bash
test ! -e "$seasonvar_key_path"
test ! -e "$seasonvar_key_path.pub"
```

Если exact target существует, остановиться и не перезаписывать его.

- [x] **Step 3: Создать repository-specific Ed25519 pair**

Run:

```bash
ssh-keygen \
  -t ed25519 \
  -N '' \
  -C 'seasonvar.miniserver.fun deploy key' \
  -f "$seasonvar_key_path"
chmod 0600 "$seasonvar_key_path"
chmod 0644 "$seasonvar_key_path.pub"
```

Передать пользователю только public key. Private key body не читать в model
context, не печатать и не копировать.

- [ ] **Step 4: Owner gate — добавить write deploy key**

В GitHub repository settings → Deploy keys добавить public key с названием
`seasonvar.miniserver.fun server` и включить write access. До подтверждения
этого действия не менять `origin` и не заявлять push capability.

- [ ] **Step 5: Проверить exact key против exact repository**

Run после owner confirmation:

```bash
GIT_SSH_COMMAND="ssh -i $seasonvar_key_path -o IdentitiesOnly=yes -o BatchMode=yes" \
  git ls-remote \
  git@github.com:goleaf/seasonvar.miniserver.fun.git \
  refs/heads/main
```

Expected: один SHA для `refs/heads/main`, без `Permission denied`.

- [ ] **Step 6: Переключить local remote только после GREEN**

Run:

```bash
git config --local core.sshCommand \
  "ssh -i $seasonvar_key_path -o IdentitiesOnly=yes -o BatchMode=yes"
git remote set-url origin \
  git@github.com:goleaf/seasonvar.miniserver.fun.git
git remote -v
composer git:doctor -- --remote
```

Expected: SSH transport `[OK]`, remote read `[OK]`; private path не
переносится в tracked output.

---

### Task 6: Финальная verification, push и remote evidence

**Files:**

- Modify: `docs/plans/current-task-plan.md`
- Modify if implementation changed: `CHANGELOG.md`
- No product/runtime files.

**Interfaces:**

- Produces: verified fast-forward `origin/main`.
- Preserves: clean-tree pre-push and GitHub main history rules.

- [x] **Step 1: Fresh focused and static verification**

Run:

```bash
php artisan test tests/Unit/GitWorkflowDoctorTest.php \
  tests/Unit/CiQualityGateContractTest.php \
  tests/Unit/AutomaticChangelogUpdateScriptTest.php \
  tests/Unit/ChangelogPolicyScriptTest.php
./vendor/bin/pint tests/Unit/GitWorkflowDoctorTest.php \
  tests/Unit/CiQualityGateContractTest.php --format agent
for shell_file in scripts/git-doctor.sh .githooks/pre-commit \
  .githooks/pre-push .githooks/post-commit .githooks/lib/git-guard.sh; do
  bash -n "$shell_file"
done
composer validate --strict
```

Expected: exit `0`, zero PHPUnit failures.

- [x] **Step 2: Fresh documentation verification**

Run:

```bash
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check
```

Expected: exit `0`.

- [x] **Step 3: Legacy/secret scan**

Run:

```bash
rg -n \
  "https://[^/[:space:]]+@github\\.com|github_pat_|ghp_|BEGIN OPENSSH PRIVATE KEY" \
  . \
  --glob '!vendor/**' \
  --glob '!node_modules/**' \
  --glob '!.git/**' \
  --glob '!docs/superpowers/plans/2026-07-25-native-git-ssh-diagnostics.md' \
  --glob '!docs/superpowers/plans/2026-07-16-canonical-ci-quality-gate.md' \
  --glob '!tests/Unit/GitWorkflowDoctorTest.php'
rg -n \
  "git-doctor|git:doctor|core\\.sshCommand|core\\.hooksPath" \
  composer.json scripts tests docs README.md AGENTS.md
```

Expected: первый scan не находит credentials/private key вне трёх явно
исключённых синтетических fixtures/self-references; второй показывает один
coherent doctor workflow.

- [x] **Step 4: Повторно прочитать requirements и matrix**

Перечитать `AGENTS.md`, requirements index, code/architecture/development,
multilingual, security, maintenance, production operations, system-wide
integration и Task 55. Все строки matrix получают только
`completed|already_compliant|not_applicable|unresolved`.

- [ ] **Step 5: Очистить разрешённый Task 55 scope**

Все Task 55 edits commit’ятся в `main`. Чужие Task 52 edits должны быть
зафиксированы их владельцем либо честно остаться blocker; их нельзя поглощать
ради clean tree.

- [ ] **Step 6: Запустить strict pre-push и normal push**

Только при полностью clean tree:

```bash
composer git:doctor -- --remote
bash .githooks/pre-push
git push origin main
```

Не устанавливать `SEASONVAR_SKIP_GIT_GUARD` и не использовать `--no-verify`.

- [ ] **Step 7: Подтвердить remote SHA**

Run:

```bash
local_main_sha="$(git rev-parse refs/heads/main)"
remote_main_sha="$(git ls-remote origin refs/heads/main | awk '{print $1}')"
test "$local_main_sha" = "$remote_main_sha"
git status --short --branch
```

Expected: local/remote SHA equal; branch `main`; clean tree.

- [ ] **Step 8: Финализировать compliance evidence**

Записать exact commit SHA, push result, tests/assertions, doctor remote result,
GitHub Actions status если он уже terminal, и все unresolved external states.
Не заявлять CI success до фактического terminal result.

## Plan Self-Review

- Spec coverage: authentication, doctor, Composer, TDD, docs, SSH rollout,
  rollback, partial commit, pre-push, remote evidence и secret lifecycle имеют
  отдельные исполнимые steps.
- Type/contract consistency: command везде называется
  `scripts/git-doctor.sh`; Composer alias — `git:doctor`; единственный option —
  `--remote`; exit codes — `0|1|2`.
- Scope: нет новых packages, routes, schema, UI, cache, queue или deployment
  mutation; GitHub App не используется как commit transport.
- Placeholder scan: каждый code-changing step содержит exact content или
  exact command; неопределённых implementation sections нет.
