# Project Instructions

## Project Overview

This is a Laravel 13 catalog application for Seasonvar projects: series, shows, anime, documentary pages, seasons, episodes, catalog relations, reviews, sitemap/SEO endpoints, and external video links.

Current stack and conventions:

- PHP 8.5 runtime, Laravel 13.19, Laravel Boost 2.4, Laravel Pint 1.29, PHPUnit 12.5.
- Tailwind CSS 4.3 with Vite 8; local FontAwesome, Plyr, and HLS assets are installed through npm.
- SQLite is used locally and in tests; PHPUnit uses an in-memory SQLite database.
- Web routes live in `routes/web.php`; there is no `routes/api.php` at this time.
- There are currently no `app/Http/Requests`, `app/Policies`, `app/Http/Resources`, `app/Jobs`, or `routes/api.php` files.
- Main public import command: `php artisan seasonvar:import`.
- Project documentation is in `README.md` and `docs/*.md`; keep it project-specific, not Laravel skeleton text.

Before changing code, inspect nearby files and existing patterns first. Prefer the structure already present in `app/Http/Controllers`, `app/Models`, `app/Services`, `app/Console/Commands`, `resources/views`, `database/migrations`, and `tests`.

## Laravel Conventions

- Use Laravel 13 APIs and verify version-sensitive behavior with Laravel Boost docs before changing Laravel code.
- Follow framework conventions for naming, route names, model names, relationships, casts, factories, and tests.
- Keep route model binding where it already exists, for example `CatalogTitle` by `slug`.
- Keep visible UI text in Russian.
- Do not add advertising, marketing copy, placeholder copy, or fake public-facing content.

## Architecture Rules

- Keep controllers thin: request parsing, validation handoff, authorization handoff, query/service orchestration, and response selection only.
- Put business logic in services, actions, jobs, or domain classes where appropriate. Prefer the current services before adding a new pattern.
- Follow the current service boundaries under `app/Services/Seasonvar`, `app/Services/Media`, and `app/Services/Crawler`.
- Move parsing, import, media, sitemap, crawling, and expensive query-building logic out of controllers when it grows.
- Keep `php artisan seasonvar:import` as the single public Seasonvar import command.
- The importer must keep seasons and episodes inside one `CatalogTitle`; do not create separate catalog titles for individual seasons.
- Video files are never downloaded to this application. Store external URLs, quality, format, translation, and availability state.

## Database And Migration Rules

- Use Eloquent relationships and explicit pivot tables already present for catalog metadata.
- Do not introduce polymorphic relations for catalog metadata unless the user explicitly asks for a redesign.
- Add indexes for new query patterns involving filters, joins, ordered lists, queues, or large imports.
- Use transactions for multi-table catalog writes.
- Use bulk operations such as `upsert`, `chunkById`, and grouped queries for importer work.
- Migrations must be additive and reversible where practical. Do not edit old migrations after they may have been run, unless this repo clearly treats them as unreleased.
- Do not use seeders for production catalog data.

## Validation And FormRequest Rules

- Normalize and validate all request input before applying filters, search, year ranges, route parameters, or write operations.
- Current catalog routes mostly use read-only controller validation. If adding write endpoints or non-trivial validation, create FormRequest classes under `app/Http/Requests`.
- Put authorization in FormRequest `authorize()` or policies for write operations.
- Use `$request->validated()` for mass assignment; do not use `$request->all()` for writes.
- Keep validation messages and user-facing errors in Russian when shown in the UI.

## Authorization Rules

- There are no application-specific policies in the current codebase. Add policies or gates before adding authenticated write, admin, import-control, or moderation endpoints.
- Never rely on hidden UI controls as authorization.
- Keep `/api/*` behavior JSON-friendly as configured in `bootstrap/app.php`.

## API Resource Rules

- There is no API route file or JSON resource layer currently.
- If an API is added, use `routes/api.php`.
- When returning Eloquent models or collections from an API, use Laravel API Resources under `app/Http/Resources`.
- Include pagination metadata for paginated API responses and keep response shapes explicit.
- Do not expose internal importer state, source HTML snapshots, raw remote URLs, secrets, or stack traces through API resources.

