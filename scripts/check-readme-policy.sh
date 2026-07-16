#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "Проверка README: $1" >&2
    exit 1
}

requires_readme_update() {
    local path="$1"

    case "$path" in
        app/*|bootstrap/*|config/*|database/*|deploy/*|lang/*|public/*|resources/*|routes/*|composer.json|composer.lock|package.json|package-lock.json|vite.config.*)
            return 0
            ;;
    esac

    return 1
}

source_path="${1:-}"

if [[ -z "$source_path" ]]; then
    fail "укажите путь к README.md или параметр --staged."
fi

temporary_file=""

cleanup() {
    if [[ -n "$temporary_file" ]]; then
        rm -f -- "$temporary_file"
    fi
}

trap cleanup EXIT

if [[ "$source_path" == "--staged" ]]; then
    repo_root="$(git rev-parse --show-toplevel)"
    cd "$repo_root"

    product_change_found=0

    while IFS= read -r -d '' staged_path; do
        if requires_readme_update "$staged_path"; then
            product_change_found=1
            break
        fi
    done < <(git diff --cached --name-only --diff-filter=ACMR -z --)

    if (( product_change_found == 1 )) && git diff --cached --quiet -- README.md; then
        fail "изменения продукта должны включать обновлённый README.md."
    fi

    temporary_file="$(mktemp)"

    if ! git show :README.md > "$temporary_file"; then
        fail "README.md отсутствует в индексе Git."
    fi

    readme_path="$temporary_file"
else
    readme_path="$source_path"

    if [[ ! -f "$readme_path" ]]; then
        fail "файл не найден: $readme_path."
    fi
fi

if ! grep -Fxq '## Дорожная карта' "$readme_path"; then
    fail "Отсутствует раздел «Дорожная карта»."
fi

if ! grep -Fxq '## История обновлений для посетителей' "$readme_path"; then
    fail "Отсутствует раздел «История обновлений для посетителей»."
fi

last_second_level_heading="$(grep -E '^## [^#]' "$readme_path" | tail -n 1 || true)"

if [[ "$last_second_level_heading" != '## История обновлений для посетителей' ]]; then
    fail "История обновлений для посетителей должна быть последним разделом второго уровня."
fi

line_number=0
fence_marker=""

while IFS= read -r line || [[ -n "$line" ]]; do
    ((line_number += 1))
    trimmed="${line#"${line%%[![:space:]]*}"}"

    if [[ "$trimmed" == '```'* ]]; then
        if [[ -z "$fence_marker" ]]; then
            fence_marker='```'
        elif [[ "$fence_marker" == '```' ]]; then
            fence_marker=""
        fi

        continue
    fi

    if [[ "$trimmed" == '~~~'* ]]; then
        if [[ -z "$fence_marker" ]]; then
            fence_marker='~~~'
        elif [[ "$fence_marker" == '~~~' ]]; then
            fence_marker=""
        fi

        continue
    fi

    if [[ -n "$fence_marker" || -z "$trimmed" || "$trimmed" == '<!--'* ]]; then
        continue
    fi

    if ! [[ "$trimmed" =~ [[:alnum:]] ]]; then
        continue
    fi

    if ! grep -Pq '\p{Cyrillic}' <<< "$trimmed"; then
        fail "Строка $line_number должна содержать русский текст."
    fi
done < "$readme_path"
