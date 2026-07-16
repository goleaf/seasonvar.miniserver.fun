# Multilingual Home Integration Implementation Plan

> Execute inline on the existing `main` branch as explicitly required by project instructions. Do not create a worktree, feature branch, subagent, production dependency, migration, or a second translation system.

**Goal:** Fully integrate Task 01 home page behavior into the existing `ru`/`en` Laravel localization architecture, including locale persistence, presentation copy, dates, numbers, SEO, sitemap, cache separation, accessibility, and documentation.

**Architecture:** Extend the current PHP language catalogs, `ApplyAccountPreferences`, `AccountSettingsService`, localized route aliases, page builder, SEO builder, date formatter, cache services and Blade components. Preserve current database translations for collections/tags and stable provider metadata for titles/audio/studios.

**Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3, SQLite, Blade, Tailwind CSS 4.3, Vite 8.

**Verification constraint:** The user explicitly requires a complete static translation audit without creating or running new automated tests. Use PHP syntax/static scripts, route inspection, Pint, Vite build, docs refresh check and diff review only.

## Task 1: Locale lifecycle and localized home route

**Files:**

- Modify: `app/Http/Middleware/ApplyAccountPreferences.php`
- Create: `app/Http/Requests/SwitchInterfaceLocaleRequest.php`
- Create: `app/Http/Controllers/SwitchInterfaceLocaleController.php`
- Modify: `app/Services/Auth/AccountSettingsService.php`
- Modify: `routes/web.php`
- Modify: `app/Support/Cache/PublicPageCachePolicy.php`

**Steps:**

1. Make route/session/user/default precedence explicit and validate every locale against the configured allowlist.
2. Add a CSRF-protected POST switch endpoint with a Form Request and thin controller.
3. Persist session locale and update the authenticated user preference through the existing settings service.
4. Add named `/{locale}` homepage aliases while preserving `/` as the existing default route.
5. Permit the validated locale parameter in homepage public-page cache matching.
6. Inspect the route table and request flow statically.

## Task 2: Global locale switcher and localized shared layout

**Files:**

- Create: `app/Services/Localization/LocalizedRouteResolver.php`
- Modify: `app/View/ViewData/AppLayoutData.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/components/layout/site-header.blade.php`
- Modify: `resources/views/components/layout/site-footer.blade.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`

**Steps:**

1. Build safe current-page locale targets with named routes where available and same-origin stable paths otherwise; strip Livewire/internal parameters.
2. Expose one shared ru/en switcher through existing layout data, using POST forms, text labels, `aria-current`, keyboard-native controls and no flags.
3. Translate every homepage-visible shared layout label, search control, navigation item, footer item and accessibility label.
4. Keep existing technical-issue additions in dirty files intact.

## Task 3: Home translation catalogs and SSR presentation

**Files:**

- Create: `lang/ru/home.php`
- Create: `lang/en/home.php`
- Modify: `resources/views/catalog/index.blade.php`
- Modify: `resources/views/components/catalog/latest-media-card.blade.php`
- Modify: `resources/views/components/catalog/title-card-list.blade.php`
- Modify: `app/View/Components/Catalog/LatestMediaCard.php`
- Modify: `app/View/Components/Catalog/TitleCard.php`
- Modify/Create component backing class for `resources/views/components/stat.blade.php`

**Steps:**

1. Add semantic, structurally identical `home.*` keys for sections, navigation, updates, catalogue statistics, empty/loading/error/end states, accessibility and SEO.
2. Preserve identical named placeholders and compatible ru/en plural variants.
3. Replace all hardcoded homepage-visible copy and accessibility labels with translation lookups.
4. Keep stable update/filter/recommendation identifiers in PHP and translate only their labels.
5. Format displayed counts with Laravel `Number` using the active locale and use `trans_choice` for nouns.

## Task 4: Locale-aware dates and homepage query data

**Files:**

- Modify: `app/Services/Auth/AccountDateTimeFormatter.php`
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Modify: `app/View/ViewModels/CatalogCollectionCardViewModel.php`
- Modify: `app/View/Components/Collections/CollectionCard.php`

**Steps:**

1. Add reusable date-only/date-group formatting based on `IntlDateFormatter`, active locale and resolved account timezone.
2. Use translated today/yesterday labels and locale-aware absolute dates without hardcoded `d.m.Y` in home UI.
3. Remove any presentation sentence building from the page builder; return stable data.
4. Preserve current eager-loading/grouped-query boundaries and avoid loading additional language rows.

