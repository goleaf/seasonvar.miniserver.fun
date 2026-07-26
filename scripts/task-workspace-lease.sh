#!/usr/bin/env bash
set -euo pipefail

readonly lease_name="seasonvar-task-workspace-lease"

fail() {
    local message="$1"
    local exit_code="${2:-1}"

    echo "Seasonvar workspace lease: $message" >&2
    exit "$exit_code"
}

usage() {
    cat >&2 <<'USAGE'
Использование:
  task-workspace-lease.sh acquire <task-id>
  task-workspace-lease.sh status
  task-workspace-lease.sh verify-owner <task-id>
  task-workspace-lease.sh declare-paths <task-id>
  task-workspace-lease.sh verify-paths <task-id>
  task-workspace-lease.sh approve-index <task-id>
  task-workspace-lease.sh verify-index <task-id>
  task-workspace-lease.sh release <task-id>
  task-workspace-lease.sh recover <task-id>
USAGE
}

valid_task_id() {
    local task_id="$1"

    [[ ${#task_id} -le 64 && "$task_id" =~ ^[a-z0-9][a-z0-9._-]*$ ]]
}

valid_owner_pid() {
    local owner_pid="$1"

    [[ ${#owner_pid} -le 10 && "$owner_pid" =~ ^[1-9][0-9]*$ ]]
}

resolve_lease_paths() {
    local resolved_git_dir
    local resolved_lease_dir

    resolved_git_dir="$(git rev-parse --absolute-git-dir 2>/dev/null)" ||
        fail "команда должна выполняться внутри Git repository."
    resolved_lease_dir="$(git rev-parse --path-format=absolute --git-path "$lease_name" 2>/dev/null)" ||
        fail "не удалось определить repository-local lease path."

    git_dir="${resolved_git_dir%/}"
    lease_dir="${resolved_lease_dir%/}"
    metadata_file="$lease_dir/metadata"
    approved_index_file="$lease_dir/approved-index"
    declared_paths_file="$lease_dir/declared-paths"
    declared_paths_metadata_file="$lease_dir/declared-paths.meta"

    if [[ "$lease_dir" != "$git_dir/$lease_name" ]]; then
        fail "Git вернул неожиданный lease path; файлы не изменены."
    fi
}

require_valid_lease_storage() {
    resolve_lease_paths

    if [[ ! -d "$lease_dir" || -L "$lease_dir" ]]; then
        fail "активный lease отсутствует или имеет небезопасный тип."
    fi

    if [[ ! -f "$metadata_file" || -L "$metadata_file" ]]; then
        fail "lease metadata отсутствует или имеет небезопасный тип."
    fi
}

load_metadata() {
    local key
    local value
    local count=0
    local seen_task_id=""
    local seen_owner_pid=""
    local seen_acquired_at=""
    local seen_token_sha256=""

    lease_task_id=""
    lease_owner_pid=""
    lease_acquired_at=""
    lease_token_sha256=""

    while IFS='=' read -r key value || [[ -n "$key$value" ]]; do
        case "$key" in
            task_id)
                [[ -z "$seen_task_id" ]] || return 1
                seen_task_id="1"
                lease_task_id="$value"
                ;;
            owner_pid)
                [[ -z "$seen_owner_pid" ]] || return 1
                seen_owner_pid="1"
                lease_owner_pid="$value"
                ;;
            acquired_at)
                [[ -z "$seen_acquired_at" ]] || return 1
                seen_acquired_at="1"
                lease_acquired_at="$value"
                ;;
            token_sha256)
                [[ -z "$seen_token_sha256" ]] || return 1
                seen_token_sha256="1"
                lease_token_sha256="$value"
                ;;
            *)
                return 1
                ;;
        esac

        count=$((count + 1))
    done < "$metadata_file"

    [[ $count -eq 4 ]] || return 1
    valid_task_id "$lease_task_id" || return 1
    valid_owner_pid "$lease_owner_pid" || return 1
    [[ "$lease_acquired_at" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
    [[ "$lease_token_sha256" =~ ^[a-f0-9]{64}$ ]] || return 1
}

require_loaded_metadata() {
    require_valid_lease_storage
    load_metadata || fail "lease metadata повреждена; автоматическое удаление запрещено."
}

load_approved_index() {
    local key
    local value
    local count=0
    local seen_task_id=""
    local seen_approved_at=""
    local seen_index_sha256=""

    approval_task_id=""
    approval_approved_at=""
    approval_index_sha256=""

    while IFS='=' read -r key value || [[ -n "$key$value" ]]; do
        case "$key" in
            task_id)
                [[ -z "$seen_task_id" ]] || return 1
                seen_task_id="1"
                approval_task_id="$value"
                ;;
            approved_at)
                [[ -z "$seen_approved_at" ]] || return 1
                seen_approved_at="1"
                approval_approved_at="$value"
                ;;
            index_sha256)
                [[ -z "$seen_index_sha256" ]] || return 1
                seen_index_sha256="1"
                approval_index_sha256="$value"
                ;;
            *)
                return 1
                ;;
        esac

        count=$((count + 1))
    done < "$approved_index_file"

    [[ $count -eq 3 ]] || return 1
    valid_task_id "$approval_task_id" || return 1
    [[ "$approval_approved_at" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
    [[ "$approval_index_sha256" =~ ^[a-f0-9]{64}$ ]] || return 1
}

require_loaded_approved_index() {
    if [[ ! -f "$approved_index_file" || -L "$approved_index_file" ]]; then
        fail "reviewed index approval отсутствует или имеет небезопасный тип."
    fi

    load_approved_index ||
        fail "reviewed index approval повреждён; автоматическое удаление запрещено."
    [[ "$approval_task_id" == "$lease_task_id" ]] ||
        fail "reviewed index approval принадлежит другой задаче."
}

require_valid_optional_approval() {
    if [[ -e "$approved_index_file" || -L "$approved_index_file" ]]; then
        require_loaded_approved_index
    fi
}

load_declared_paths_metadata() {
    local key
    local value
    local count=0
    local seen_task_id=""
    local seen_declared_at=""
    local seen_paths_sha256=""

    declaration_task_id=""
    declaration_declared_at=""
    declaration_paths_sha256=""

    while IFS='=' read -r key value || [[ -n "$key$value" ]]; do
        case "$key" in
            task_id)
                [[ -z "$seen_task_id" ]] || return 1
                seen_task_id="1"
                declaration_task_id="$value"
                ;;
            declared_at)
                [[ -z "$seen_declared_at" ]] || return 1
                seen_declared_at="1"
                declaration_declared_at="$value"
                ;;
            paths_sha256)
                [[ -z "$seen_paths_sha256" ]] || return 1
                seen_paths_sha256="1"
                declaration_paths_sha256="$value"
                ;;
            *)
                return 1
                ;;
        esac

        count=$((count + 1))
    done < "$declared_paths_metadata_file"

    [[ $count -eq 3 ]] || return 1
    valid_task_id "$declaration_task_id" || return 1
    [[ "$declaration_declared_at" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
    [[ "$declaration_paths_sha256" =~ ^[a-f0-9]{64}$ ]] || return 1
}

validate_declared_paths_file() {
    local path_file="$1"

    php -r '
        $contents = file_get_contents($argv[1]);

        if ($contents === false || $contents === "" || ! str_ends_with($contents, "\0")) {
            exit(1);
        }

        $paths = explode("\0", substr($contents, 0, -1));
        sort($paths, SORT_STRING);
        $previous = null;

        foreach ($paths as $path) {
            if ($path === "" || str_starts_with($path, "/")) {
                exit(1);
            }

            $components = explode("/", $path);

            if ($components[0] === ".git") {
                exit(1);
            }

            foreach ($components as $component) {
                if ($component === "." || $component === "..") {
                    exit(1);
                }
            }

            if ($previous !== null && $previous === $path) {
                exit(1);
            }

            $previous = $path;
        }
    ' "$path_file"
}

declared_paths_digest() {
    local path_file="$1"

    php -r '
        $digest = hash_file("sha256", $argv[1]);

        if ($digest === false) {
            exit(1);
        }

        echo $digest;
    ' "$path_file"
}

require_loaded_declared_paths() {
    local actual_digest

    if [[ ! -f "$declared_paths_file" || -L "$declared_paths_file" ||
        ! -f "$declared_paths_metadata_file" || -L "$declared_paths_metadata_file" ]]; then
        fail "declared path manifest отсутствует или имеет небезопасный тип."
    fi

    [[ "$(stat -c '%a' -- "$declared_paths_file")" == "600" &&
        "$(stat -c '%a' -- "$declared_paths_metadata_file")" == "600" ]] ||
        fail "declared path manifest имеет небезопасные permissions."

    load_declared_paths_metadata ||
        fail "declared path metadata повреждена; automatic deletion запрещён."
    [[ "$declaration_task_id" == "$lease_task_id" ]] ||
        fail "declared path manifest принадлежит другой задаче."
    validate_declared_paths_file "$declared_paths_file" ||
        fail "declared path manifest повреждён."

    if ! cmp -s -- "$declared_paths_file" <(LC_ALL=C sort -zu -- "$declared_paths_file"); then
        fail "declared path manifest не canonical."
    fi

    actual_digest="$(declared_paths_digest "$declared_paths_file")" ||
        fail "не удалось вычислить declared path digest."
    [[ "$actual_digest" == "$declaration_paths_sha256" ]] ||
        fail "declared path manifest не совпадает с metadata."
}

require_valid_optional_declared_paths() {
    if [[ -e "$declared_paths_file" || -L "$declared_paths_file" ||
        -e "$declared_paths_metadata_file" || -L "$declared_paths_metadata_file" ]]; then
        require_loaded_declared_paths
    fi
}

token_digest() {
    php -r 'echo hash("sha256", stream_get_contents(STDIN));'
}

index_digest() {
    git ls-files --stage -z -- | token_digest
}

require_matching_owner() {
    local task_id="$1"
    local action="$2"
    local raw_token="${SEASONVAR_TASK_LEASE_TOKEN:-}"
    local digest

    valid_task_id "$task_id" ||
        fail "указан недопустимый task-id." 2
    [[ "$raw_token" =~ ^[a-f0-9]{64}$ ]] ||
        fail "$action требует SEASONVAR_TASK_LEASE_TOKEN из успешного acquire."

    require_loaded_metadata

    [[ "$lease_task_id" == "$task_id" ]] ||
        fail "lease принадлежит другой задаче."

    digest="$(printf '%s' "$raw_token" | token_digest)"
    [[ "$digest" == "$lease_token_sha256" ]] ||
        fail "lease token не совпадает; workspace не изменён."
}

require_reviewable_index() {
    local diff_status

    if git diff --cached --quiet --; then
        fail "пустой staged index нельзя одобрить."
    else
        diff_status=$?
        [[ $diff_status -eq 1 ]] ||
            fail "не удалось безопасно проверить staged index."
    fi

    [[ -z "$(git ls-files --unmerged)" ]] ||
        fail "reviewed index approval запрещён при unresolved conflicts."
}

declared_paths_match_index() {
    git diff --cached --name-only -z --no-renames -- >/dev/null ||
        fail "не удалось безопасно получить staged path set."

    cmp -s -- "$declared_paths_file" <(
        git diff --cached --name-only -z --no-renames -- |
            LC_ALL=C sort -zu
    )
}

require_declared_paths_match_index() {
    require_loaded_declared_paths
    declared_paths_match_index ||
        fail "staged path set отличается от declared task paths."
}

remove_exact_lease() {
    local entry
    local -a entries=()
    local -a unexpected_entries=()

    resolve_lease_paths

    [[ -d "$lease_dir" && ! -L "$lease_dir" ]] ||
        fail "exact lease directory больше не существует."
    [[ -f "$metadata_file" && ! -L "$metadata_file" ]] ||
        fail "exact lease metadata больше не существует."

    while IFS= read -r -d '' entry; do
        entries+=("$entry")
    done < <(find -P "$lease_dir" -mindepth 1 -maxdepth 1 -print0)

    for entry in "${entries[@]}"; do
        case "$entry" in
            "$metadata_file")
                [[ -f "$entry" && ! -L "$entry" ]] ||
                    unexpected_entries+=("$entry")
                ;;
            "$approved_index_file")
                [[ -f "$entry" && ! -L "$entry" ]] ||
                    unexpected_entries+=("$entry")
                ;;
            "$declared_paths_file" | "$declared_paths_metadata_file")
                [[ -f "$entry" && ! -L "$entry" ]] ||
                    unexpected_entries+=("$entry")
                ;;
            *)
                unexpected_entries+=("$entry")
                ;;
        esac
    done

    if (( ${#unexpected_entries[@]} > 0 || ${#entries[@]} < 1 || ${#entries[@]} > 4 )); then
        fail "lease directory содержит неожиданные файлы; automatic deletion запрещён."
    fi

    if [[ -e "$approved_index_file" || -L "$approved_index_file" ]]; then
        unlink -- "$approved_index_file" ||
            fail "не удалось удалить exact reviewed index approval."
    fi

    if [[ -e "$declared_paths_metadata_file" || -L "$declared_paths_metadata_file" ]]; then
        unlink -- "$declared_paths_metadata_file" ||
            fail "не удалось удалить exact declared path metadata."
    fi

    if [[ -e "$declared_paths_file" || -L "$declared_paths_file" ]]; then
        unlink -- "$declared_paths_file" ||
            fail "не удалось удалить exact declared path manifest."
    fi

    unlink -- "$metadata_file" ||
        fail "не удалось удалить exact lease metadata."

    if ! rmdir -- "$lease_dir"; then
        if [[ -d "$lease_dir" && ! -e "$metadata_file" ]]; then
            umask 077
            printf 'task_id=%s\nowner_pid=%s\nacquired_at=%s\ntoken_sha256=%s\n' \
                "$lease_task_id" \
                "$lease_owner_pid" \
                "$lease_acquired_at" \
                "$lease_token_sha256" > "$metadata_file"
            chmod 600 "$metadata_file"
        fi

        fail "lease directory изменился во время release; metadata сохранена, recursive deletion запрещён."
    fi
}

acquire_lease() {
    local task_id="$1"
    local owner_pid="${SEASONVAR_TASK_LEASE_OWNER_PID:-$PPID}"
    local acquired_at
    local raw_token
    local digest
    local metadata_temp

    valid_task_id "$task_id" ||
        fail "task-id должен начинаться со строчной буквы или цифры и содержать не более 64 символов [a-z0-9._-]." 2
    valid_owner_pid "$owner_pid" ||
        fail "SEASONVAR_TASK_LEASE_OWNER_PID должен быть положительным process ID." 2

    resolve_lease_paths
    umask 077

    if ! mkdir -- "$lease_dir" 2>/dev/null; then
        fail "workspace уже принадлежит другой задаче; используйте status."
    fi

    metadata_temp="$lease_dir/metadata.$$"

    cleanup_incomplete_acquire() {
        if [[ -f "$metadata_temp" && ! -L "$metadata_temp" ]]; then
            unlink -- "$metadata_temp" 2>/dev/null || true
        fi

        if [[ -d "$lease_dir" && ! -L "$lease_dir" ]]; then
            rmdir -- "$lease_dir" 2>/dev/null || true
        fi
    }

    trap cleanup_incomplete_acquire EXIT HUP INT TERM

    raw_token="$(php -r 'echo bin2hex(random_bytes(32));')"
    [[ "$raw_token" =~ ^[a-f0-9]{64}$ ]] ||
        fail "не удалось создать безопасный lease token."

    digest="$(printf '%s' "$raw_token" | token_digest)"
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] ||
        fail "не удалось вычислить token digest."

    acquired_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

    printf 'task_id=%s\nowner_pid=%s\nacquired_at=%s\ntoken_sha256=%s\n' \
        "$task_id" \
        "$owner_pid" \
        "$acquired_at" \
        "$digest" > "$metadata_temp"
    chmod 600 "$metadata_temp"
    mv -- "$metadata_temp" "$metadata_file"

    trap - EXIT HUP INT TERM

    printf 'task_id=%s\n' "$task_id"
    printf 'SEASONVAR_TASK_LEASE_TOKEN=%s\n' "$raw_token"
}

declare_paths() {
    local task_id="$1"
    local declared_at
    local digest
    local input_temp=""
    local manifest_temp=""
    local metadata_temp=""

    require_matching_owner "$task_id" "declare-paths"
    umask 077

    cleanup_incomplete_declaration() {
        local temporary_path

        for temporary_path in "$input_temp" "$manifest_temp" "$metadata_temp"; do
            if [[ -n "$temporary_path" && -f "$temporary_path" && ! -L "$temporary_path" ]]; then
                unlink -- "$temporary_path" 2>/dev/null || true
            fi
        done
    }

    trap cleanup_incomplete_declaration EXIT HUP INT TERM

    input_temp="$(mktemp -- "$lease_dir/declared-paths.input.XXXXXX")" ||
        fail "не удалось создать безопасный temporary input file."
    manifest_temp="$(mktemp -- "$lease_dir/declared-paths.manifest.XXXXXX")" ||
        fail "не удалось создать безопасный temporary manifest file."
    metadata_temp="$(mktemp -- "$lease_dir/declared-paths.meta.XXXXXX")" ||
        fail "не удалось создать безопасный temporary metadata file."

    chmod 600 "$input_temp" "$manifest_temp" "$metadata_temp"
    cat > "$input_temp"
    validate_declared_paths_file "$input_temp" ||
        fail "path declaration должна быть непустым безопасным NUL-delimited списком."

    LC_ALL=C sort -zu -- "$input_temp" > "$manifest_temp"
    validate_declared_paths_file "$manifest_temp" ||
        fail "не удалось canonicalize declared path manifest."

    digest="$(declared_paths_digest "$manifest_temp")" ||
        fail "не удалось вычислить declared path digest."
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] ||
        fail "не удалось вычислить declared path digest."

    declared_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    printf 'task_id=%s\ndeclared_at=%s\npaths_sha256=%s\n' \
        "$task_id" \
        "$declared_at" \
        "$digest" > "$metadata_temp"

    require_valid_optional_declared_paths
    require_valid_optional_approval

    mv -- "$manifest_temp" "$declared_paths_file"
    mv -- "$metadata_temp" "$declared_paths_metadata_file"

    if [[ -e "$approved_index_file" || -L "$approved_index_file" ]]; then
        unlink -- "$approved_index_file" ||
            fail "не удалось инвалидировать exact reviewed index approval."
    fi

    unlink -- "$input_temp"
    trap - EXIT HUP INT TERM

    printf 'declared_task_id=%s\n' "$task_id"
}

