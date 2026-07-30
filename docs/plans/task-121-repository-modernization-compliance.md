# Task 121 — compliance полной модернизации repository

Обновлено: 30.07.2026.

Статус: `in_progress`. Этот файл является task-specific matrix, а не новым
каноническим владельцем доменных требований. Канонические источники указаны
в `docs/requirements/index.md`; статусы меняются только после проверки.

## Контролируемые статусы

- `completed` — task preparation или implementation выполнены и проверены;
- `already_compliant` — существующий contract фактически проверен и не
  требует изменения;
- `not_applicable` — требование не относится к фактическому проекту, причина
  указана;
- `unresolved` — существует доказанный внешний или environmental blocker.

## Матрица

| ID | Требование / источник | Статус | Implementation / evidence | Verification |
| --- | --- | --- | --- | --- |
| GOV-121-001 | Root `AGENTS.md`, requirements index и applicable owners прочитаны до code edits | `completed` | Root instructions; `docs/requirements/*.md`; project skills | Reading log в approved design и plan |
| GOV-121-002 | Existing `main`, user changes и exact lease защищены | `completed` | lease `task-121-repository-modernization`; untracked audit image исключён | `git status --short --branch`; `verify-owner` |
| GOV-121-003 | Living plan, compliance matrix, expected files и protected contracts подготовлены | `completed` | current plan, this matrix, approved design, implementation plan | повторное чтение plan до code edits |
| DOC-121-001 | Каждый first-party Markdown классифицирован; canonical owners не дублируются | `unresolved` | `docs/markdown-review-2026-07-13.md` должен получить свежий corpus snapshot | tracked Markdown inventory + link/owner checks |
| DOC-121-002 | Активные требования имеют стабильные IDs и traceability | `unresolved` | existing requirement owners + planned fixture/seeding owner | requirements repository tests |
| DOC-121-003 | Managed docs, README, CHANGELOG и final docs соответствуют фактическому коду | `unresolved` | baseline stale managed blocks подтверждены | `project:docs-refresh --check`; docs CI |
| MNT-121-001 | PHP target `>=8.5.0 <8.6.0`, Laravel `^13.0`, current compatible package constraints | `unresolved` | `composer.json`, lock, maintenance decisions/inventory/runtime docs | Composer validate/audit/why-not/tests |
| MNT-121-002 | Livewire `4.3+`, Tailwind `4.3+`, Vite integration и current stable patches проверены | `unresolved` | package manifest/lock, frontend upgrade decision | npm audit/build/browser |
| MNT-121-003 | Production compatibility, rollback and data-safety review | `completed` | current plan; production inspection; no production mutation authorized | production operations checklist |
| PHP-121-001 | PHP 8.5 warnings/deprecations и modified-code typing verified | `unresolved` | Rector/PHPStan/runtime tests; changed code | full error-reporting tests, syntax, static gates |
| LAR-121-001 | Laravel 13 structure/features reviewed without replacing project configuration | `already_compliant` | installed Laravel 13.23; modern bootstrap/providers/routes inspected | Boost app info, route/config cache, full suite |
| LAR-121-002 | Laravel 13 feature applicability decisions documented | `unresolved` | architecture/upgrade owner update planned | framework applicability matrix check |
| ARCH-121-001 | Routes/controllers/Livewire/domain services preserve existing boundaries | `unresolved` | full route-to-domain census and targeted refactors | architecture tests + focused feature tests |
| SEC-121-001 | Auth/authz/input/URL/file/token/logging boundaries reviewed and regressions tested | `unresolved` | policies, requests, Livewire actions, integrations | security-focused test selection + full suite |
| DATA-121-001 | Schema/model consistency, indexes, transactions and additive migration safety verified | `unresolved` | 150-model/134-migration census; one pending production migration | fresh migration, rollback/fresh-seed, DB tests |
| SEED-121-001 | Every suitable Eloquent model has a valid factory or documented exemption | `unresolved` | RED: 146 models / 11 factories / 135 missing contracts | `ModelFactoryCoverageTest` |
| SEED-121-002 | Meaningful states and explicit graph helpers cover domain states | `unresolved` | model enum/status census planned | state coverage tests |
| SEED-121-003 | Reference/dev/demo/test seeders are safe, deterministic and production-guarded | `unresolved` | production guard completed; broader reference/demo coverage remains | `LocalAccountSeederTest`, `PortalDemoSeederTest` |
| TEST-121-001 | Full PHP behaviour remains green and new defects have regressions | `unresolved` | baseline: 2307 tests / 208533 assertions / 11 skipped | focused TDD + final full/parallel runs |
| TEST-121-002 | Coverage is measured where a driver exists; no fabricated percentage | `unresolved` | coverage driver must be detected | PHPUnit coverage command or exact blocker |
| LIVE-121-001 | Class-based Livewire only; public state/auth/validation/features reviewed | `unresolved` | baseline: 86 class files, no Volt found | component census/tests/feature matrix |
| BLADE-121-001 | No `@php`, query/model/service/facade calls or unsafe HTML in Blade | `already_compliant` | repository scans found no current `@php`/Volt/debug regressions | architecture tests and final whole-repo scan |
| UI-121-001 | Tailwind CSS-first sources/tokens/responsive/a11y contracts reviewed | `unresolved` | existing CSS-first Vite build passed | feature matrix, build, Playwright viewports |
| I18N-121-001 | ru/en keys, placeholders, identity values and localized errors stay consistent | `unresolved` | canonical multilingual owner | translation tests and key parity |
| PERF-121-001 | Critical queries/cache/Livewire/assets remain bounded | `unresolved` | baseline asset sizes captured | query/payload/cache tests + final build comparison |
| OPS-121-001 | Fresh migration, fresh seed, cacheable artifacts and boot smoke pass | `unresolved` | isolated test environment only | migration/seed/config/route/view gates |
| GIT-121-001 | Exact authorized scope committed only in `main` | `unresolved` | no Task 121 commit yet | manifest/index approval and commit hash |
| GIT-121-002 | Completed commit pushed, or external failure recorded factually | `unresolved` | remote authentication previously unavailable | observed `git push` result |

## Cross-feature impact

Every implementation pass must re-evaluate authentication, authorization,
translations, caching, search, notifications, SEO, privacy, mobile,
administration, audit, imports, premium/region/legal access, player, PWA,
deployment, backups and rollback. A domain is `not_applicable` only after a
repository search or test proves that status.

## Baseline evidence

- PHP suite: `2307` tests, `2296` passed, `11` skipped,
  `208533` assertions.
- Composer/npm advisories: `0` / `0`.
- Pint, required Rector profile, full PHP syntax scan and bounded Larastan:
  passed.
- Vite production build: passed.
- Full CI baseline: stopped by stale managed docs before repeating PHP tests.
- Production-style database: one pending additive lookup-index migration;
  no production mutation performed.
