# Task 104 compliance matrix — player workspace

| Requirement | Status | Evidence / remaining gate |
| --- | --- | --- |
| Fresh AGENTS and canonical requirements | completed | root instructions, requirements index, UI/player/security/performance/PWA/operations docs reread |
| Actual version-specific Laravel/Livewire behavior | completed | Laravel Boost: PHP 8.5, Laravel 13.22, Livewire 4.3; cleanup/morph/island docs |
| Existing architecture preserved | completed | design keeps one `CatalogTitlePlayer`, keyed `wire:ignore`, Plyr/HLS and existing JS ownership |
| Canonical UX contract updated first | completed | UI standards, frontend, architecture, views, playback report |
| Theatre scoped to player and Escape-safe | completed | scoped body/workspace classes; Playwright checks hide-only-secondary, `Escape`, focus return and geometry |
| Compact truthful context controls | completed | ViewModel/projection/transition tests; translation, quality and real subtitle metadata; hot-swap rebuilds public-query links |
| Real subtitles only, no fake tracks | completed | only `has_subtitles`/`subtitle_language` from selected licensed media; no invented track/download URL |
| Descriptive previous/next with real href | completed | server href remains usable without JS; title/episode text and hot-swap labels covered |
| Mobile ≥44 px, bottom sheet, landscape/no overlap | completed | CSS contract plus Playwright desktop/mobile/tablet geometry: 8 passed, 1 skipped |
| Skeleton/loading/fallback/error recovery | completed | runtime states, non-blocking skeleton, retry, existing source chooser and report action covered |
| Hotkeys compact dialog | completed | existing accessible dialog retained behind compact `?` trigger; large inline flow removed |
| Authorization/IDOR/report/source secrecy | completed | existing signed playback/report services retained; transition payload exposes no `source_url`/grant |
| XSS/CSRF/mass assignment | not_applicable_no_new_write | escaped Blade, no new endpoint/input, no client-trusted entitlement |
| Database migration/index | not_applicable | existing nullable `subtitle_language`; projection-only change |
| Query/N+1/performance | completed | selected columns and existing eager loading; 24 unique display options are capped after active-first while all available media remain selectable; no speculative index |
| Public routes/query/API compatibility | completed | no route/API/query-key changes; public `href` and history state retained |
| PWA no-video-cache | already_compliant | service worker/cache contracts untouched; player still uses signed delivery |
| Error logging/privacy | completed | no new log or personal data; raw source URL/grant excluded from client transition metadata |
| RU/EN localization parity | completed | player dictionary keys updated in both locales; focused contract passed |
| Focused/full tests, Pint, build, browser QA | completed_with_external_regressions | final Task 104 + translation parity run: 33 focused PHP tests / 80,915 assertions passed; Pint, PHPStan, Rector, Composer validation, routes and Vite passed; Playwright 8 passed / 1 skipped. Full PHPUnit executed 2,176 tests with 2,148 passed, 11 skipped and 17 parallel-workstream failures/errors unrelated to Task 104; exact evidence archived |
| README/CHANGELOG/evidence | completed | canonical docs, visitor news, dated changelog and archive evidence updated |
| `main` exact commit and ordinary push | unresolved | implementation commit `ed77c9eb` (`feat: redesign player workspace`) created on `main` through hooks; `GIT_TERMINAL_PROMPT=0 git push origin main` failed with `fatal: could not read Username for 'https://github.com': terminal prompts disabled` |

## Cross-feature impact

- Authentication/authorization: only existing report/download/personal actions.
- Search/SEO/notifications/import/admin/premium/payments/ads: no contract change.
- Player/progress/telemetry/cache/PWA: lifecycle and delivery boundaries retained.
- Mobile/accessibility/translations: directly affected and require browser/parity
  evidence.
- Production: asset-only plus projection/markup behavior; no DDL, env, service or
  dependency. Rollback is one commit revert.

## Unresolved external repository state

- `CatalogBladeComponentTest::test_title_page_places_season_anchor_on_season_block_with_scroll_offset`
  separately reproduces a foreign nested Livewire query-state regression:
  `season` no longer selects the requested season. Task 104 does not change the
  `season` URL property, mount contract or season query.
- Full-suite foreign failures also include the concurrently modified offline
  layout architecture rule, shared current-plan policy, account flash contract
  and an absent collection-quality class. They remain outside this lease and
  are not hidden or converted to successful checks.
