# Changelog

## 2026-07-09

- Documented deployment environment requirements, expanded non-secret `.env.example` defaults, and added a regression test that keeps `env()` calls inside config files.
- Improved the Vite frontend build by using one app entry, lazy-loading Plyr/HLS player assets, loading generated Vite fonts, and documenting frontend commands.
- Added a read-only catalog titles JSON API with explicit Laravel API Resources, eager-loaded relationships, pagination metadata, and sensitive-field regression tests.
- Added a lock-aware queued Seasonvar import job with explicit timeout, retries, backoff, uniqueness, failure logging, and aligned local queue worker settings.
- Added conservative web security headers for Laravel responses.
- Added a named rate limiter for the authenticated `/stats` diagnostics route.
- Disabled Laravel local temporary storage routes by default via `LOCAL_FILESYSTEM_SERVE=false`.
- Documented security rules for secrets, external URLs, Blade output, and dependency audits.
- Added regression tests for security headers, stats throttling, disabled storage routes, and private-host playlist URL blocking.