show_status() {
    local index_approved="no"
    local paths_declared="no"
    local current_digest
    local diff_status

    require_loaded_metadata
    require_valid_optional_declared_paths

    if [[ -e "$declared_paths_file" && -e "$declared_paths_metadata_file" ]]; then
        paths_declared="yes"
    fi

    if [[ -e "$approved_index_file" || -L "$approved_index_file" ]]; then
        require_loaded_approved_index

        if git diff --cached --quiet --; then
            diff_status=0
        else
            diff_status=$?
        fi

        if [[ $diff_status -eq 1 && -z "$(git ls-files --unmerged)" &&
            "$paths_declared" == "yes" ]] &&
            declared_paths_match_index; then
            current_digest="$(index_digest)"

            if [[ "$current_digest" == "$approval_index_sha256" ]]; then
                index_approved="yes"
            fi
        elif [[ $diff_status -gt 1 ]]; then
            fail "не удалось безопасно проверить staged index."
        fi
    fi

    printf 'task_id=%s\n' "$lease_task_id"
    printf 'owner_pid=%s\n' "$lease_owner_pid"
    printf 'acquired_at=%s\n' "$lease_acquired_at"
    printf 'paths_declared=%s\n' "$paths_declared"
    printf 'index_approved=%s\n' "$index_approved"
}

