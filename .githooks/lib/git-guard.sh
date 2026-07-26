#!/usr/bin/env bash
set -euo pipefail

seasonvar_git_guard_repo_root() {
    git rev-parse --show-toplevel
}

seasonvar_git_guard_branch() {
    git symbolic-ref --quiet --short HEAD 2>/dev/null || true
}

seasonvar_git_guard_require_main() {
    local branch
    branch="$(seasonvar_git_guard_branch)"

    if [[ "$branch" != "main" ]]; then
        echo "Seasonvar Git guard: работа разрешена только в существующей ветке main. Текущая ветка: ${branch:-detached}." >&2
        exit 1
    fi
}

seasonvar_git_guard_require_no_conflicts() {
    local conflicts
    conflicts="$(git ls-files --unmerged)"

    if [[ -n "$conflicts" ]]; then
        echo "Seasonvar Git guard: найдены неразрешённые конфликты. Commit/push остановлен без изменения файлов." >&2
        git diff --name-only --diff-filter=U -- >&2
        exit 1
    fi
}

seasonvar_git_guard_require_task_environment() {
    if [[ -z "${SEASONVAR_TASK_ID:-}" || -z "${SEASONVAR_TASK_LEASE_TOKEN:-}" ]]; then
        echo "Seasonvar Git guard: задайте SEASONVAR_TASK_ID и matching SEASONVAR_TASK_LEASE_TOKEN активного owner process." >&2
        exit 1
    fi
}

seasonvar_git_guard_run_lease_command() {
    local command="$1"
    local repo_root

    seasonvar_git_guard_require_task_environment
    repo_root="$(seasonvar_git_guard_repo_root)"

    "$repo_root/scripts/task-workspace-lease.sh" "$command" "$SEASONVAR_TASK_ID"
}

seasonvar_git_guard_require_workspace_lease() {
    seasonvar_git_guard_run_lease_command verify-owner
}

seasonvar_git_guard_require_declared_paths() {
    seasonvar_git_guard_run_lease_command verify-paths
}

seasonvar_git_guard_require_approved_index() {
    seasonvar_git_guard_run_lease_command verify-index
}

seasonvar_git_guard_staged_paths() {
    git diff --cached --name-only --diff-filter=ACMR -z --
}

seasonvar_git_guard_tracked_paths() {
    git ls-files -z
}

seasonvar_git_guard_is_temporary_path() {
    local path="$1"
    local basename="${path##*/}"

    case "$basename" in
        .DS_Store|Thumbs.db|*.swp|*.swo|*.tmp|*.temp|*.bak|*.orig|*.rej|*~|debug.log|npm-debug.log|npm-debug.log.*|yarn-debug.log|yarn-error.log|pnpm-debug.log|phpunit.result.cache)
            return 0
            ;;
    esac

    return 1
}

seasonvar_git_guard_is_sensitive_path() {
    local path="$1"
    local basename="${path##*/}"

    case "$basename" in
        .env.example|.env.*.example|.env.dist|*.example.pem|*.example.key|*.example.p12|*.example.pfx)
            return 1
            ;;
        .env|.env.*|credentials.json|credentials-*.json|credentials_*.json|client_secret*.json|client-secret*.json|service-account*.json|service_account*.json|google-credentials*.json|*.pem|*.key|*.p12|*.pfx|id_rsa|id_rsa.*|id_ed25519|id_ed25519.*|.pypirc|auth.json)
            return 0
            ;;
    esac

    case "/$path" in
        */.aws/credentials|*/.ssh/*|*/.config/gcloud/application_default_credentials.json)
            return 0
            ;;
    esac

    return 1
}

seasonvar_git_guard_require_safe_paths() {
    local scope="$1"
    local path
    local -a temporary_paths=()
    local -a sensitive_paths=()

    if [[ "$scope" == "staged" ]]; then
        while IFS= read -r -d '' path; do
            if seasonvar_git_guard_is_temporary_path "$path"; then
                temporary_paths+=("$path")
            fi

            if seasonvar_git_guard_is_sensitive_path "$path"; then
                sensitive_paths+=("$path")
            fi
        done < <(seasonvar_git_guard_staged_paths)
    elif [[ "$scope" == "tracked" ]]; then
        while IFS= read -r -d '' path; do
            if seasonvar_git_guard_is_temporary_path "$path"; then
                temporary_paths+=("$path")
            fi

            if seasonvar_git_guard_is_sensitive_path "$path"; then
                sensitive_paths+=("$path")
            fi
        done < <(seasonvar_git_guard_tracked_paths)
    else
        echo "Seasonvar Git guard: неизвестная область проверки путей: $scope." >&2
        exit 1
    fi

    if (( ${#temporary_paths[@]} > 0 )); then
        echo "Seasonvar Git guard: обнаружены временные или отладочные файлы. Удалите их из Git и повторите операцию:" >&2
        printf '  - %s\n' "${temporary_paths[@]}" >&2
        exit 1
    fi

    if (( ${#sensitive_paths[@]} > 0 )); then
        echo "Seasonvar Git guard: ВНИМАНИЕ — путь похож на .env или файл credentials. Операция остановлена; храните секреты вне Git:" >&2
        printf '  - %s\n' "${sensitive_paths[@]}" >&2
        echo "Разрешены только безопасные шаблоны вроде .env.example без реальных значений." >&2
        exit 1
    fi
}

seasonvar_git_guard_require_clean_tree() {
    if [[ -n "$(git status --porcelain)" ]]; then
        echo "Seasonvar Git guard: рабочее дерево должно быть чистым перед push." >&2
        git status --short >&2
        exit 1
    fi
}
