#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [[ "${SEASONVAR_DOCS_HOOK:-}" == "1" || "${SEASONVAR_SKIP_DOCS_HOOK:-}" == "1" ]]; then
    exit 0
fi

managed_paths=(
    "README.md"
    "AGENTS.md"
    "docs/CODE_STANDARDS.md"
    "docs/UI_STANDARDS.md"
    "docs/DATA_RELATIONS.md"
    "docs/SOURCE_PARITY.md"
    "docs/MAINTENANCE_LOG.md"
)

if ! git diff --quiet -- "${managed_paths[@]}" || ! git diff --cached --quiet -- "${managed_paths[@]}"; then
    echo "Файлы документации уже содержат несохраненные изменения, автокоммит документации пропущен."
else
    php artisan project:docs-refresh --no-ansi

    if ! git diff --quiet -- "${managed_paths[@]}"; then
        git add -- "${managed_paths[@]}"
        SEASONVAR_DOCS_HOOK=1 git commit -m "docs: refresh project markdown"
    fi
fi

if [[ "${SEASONVAR_DOCS_AUTO_PUSH:-}" != "1" ]]; then
    echo "Автоматическая отправка документации в Git пропущена. Для включения установите SEASONVAR_DOCS_AUTO_PUSH=1."
    exit 0
fi

branch="$(git branch --show-current)"

if [[ -z "$branch" ]]; then
    echo "Текущая ветка не определена, автоматическая отправка в Git пропущена."
    exit 0
fi

git push origin HEAD:"$branch"