verify_paths() {
    local task_id="$1"

    require_matching_owner "$task_id" "verify-paths"
    require_declared_paths_match_index

    printf 'verified_paths_task_id=%s\n' "$task_id"
}

verify_owner() {
    local task_id="$1"

    require_matching_owner "$task_id" "verify-owner"

    printf 'verified_owner_task_id=%s\n' "$task_id"
}

approve_index() {
    local task_id="$1"
    local approved_at
    local digest
    local approval_temp

    require_matching_owner "$task_id" "approve-index"
    require_declared_paths_match_index
    require_reviewable_index
    require_valid_optional_approval

    digest="$(index_digest)"
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] ||
        fail "не удалось вычислить reviewed index digest."
    approved_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

    umask 077
    approval_temp="$(mktemp -- "$lease_dir/approved-index.XXXXXX")" ||
        fail "не удалось создать безопасный temporary approval file."

    cleanup_incomplete_approval() {
        if [[ -f "$approval_temp" && ! -L "$approval_temp" ]]; then
            unlink -- "$approval_temp" 2>/dev/null || true
        fi
    }

    trap cleanup_incomplete_approval EXIT HUP INT TERM

    printf 'task_id=%s\napproved_at=%s\nindex_sha256=%s\n' \
        "$task_id" \
        "$approved_at" \
        "$digest" > "$approval_temp"
    chmod 600 "$approval_temp"
    mv -- "$approval_temp" "$approved_index_file"

    trap - EXIT HUP INT TERM

    printf 'approved_task_id=%s\n' "$task_id"
}