## Task 5: Localized home SEO, sitemap and cache warming

**Files:**

- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Modify: `app/Services/Catalog/CatalogSitemapResponder.php`
- Modify: `app/Services/Catalog/CatalogCacheWarmer.php`
- Modify: `lang/ru/home.php`
- Modify: `lang/en/home.php`

**Steps:**

1. Generate home title, descriptions, Open Graph/Twitter fields, structured data and accessibility text from locale catalogs.
2. Generate canonical and alternates only from existing named routes; add ru/en `hreflang` plus x-default.
3. Add localized homepage entries to the sitemap without changing title slug strategy.
4. Warm locale-sensitive public home variants for every supported locale and restore the original application locale.
5. Preserve version-based invalidation so changed source content invalidates all locale variants.
6. Include the active/fallback public translation catalogs in a deterministic homepage HTML cache fingerprint so code translation edits cannot reuse stale markup.

## Task 6: Documentation and changelog

**Files:**

- Modify: `docs/README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/caching.md`
- Modify: `docs/administration.md`
- Modify: `docs/development.md`
- Modify: `docs/plans/laravel-video-portal-modernization.md`
- Modify: `CHANGELOG.md`

**Steps:**

1. Assign localization topic ownership in the documentation map without adding a competing guide.
2. Document supported/default/fallback locale, selection priority, routes, storage, key convention, content fallback, switcher, Livewire lifecycle, date/number/plural behavior and interface-vs-audio terminology.
3. Document locale cache dimensions, warming and invalidation.
4. Document admin/editorial responsibility and translator placeholder/parity workflow.
5. Add an English changelog entry for the multilingual home integration.
6. Update the living plan Task 01 status and link this audit/implementation plan.

## Task 7: Complete static audit and handoff

**Steps:**

1. Run PHP syntax checks on all changed PHP and language files.
2. Run a static recursive ru/en key and named-placeholder parity check; scan for duplicate source keys and raw keys exposed in Blade.
3. Inspect localized route generation and middleware order without running an automated test suite.
4. Scan changed homepage/layout files for unintended hardcoded user-facing strings.
5. Run `./vendor/bin/pint --dirty --format agent` after PHP changes.
6. Run `npm run build` because Blade/Tailwind asset assumptions changed.
7. Run `php artisan project:docs-refresh --check` and `git diff --check`.
8. Review the complete diff against the existing dirty work; commit only task-owned changes if the repository state permits an isolated safe commit on `main`. If unrelated user changes prevent the mandatory clean-tree commit, report that as the explicit blocker without modifying or absorbing them.

## Execution record — 16 July 2026

- Tasks 1–6 are implemented on the existing `main` branch without a new package, migration, branch, worktree or translation layer.
- The repository audit found exactly two supported interface locales (`ru`, `en`), `ru` as both application default and fallback, PHP-array language catalogs, partial `localized.*` route aliases, session key `interface_locale_route`, saved account locale, global web middleware for Livewire updates, and existing database translations only for collections/tags.
- The home route now has validated localized aliases, one CSRF-protected shared switcher, safe current-page redirects, session/user persistence and allowlisted locale handling. Stable unlocalized title slugs and existing content fallback behavior are preserved.
- Homepage SSR, shared layout, accessibility copy, dates, numbers, plural forms, latest-update types, empty/loading/error/end states and SEO use the existing translation loader. `home.php` contains 96 matching leaf keys in each locale; `catalog.php` contains 386 matching leaf keys in each locale, with matching named placeholders.
- Home canonical/alternate metadata, `hreflang`, `x-default`, structured data, localized sitemap entries, locale-separated cache warming and a deterministic public translation fingerprint are integrated. User-specific continue-watching data remains outside shared cache payloads.
- Static verification completed with targeted Pint, PHP syntax checks, recursive key/placeholder parity, hardcoded-string inspection, route/middleware inspection, Blade compilation, Vite production build, managed-document refresh/check and task-scoped `git diff --check`. No automated test was created or run, as required.
- Safe commit/clean-tree completion remains blocked by unrelated concurrent Task 20 changes, including staged files and overlaps in `routes/web.php`, `AppLayoutData`, shared documentation and `CHANGELOG.md`. Those changes were preserved and were not unstaged, reverted or absorbed into a Task 01 commit.
