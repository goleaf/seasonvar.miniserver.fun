#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd "$script_dir/.." && pwd -P)"
target="${1:-$repo_root/docs/plans/current-task-plan.md}"

if [[ "$target" != /* ]]; then
    target="$repo_root/$target"
fi

exec php "$script_dir/check-current-plan-policy.php" "$target"