verify_index() {
    local task_id="$1"
    local current_digest

    require_matching_owner "$task_id" "verify-index"
    require_loaded_approved_index
    require_declared_paths_match_index
    require_reviewable_index

    current_digest="$(index_digest)"
    [[ "$current_digest" == "$approval_index_sha256" ]] ||
        fail "staged index отличается от reviewed approval; требуется повторное одобрение."

    printf 'verified_task_id=%s\n' "$task_id"
}

release_lease() {
    local task_id="$1"
    local raw_token="${SEASONVAR_TASK_LEASE_TOKEN:-}"
    local digest

    valid_task_id "$task_id" ||
        fail "указан недопустимый task-id." 2
    [[ "$raw_token" =~ ^[a-f0-9]{64}$ ]] ||
        fail "release требует SEASONVAR_TASK_LEASE_TOKEN из успешного acquire."

    require_loaded_metadata

    [[ "$lease_task_id" == "$task_id" ]] ||
        fail "lease принадлежит другой задаче."

    digest="$(printf '%s' "$raw_token" | token_digest)"
    [[ "$digest" == "$lease_token_sha256" ]] ||
        fail "lease token не совпадает; workspace не изменён."

    require_valid_optional_approval
    require_valid_optional_declared_paths
    remove_exact_lease
    printf 'released_task_id=%s\n' "$task_id"
}

