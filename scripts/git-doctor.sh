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
canonical_https_origin="https://github.com/goleaf/seasonvar.miniserver.fun.git"
canonical_ssh_origin="git@github.com:goleaf/seasonvar.miniserver.fun.git"

if [[ -z "$origin_url" ]]; then
    fail "Remote origin не настроен."
elif [[ "$origin_url" =~ ^https:// ]]; then
    authority="${origin_url#https://}"
    authority="${authority%%/*}"

    if [[ "$authority" == *"@"* ]]; then
        fail "HTTPS origin содержит запрещённые embedded credentials."
    elif [[ "$origin_url" != "$canonical_https_origin" ]]; then
        fail "origin не соответствует каноническому repository."
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
    if [[ "$origin_url" != "$canonical_ssh_origin" ]]; then
        fail "origin не соответствует каноническому repository."
    else
        transport="ssh"
        ok "origin использует SSH."

        ssh_command="$(git config --local --get core.sshCommand 2>/dev/null || true)"

        if [[ -z "$ssh_command" ]]; then
            fail "SSH origin требует repository-local exact identity."
        elif [[ ! "$ssh_command" =~ (^|[[:space:]])-i([[:space:]]|$) ]] \
            || [[ "$ssh_command" != *"IdentitiesOnly=yes"* ]] \
            || [[ "$ssh_command" != *"BatchMode=yes"* ]]; then
            fail "SSH identity должна явно задавать ключ, IdentitiesOnly=yes и BatchMode=yes."
        else
            ok "Repository-local SSH identity policy настроена."
        fi
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
