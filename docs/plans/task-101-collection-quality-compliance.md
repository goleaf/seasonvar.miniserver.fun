# Task 101 — compliance matrix рейтинга качества подборок

Дата: 26.07.2026.

Статусы: `completed`, `already_compliant`, `not_applicable`, `unresolved`.

| Requirement | Status | Evidence / gate |
| --- | --- | --- |
| Root `AGENTS.md`, requirements index и canonical owners | `completed` | Fresh read до реализации и повторный read перед delivery |
| Runtime/package/frontend/DB inventory | `completed` | PHP 8.5.8, Laravel 13.22, Livewire 4.3, Tailwind 4.3, SQLite |
| Existing collection architecture и contracts | `completed` | Models/services/queries/Livewire/API/sitemap/recommendations/import/tests traced |
| Git branch/status/foreign changes | `completed` | Existing `main`, exclusive lease и exact declared path manifest |
| Production-style census | `completed` | 1 403 total, 501 legacy public, 1 349 oversized, 39 empty, все без категории |
| Laravel 13 official guidance | `completed` | Aggregates, transactions, migrations, validation и schedule проверены |
| Design, live checklist и rollback | `completed` | Task 101 spec/plan и current registry |
| Additive/reversible DB | `completed` | Fresh SQLite, targeted rollback и reapply; no migration DML |
| `quality_score` `0..100` и components | `completed` | Evaluator/assessor unit и feature tests |
| Exact duplicate detection | `completed` | Sorted title-ID SHA-256, earliest persisted canonical, issue и penalty `35` |
| Similar name/description detection | `completed` | Unicode normalization, bounded token buckets/Jaccard, no destructive action |
| Demo/template hiding | `completed` | Exact quarantine preserved; template/current-score public gate |
| Category, cap и current minimum | `completed` | Public/moderation/feature/recommendation tests |
| Dynamic marker | `completed` | Source-managed manual и owner-only smart truthful badges |
| Theme percentage/reason | `completed` | Versioned item columns, matcher, public detail/card tests |
| Editorial verification | `completed` | Current-version audited moderation action and negative tests |
| Saves/reports/completions/returns | `completed` | Grouped privacy-safe aggregate query tests |
| Likes/follows/collaborators | `not_applicable` | User explicitly keeps them unimplemented; no fake social tables/UI |
| Public smart rules | `not_applicable` | Existing owner-only private contract preserved |
| Validation/normalization | `completed` | Stable enums/config bounds/command options/Livewire filter allowlists |
| Authorization/IDOR/CSRF | `completed` | Existing policies/gates and moderation negative tests |
| SQL/XSS/mass assignment/privacy | `completed` | Derived fields non-fillable; escaped Blade; aggregate-only evidence |
| N+1/query bounds/indexes | `completed` | One signature lookup per batch, explicit eager projection, grouped queries |
| `EXPLAIN QUERY PLAN` | `completed` | Signature/refresh/issues indexes used; unused public-quality index removed |
| Cache/search/SEO/sitemap/API/recommendations | `completed` | Broad collection and portal-search regressions; current score gates |
| Import/source/title merge/account lifecycle | `completed` | HDRezka reconciliation and existing lifecycle suites |
| RU/EN/mobile/a11y/error states | `completed` | Translation parity and 3-project Playwright run |
| Dependencies/environment | `not_applicable` | No package, `.env`, provider or infrastructure change |
| Production safety/rollback | `completed` | Additive canary plan, fail-closed marker and no heavy backfill |
| Canonical docs/README/CHANGELOG | `completed` | Topic owners, visitor behavior and dated Russian entry updated |
| Pint/PHPStan/focused/broad/build | `completed` | Exact staged-tree Pint/PHPStan, 81/82 152 focused/parity, Vite 8.1.4 |
| Full PHPUnit | `completed` | Exact staged tree: 2 132 tests, 2 113 passed, 11 skipped, 205 522 assertions; all Task 101 tests passed |
| Unrelated full-suite failures | `unresolved` | 7 failures/1 error: snapshot `.git` contract, search, legacy tag, Seasonvar merge/dispatch и account session |
| Managed docs refresh | `completed` | Exact staged-tree refresh/check passed; migration inventory generated without foreign working-copy hunks |
| Legacy/debug/secret/foreign diff audit | `completed` | Exact isolated index: 70 declared paths, cached diff check и secret/debug/binary scans passed |
| Commit in `main` | `unresolved` | Final commit pending |
| Push configured remote | `unresolved` | Ordinary push pending |
