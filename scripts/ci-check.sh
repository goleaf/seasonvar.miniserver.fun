#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

export APP_ENV="${APP_ENV:-testing}"
export APP_DEBUG="${APP_DEBUG:-false}"
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
export APP_URL="${APP_URL:-http://localhost}"
export BROADCAST_CONNECTION="${BROADCAST_CONNECTION:-null}"
export CACHE_STORE="${CACHE_STORE:-array}"
export COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-1}"
export MAIL_MAILER="${MAIL_MAILER:-array}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export SESSION_DRIVER="${SESSION_DRIVER:-array}"

ci_run_id="${SEASONVAR_CI_RUN_ID:-$$}"
ci_output_root="${SEASONVAR_CI_OUTPUT_ROOT:-$repo_root/output/ci/$ci_run_id}"
export APP_CONFIG_CACHE="${APP_CONFIG_CACHE:-$ci_output_root/config.php}"
export APP_EVENTS_CACHE="${APP_EVENTS_CACHE:-$ci_output_root/events.php}"
export APP_PACKAGES_CACHE="${APP_PACKAGES_CACHE:-$ci_output_root/packages.php}"
export APP_ROUTES_CACHE="${APP_ROUTES_CACHE:-$ci_output_root/routes.php}"
export APP_SERVICES_CACHE="${APP_SERVICES_CACHE:-$ci_output_root/services.php}"
export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-$ci_output_root/views}"

clear_laravel_cache_artifacts() {
    php artisan config:clear --no-interaction >/dev/null 2>&1 || true
    php artisan route:clear --no-interaction >/dev/null 2>&1 || true
    php artisan view:clear --no-interaction >/dev/null 2>&1 || true
    rm -f \
        "$APP_CONFIG_CACHE" \
        "$APP_EVENTS_CACHE" \
        "$APP_PACKAGES_CACHE" \
        "$APP_ROUTES_CACHE" \
        "$APP_SERVICES_CACHE"

    if [[ -d "$VIEW_COMPILED_PATH" ]]; then
        find "$VIEW_COMPILED_PATH" -maxdepth 1 -type f -delete
    fi
}

mkdir -p "$ci_output_root" "$VIEW_COMPILED_PATH"
clear_laravel_cache_artifacts

run_laravel_cache_validation() (
    mkdir -p "$ci_output_root" "$VIEW_COMPILED_PATH"
    trap clear_laravel_cache_artifacts EXIT
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
)

run_backend() (
    trap clear_laravel_cache_artifacts EXIT
    export DB_CONNECTION=sqlite
    export DB_DATABASE=:memory:
    composer validate --strict
    composer audit
    bash scripts/check-changelog-policy.sh CHANGELOG.md
    clear_laravel_cache_artifacts
    ./vendor/bin/pint --test --format=agent
    composer rector:check
    find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
    composer analyse
    php artisan project:docs-refresh --check --no-interaction
    run_laravel_cache_validation
    php artisan test
)

run_frontend() {
    npm audit --audit-level=high
    npm run build
}

run_browser() (
    trap clear_laravel_cache_artifacts EXIT
    local browser_database="${BROWSER_TEST_DATABASE:-$repo_root/output/playwright/$ci_run_id/browser.sqlite}"
    local browser_port="${PLAYWRIGHT_PORT:-$((18000 + ($$ % 20000)))}"

    if [[ "$browser_database" != /* ]]; then
        browser_database="$repo_root/$browser_database"
    fi

    export APP_URL="http://127.0.0.1:$browser_port"
    export CACHE_DOMAIN_STORE=array
    export CACHE_HOT_STORE=array
    export CACHE_LOCK_STORE=array
    export CACHE_METRICS_STORE=array
    export CACHE_VERSION_STORE=array
    export DB_CONNECTION=sqlite
    export DB_DATABASE="$browser_database"
    export BROWSER_TEST_DATABASE="$browser_database"
    export PLAYWRIGHT_PORT="$browser_port"
    export PLAYWRIGHT_RUNTIME_NAME="${PLAYWRIGHT_RUNTIME_NAME:-ci-$ci_run_id}"
    export PLAYBACK_ALLOWED_HOSTS="${PLAYBACK_ALLOWED_HOSTS:-media.example.com}"
    export PLAYBACK_ENFORCE_PUBLIC_DNS="${PLAYBACK_ENFORCE_PUBLIC_DNS:-false}"

    npm run build
    npm run test:browser:install
    php tests/browser/prepare-fixtures.php
    npm run test:browser
)

profile="${1:-full}"

case "$profile" in
    backend)
        run_backend
        ;;
    frontend)
        run_frontend
        ;;
    browser)
        run_browser
        ;;
    pre-push)
        run_backend
        run_frontend
        ;;
    full)
        run_backend
        run_frontend
        run_browser
        ;;
    *)
        echo "Неизвестный профиль проверки CI: $profile" >&2
        echo "Допустимые профили: backend, frontend, browser, pre-push, full." >&2
        exit 2
        ;;
esac