recover_stale_lease() {
    local task_id="$1"

    valid_task_id "$task_id" ||
        fail "указан недопустимый task-id." 2

    require_loaded_metadata

    [[ "$lease_task_id" == "$task_id" ]] ||
        fail "stale recovery разрешён только для exact task-id активного lease."

    if kill -0 "$lease_owner_pid" 2>/dev/null || [[ -d "/proc/$lease_owner_pid" ]]; then
        fail "owner PID $lease_owner_pid активен; stale recovery запрещён."
    fi

    require_valid_optional_approval
    require_valid_optional_declared_paths
    remove_exact_lease
    printf 'recovered_task_id=%s\n' "$task_id"
}

command="${1:-}"

case "$command" in
    acquire)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        acquire_lease "$2"
        ;;
    status)
        [[ $# -eq 1 ]] || {
            usage
            exit 2
        }
        show_status
        ;;
    verify-owner)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        verify_owner "$2"
        ;;
    declare-paths)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        declare_paths "$2"
        ;;
    verify-paths)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        verify_paths "$2"
        ;;
    approve-index)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        approve_index "$2"
        ;;
    verify-index)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        verify_index "$2"
        ;;
    release)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        release_lease "$2"
        ;;
    recover)
        [[ $# -eq 2 ]] || {
            usage
            exit 2
        }
        recover_stale_lease "$2"
        ;;
    *)
        usage
        exit 2
        ;;
esac
