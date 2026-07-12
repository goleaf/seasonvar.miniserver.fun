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

seasonvar_git_guard_require_no_unstaged_changes() {
    if ! git diff --quiet --; then
        echo "Seasonvar Git guard: есть unstaged tracked changes. Добавьте их в commit или отмените перед commit." >&2
        git status --short >&2
        exit 1
    fi
}

seasonvar_git_guard_require_no_untracked_files() {
    local untracked
    untracked="$(git ls-files --others --exclude-standard)"

    if [[ -n "$untracked" ]]; then
        echo "Seasonvar Git guard: есть untracked files. Добавьте их в commit или уберите из рабочего дерева перед commit." >&2
        git status --short >&2
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
