# Task 111 — requirement compliance matrix

Статусы: `completed`, `already_compliant`, `not_applicable`, `unresolved`.

| Requirement | Status | Evidence / action |
|---|---|---|
| Read `AGENTS.md`, canonical index и применимые owners before edits | `completed` | Read order выполнен; homepage, comments, content requests, performance, cache, UI, maintenance и production boundaries сопоставлены |
| Verify actual runtime/packages/database/frontend | `completed` | PHP 8.5; Laravel 13.22.0; Livewire 4.3.3; Tailwind 4.3.2/Vite 8; SQLite |
| Work only on existing `main` and preserve foreign changes | `completed` | Предыдущий owner завершил commit `3b583577`; clean tree проверен до собственного lease |
| Acquire lease and declare exact paths before edits | `completed` | `task-111-homepage-query-classes-performance`; declared manifest stored by lease helper |
| Fix guest discussion loading error | `completed` | Guest root/reply projection always includes zero private count; focused regression is green |
| Show every genre, country and valid year on home | `completed` | One grouped query; full guest/auth web collections and focusable bounded UI |
| Preserve routes, API shape and query parameters | `completed` | Existing routes untouched; `/api/v1/home` keeps 18 genres/12 years and current JSON shape |
| Implement Query Class pattern without speculative repository layer | `completed` | Three domain-local `final readonly` query classes; reflection contract; no repository |
| Normalize/validate all relevant request input | `completed` | Strict nested keys, backed provider enum, normalized composite duplicate at Livewire/domain layers |
| Authorization remains server-side | `already_compliant` | Changes are read-only/public facets or retain existing policies/gates; no new write route |
| Avoid N+1, duplicate/unused queries and unbounded result hydration | `completed` | Genre/country union, hidden authenticated featured query skipped, selected projections retained |
| Add only query-evidenced reversible indexes | `completed` | `release_schedule_title_released_idx`; alternative `is_public` index rejected after measurement |
| Cache has explicit invalidation/version strategy | `completed` | `content-index-v3`, `homepage-year-buckets-v2`, API slicing outside public snapshot |
| Use scoped lifecycle for request memoization | `completed` | `scopedIf(CatalogTasteOnboardingSchema::class)` and same/new-scope assertions |
| Prevent Collection `each()` false-sentinel regressions | `completed` | 19 explicit `void` closures and parser-based repository regression |
| Evaluate referenced large-project architecture blueprint | `completed` | Existing service/view model/API Resource/config/component boundaries retained; speculative interfaces/repositories/global composers/domain rewrite and unverified zero-downtime claim rejected |
| Preserve browser-session logout on Laravel 13.22.0 | `completed` | Exact version/message/stack shim restores only skipped event; feature test proves current-session preservation and other-session deletion |
| Preserve direct player query state and nested Livewire ownership | `completed` | Full-page owner passes validated initial selection; nullable mount arguments do not overwrite direct `#[Url]` hydration |
| Keep player PHP/assets in one release identity | `completed` | `CatalogTitleDetail` is listed in `resources/player-release.json`; fresh build returned `ready: true` for `30` sources / `19` assets, lifecycle browser matrix passed |
| Keep player failure UX singular and browser guard precise | `completed` | One recovery retry remains; only exact optional PWA poster miss and browser navigation cancellation are non-fatal |
| Preserve player session identity across Livewire morphs | `completed` | `wire:ignore.self` protects only root client identity while descendants continue updating; stale-event checks remain exact |
| Keep Firefox player acceptance deterministic without production relaxations | `completed` | Test-only CSP matches fixture media, fonts settle before login navigation, and visible option click is atomic |
| Keep prepared catalog/tag view data visible without Blade queries | `completed` | `directorySuggestions` and `TagPageData::related` render through existing named routes |
| Keep Top‑100 displayed rating consistent with ranking | `completed` | `CatalogTopListItem::ratingProvider` selects the existing card presentation order without extra SQL |
| Isolate hook contract tests from active task lease | `completed` | Child Process explicitly removes owner/token variables before asserting missing-owner failure |
| Security audit: secrets, SQLi, XSS, CSRF, IDOR, rate limits | `already_compliant` | Production `.env`/debug probes safe; dynamic identifiers allowlisted; Blade raw output sanitized; existing admin/CSRF boundaries verified |
| Production backup and restore readiness | `unresolved` | Repository runbook explicitly lacks a currently verified restore rehearsal; external operational action is outside code authority |
| Update/validate project skills safely | `completed` | Invalid frontmatter and targeted rules fixed; all 23 project skills pass `quick_validate.py` |
| Do not add dependency solely because it is newer | `already_compliant` | No PHP/Laravel/package upgrade; PHP constraint `^8.3` remains |
| Update canonical docs, README and CHANGELOG | `completed` | Architecture/performance/cache/UI/testing/security/data/MCP owners plus visitor/technical histories updated |
| Focused then broad tests, Pint/static/build/browser | `completed` | Focused 64/402, related 35/255, regression 13/150; backend 2286 tests / 208315 assertions, frontend audit/build, 23 skills, homepage 6/6 and player lifecycle 16 passed / 6 skipped |
| Exact staged review, secrets scan, commit and push | `unresolved` | Pending |
