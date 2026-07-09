# Changelog

## 2026-07-09

- Added an optional queued email notification for failed queued Seasonvar imports, with safe env configuration, dispatch/content tests, and notification documentation.
- Added a private upload storage foundation with explicit image validation rules, generated filenames, fake-storage tests, and storage documentation.
- Improved catalog search form UX with reusable Blade form components, visible validation errors, and preserved old input after validation redirects.
- Cleaned up Eloquent relationship inverses for catalog source pages and Seasonvar import runs, added schema-aligned casts, and documented model query rules in `docs/models.md`.
- Synchronized Markdown documentation with the current Laravel 13 routes, setup, MCP, deployment, testing, API, Blade, and CI conventions.
- Added a GitHub Actions CI workflow for Composer, Pint, Laravel tests, PHP syntax linting, npm audit/build, and dependency audits.
- Documented deployment environment requirements, expanded non-secret `.env.example` defaults, and added a regression test that keeps `env()` calls inside config files.
- Improved the Vite frontend build by using one app entry, lazy-loading Plyr/HLS player assets, loading generated Vite fonts, and documenting frontend commands.
- Added a read-only catalog titles JSON API with explicit Laravel API Resources, eager-loaded relationships, pagination metadata, and sensitive-field regression tests.
- Added a lock-aware queued Seasonvar import job with explicit timeout, retries, backoff, uniqueness, failure logging, and aligned local queue worker settings.
- Added conservative web security headers for Laravel responses.
- Added a named rate limiter for the public read-only `/stats` diagnostics route.
- Disabled Laravel local temporary storage routes by default via `LOCAL_FILESYSTEM_SERVE=false`.
- Documented security rules for secrets, external URLs, Blade output, and dependency audits.
- Added regression tests for security headers, stats throttling, disabled storage routes, and private-host playlist URL blocking.
