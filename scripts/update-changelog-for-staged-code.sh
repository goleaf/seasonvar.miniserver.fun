#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "Автообновление CHANGELOG: $1" >&2
    exit 1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || fail "не удалось определить корень Git repository."

cd "$repo_root"

git ls-files --error-unmatch CHANGELOG.md >/dev/null 2>&1 \
    || fail "CHANGELOG.md не отслеживается Git."

if ! git diff --cached --quiet -- CHANGELOG.md; then
    exit 0
fi

if ! git diff --quiet -- CHANGELOG.md; then
    fail "CHANGELOG.md содержит изменения вне индекса."
fi

changelog_date="${SEASONVAR_CHANGELOG_DATE:-$(TZ=Europe/Vilnius date +%F)}"
paths_file="$(mktemp)"

cleanup() {
    rm -f -- "$paths_file"
}

trap cleanup EXIT

git diff --cached --name-only --diff-filter=ACMRD -z -- > "$paths_file"

if [[ ! -s "$paths_file" ]]; then
    exit 0
fi

php "$script_dir/update-changelog-for-staged-code.php" \
    "$repo_root/CHANGELOG.md" \
    "$changelog_date" \
    < "$paths_file"

if ! git diff --quiet -- CHANGELOG.md; then
    git add -- CHANGELOG.md
    echo "Автообновление CHANGELOG: добавлена русская датированная запись."
fi
