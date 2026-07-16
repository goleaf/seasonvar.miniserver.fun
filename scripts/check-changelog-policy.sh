#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
target="${1:-CHANGELOG.md}"

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

php "$script_dir/check-changelog-policy.php" "$target"