## Testing Rules

- Use PHPUnit classes under `tests/Feature` and `tests/Unit`; Pest is not installed.
- Prefer focused tests near the changed behavior before broad suite runs.
- Use factories and Laravel HTTP/console test helpers.
- Use `RefreshDatabase` as existing tests do unless there is a clear reason to change the test base pattern.
- Fake outbound HTTP with `Http::fake()` / `Http::preventStrayRequests()` for importer, crawler, playlist, and media-check tests.
- Tests should cover changed behavior, especially routes, commands, parsing, sitemap/robots, media import, and database behavior.

## Code Style Rules

- Run Pint for PHP changes: `./vendor/bin/pint --dirty --format agent`.
- Use typed method signatures, return types, and relationship PHPDoc generics as the existing models do.
- Use Laravel helpers and collections where they improve clarity.
- Keep comments rare and useful; prefer descriptive names over explanatory comments.
- Do not run database queries from Blade views.
- Use existing Blade components from `resources/views/components` before adding duplicate markup.
- For frontend changes, follow `docs/UI_STANDARDS.md`: light theme, Russian UI text, local icons/assets, readable mobile layouts.

## Security Rules

- Ask before adding production dependencies.
- Do not edit `.env` directly unless the user explicitly requests it. Update `.env.example` or config files when appropriate.
- Do not commit secrets, tokens, cookies, source credentials, raw private logs, or downloaded video files.
- Access environment values through config files; application code should use `config()`, not direct `env()` calls outside config.
- Validate and normalize all external URLs. Seasonvar source URLs must stay under `https://seasonvar.ru/`.
- Escape Blade output with `{{ }}` unless content is trusted and intentionally sanitized.
- Use Laravel HTTP client timeouts/retries for outbound requests; do not create unbounded remote calls.

## Performance Rules

- Avoid N+1 queries; eager load relationships used by Blade or serialization.
- Do not execute database queries in Blade views.
- Use `withCount()` or aggregate queries for counters.
- For large datasets, use `chunkById`, cursors, lazy collections, and bulk writes.
- Keep sitemap and feed responses streamed when they can grow with catalog size.
- Do not parse unchanged source pages when hashes/status allow a safe skip.
- Keep importer batch sizes and crawl delays conservative so local and remote servers are not overloaded.

## Commands To Run Before Finishing

Run the narrowest relevant commands for the change. Use the exact commands that apply:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SpecificTestName
php artisan test
./vendor/bin/phpunit
npm run build
```

Notes:

- Run Pint after PHP changes.
- Run the focused test filter first when a relevant test exists; run the full suite for broad or risky changes.
- `./vendor/bin/phpunit` is available because PHPUnit is installed; `php artisan test` is usually preferred.
- Run `npm run build` when frontend assets, Blade markup with asset assumptions, Vite config, Tailwind classes, or JS/CSS files change.
- Do not run fake commands: Pest and `npm run lint` are not installed in this project.

## Forbidden Actions

- Do not run destructive commands such as `migrate:fresh`, `db:wipe`, `queue:clear`, `cache:clear`, or broad data-deleting commands on production-like environments unless explicitly approved.
- Do not run `git reset --hard`, destructive checkout, or delete user changes unless explicitly requested.
- Do not replace project documentation with generic Laravel template text.

<!-- project-docs:start -->
## Автоматизация документации

- Команда `php artisan project:docs-refresh` поддерживает управляемые блоки документации в актуальном состоянии.
- Git-хук должен работать через `core.hooksPath=.githooks`, не должен коммитить посторонние изменения вне управляемых файлов документации и должен отправлять текущую ветку в Git только при `SEASONVAR_DOCS_AUTO_PUSH=1`.
- Карта сайта и `robots.txt` считаются частью технической документации проекта и должны отражаться в `README.md`, `docs/CODE_STANDARDS.md`, `docs/DATA_RELATIONS.md` и журнале обслуживания.
<!-- project-docs:end -->
