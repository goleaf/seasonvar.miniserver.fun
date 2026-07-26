# План: field-level исправления данных каталога

Дата: 26.07.2026
Ветка: только существующая `main`
Design: [`../specs/2026-07-26-field-level-catalog-corrections-design.md`](../specs/2026-07-26-field-level-catalog-corrections-design.md)

Статусы: `pending`, `in_progress`, `completed`, `skipped` с причиной.

## Critical — требования, discovery и совместимость

- [completed] Повторно прочитать `AGENTS.md`, requirements index,
  применимые canonical owner-документы и соседнюю реализацию. Почему:
  постоянные правила являются источником истины. Файлы:
  `AGENTS.md`, `docs/requirements/*`, `docs/{architecture,DATA_RELATIONS,catalog-quality,administration,authorization,security,performance,caching,UI_STANDARDS,frontend,views}.md`.
  Зависимости: repository state. Риск: пропустить конфликт постоянных
  contracts. Проверка: recorded inventory и compliance matrix.
- [completed] Проверить runtime/packages/frontend/database/git. Почему:
  реализация должна соответствовать установленным версиям и не затронуть
  чужой dirty scope. Файлы: `composer.lock`, `package-lock.json`,
  configuration и Git index. Зависимости: CLI/Boost. Риск: использовать API
  другой версии или включить чужие изменения. Проверка: PHP/Laravel/Livewire/
  Tailwind/SQLite versions, `git status --short --branch`, remote.
- [completed] Найти канонический correction/vote/moderation domain. Почему:
  параллельная система запрещена. Файлы: `ContentRequest*`, routes, policy,
  actions, services, Livewire/views. Зависимости: Task 19. Риск: duplicate
  workflows. Проверка: route/action/schema inventory.
- [completed] Обновить canonical requirement owner и design до кода.
  Почему: новое постоянное product rule сначала принадлежит одному owner.
  Файлы: `docs/catalog-quality.md`, design spec. Зависимости: discovery.
  Риск: неоднозначная ответственность. Проверка: owner описывает fields,
  reasons, support, moderation, privacy и no-auto-mutation.

## Critical — TDD и schema

- [completed] Написать failing tests для schema, resolver/prefill, tag
  reason, identity, vote и moderation. Почему: доказать отсутствие
  поведения до реализации. Файлы: новые `tests/Feature/*`. Зависимости:
  текущие factories/migrations. Риски: brittle markup и тест реализации
  вместо поведения. Проверка: focused RED с ожидаемой причиной.
- [completed] Добавить additive reversible migration для nullable
  `correction_reason` и `correction_target_key`. Почему: сохранить
  объяснимую причину и identity конкретного relation/episode. Файлы:
  `database/migrations/2026_07_26_*`. Зависимости: current
  `content_requests`. Риски: rolling window, SQLite alter semantics.
  Проверка: migrate fresh/rollback/fresh, schema assertions.
- [completed] Обновить schema readiness и модель. Почему: rolling deploy
  должен деградировать безопасно, а casts/fillable — соответствовать schema.
  Файлы: `ContentRequestSchema`, `ContentRequest`. Зависимости: migration.
  Риск: SQL до migration. Проверка: missing-column readiness regression.

## High — backend и validation

- [completed] Добавить backed enums field/reason и typed target DTO. Почему:
  internal identity не должна зависеть от перевода или raw string. Файлы:
  `app/Enums`, `app/DTOs/ContentRequests`. Зависимости: translation keys.
  Риск: legacy `cast`/`episode_list`. Проверка: enum mapping/compat tests.
- [completed] Реализовать bounded server-side target resolver. Почему:
  query string нельзя считать trusted. Файлы:
  `CatalogCorrectionTargetResolver`, taxonomy registry/model scopes.
  Зависимости: public title/relation/episode visibility. Риски: IDOR,
  N+1, source URL disclosure. Проверка: foreign/hidden/tampered IDs fail;
  safe scalar/relation values pass with bounded queries.
- [completed] Реализовать link builder для title/player presentation. Почему:
  Blade не должен строить business identity. Файлы:
  `CatalogCorrectionLinkBuilder`, page/player builders. Зависимости:
  resolver fields and named route. Риски: localized route regression,
  excessive payload. Проверка: exact query params and no DB query from
  Blade.
- [completed] Расширить form mount/payload/rules/options. Почему:
  field shortcut должен предзаполнить target и потребовать tag reason.
  Файлы: `ContentRequestFormPage`, form Blade. Зависимости: resolver/enums.
  Риски: tampered current value, empty/zero confusion, manual form
  regression. Проверка: Livewire mount and validation tests for each field,
  invalid/empty reason, reset/type changes.
- [completed] Расширить factory/DTO/type rules/create action. Почему:
  action boundary обязана повторно нормализовать и валидировать даже без
  frontend. Файлы: `ContentRequestInput`, factory, rules, create.
  Зависимости: migration/resolver. Риски: mass assignment, inconsistent
  reason/target. Проверка: direct action tests and DB assertions.
- [completed] Включить target key в exact identity, duplicate and merge
  compatibility. Почему: два разных тега/серии не должны схлопываться, а
  одна и та же цель должна собирать поддержку. Файлы:
  `ContentRequestIdentity`, `DuplicateContentRequestService`,
  `MergeContentRequests`. Зависимости: target key. Риски: изменение legacy
  hashes. Проверка: legacy null identity unchanged; two tags differ; same
  tag duplicates/redirects.

## High — frontend, UX и moderation

- [completed] Добавить query-free correction-link component. Почему:
  единая доступная кнопка исключает markup duplication. Файлы:
  `resources/views/components/content-requests/correction-link.blade.php`.
  Зависимости: prepared URL. Риски: слишком шумный UI. Проверка: compact
  label, 44px target, visible focus, escaped URL/label.
- [completed] Добавить links к title/year/poster/description, actor и каждому
  genre/tag/country/translation item, включая missing states. Почему:
  требование относится к конкретному полю. Файлы:
  `CatalogTitlePageBuilder`, `CatalogShowViewModel`,
  `catalog-title-detail.blade.php`. Зависимости: eager-loaded relations.
  Риски: nested links, mobile overflow, duplicate top-taxonomy controls.
  Проверка: HTML assertions and browser desktop/mobile.
- [completed] Добавить episode/subtitle actions в player без nested links.
  Почему: series/subtitle исправление должно сохранять точный context.
  Файлы: `CatalogTitlePlayer`, player Blade. Зависимости: selected/visible
  episode. Риски: player morph/`wire:ignore` boundary. Проверка: links
  остаются вне ignored shell, episode navigation/player tests pass.
- [completed] Показывать correction reason в form/detail/admin context.
  Почему: пользователи и moderator должны понимать предложение. Файлы:
  presenter/detail DTO/views/admin card/translation. Зависимости: enum cast.
  Риски: private evidence leakage. Проверка: reason label visible, raw codes
  not user-facing, voter identities absent.
- [completed] Проверить support и accept/reject flow. Почему: это явное
  функциональное требование. Файлы: existing engagement/status actions и
  admin Livewire; минимальные изменения только при доказанном gap.
  Зависимости: policy, unique vote, status matrix. Риски: race, stale
  version, unauthorized write. Проверка: second user unique vote; guest/
  unverified denied; moderator approved/rejected; ordinary user denied.

## High — security, performance и failures

- [completed] Проверить CSRF, XSS, IDOR, mass assignment, rate limiting,
  no-secret logging и public DTO. Почему: user-generated correction text и
  IDs являются hostile input. Файлы: changed action/form/presenter/views.
  Зависимости: current middleware/policy. Риски: relation enumeration,
  external URL exposure. Проверка: policy/tamper tests, escaped output,
  repository secret/debug scan.
- [completed] Проверить query count/index plan. Почему: title page не должна
  получить per-chip queries, а duplicate lookup должен использовать
  existing hash index. Файлы: page builder/query tests, SQLite `EXPLAIN`.
  Зависимости: eager-loaded taxonomy graph. Риск: hidden N+1. Проверка:
  query-budget regression and `EXPLAIN QUERY PLAN`.
- [completed] Проверить predictable error states. Почему: invalid/tampered
  shortcut не должен приводить к 500 или подробной exception. Файлы:
  resolver/form translations. Зависимости: action exception boundary.
  Риск: fallback на неверную общую форму. Проверка: Russian validation
  message, unavailable schema state, logs contain no sensitive values.

## Medium — documentation и regression

- [completed] Обновить architecture/data/admin/auth/views/UI/frontend docs.
  Почему: owner links, schema, policy, UI и rollback должны совпадать с
  кодом. Файлы: тематические canonical docs. Зависимости: final design.
  Риск: duplicate contract prose. Проверка: docs map and exact references.
- [completed] Проверить и при visitor-visible change обновить `README.md`;
  добавить отдельную русскую запись `CHANGELOG.md`. Почему: project hooks и
  visitor history. Файлы: README/CHANGELOG. Зависимости: completed behavior.
  Риск: затронуть управляемый `project-docs` block. Проверка:
  `project:docs-refresh --check` and hook docs profile. Управляемый
  `docs/MAINTENANCE_LOG.md` остаётся внешним blocker: файл уже подготовлен
  другим task scope и требует refresh относительно параллельных изменений,
  поэтому Task 97 не перезаписывает его.
- [completed] Выполнить legacy/dead/debug/duplicate scan. Почему: новый flow
  не должен оставить generic dead button или второй correction path. Файлы:
  whole repository search. Зависимости: implementation. Риск: ошибочно
  удалить используемый compatibility path. Проверка: dependency review
  каждого совпадения; generic missing-content action сохраняется только если
  имеет отдельную функцию.

## Critical — verification, commit и push

- [completed] Запустить Pint и focused PHP tests, исправляя причины до GREEN.
  Файлы: changed PHP/tests. Зависимости: implementation. Риск: foreign dirty
  files. Проверка: exact command output.
- [completed] Запустить migration tests, broader content-request/catalog/
  security suites, затем full `php artisan test`. Зависимости: focused
  GREEN. Риск: независимые failures в движущемся dirty snapshot. Проверка:
  каждый failure классифицирован и повторно проверен; `|| true` запрещён.
- [completed] Запустить `npm run build`, Blade/docs/config/route checks и
  browser desktop/mobile QA. Зависимости: UI complete. Риск: production
  browser unavailable. Проверка: factual output or `unresolved`.
- [in_progress] Перечитать requirements/task, обновить compliance matrix,
  выполнить `git status`, diff/stat/cached/untracked, secret/debug/format
  scans. Зависимости: all checks. Риск: чужие staged files. Проверка:
  alternate index exact scope and clean diff check.
- [pending] Создать логичный commit в существующей `main` с осмысленным
  сообщением. Почему: user explicitly requested delivery. Зависимости:
  verified scope and required docs. Риск: hook sees foreign index.
  Проверка: alternate-index staged diff, commit hash and original index
  restoration.
- [pending] Выполнить обычный push текущей `main` без force. Зависимости:
  clean working tree requirement may be blocked by foreign changes and
  remote auth. Риск: credentials/remote refusal. Проверка: factual command
  and output; external refusal remains `unresolved`.

## Expected changed files

- `app/Enums/ContentCorrectionField.php`
- `app/Enums/ContentCorrectionReason.php`
- `app/DTOs/ContentRequests/*`
- `app/Services/ContentRequests/*`
- `app/Actions/ContentRequests/{CreateContentRequest,MergeContentRequests}.php`
- `app/Models/ContentRequest.php`
- `app/Livewire/{CatalogTitleDetail,CatalogTitlePlayer}.php`
- `app/Livewire/ContentRequests/ContentRequestFormPage.php`
- `app/Services/Catalog/CatalogTitlePageBuilder.php`
- `app/View/ViewModels/CatalogShowViewModel.php`
- `config/content-requests.php`
- additive migration, RU/EN translations, title/player/request Blade views
- focused feature tests
- canonical docs, `README.md`, `CHANGELOG.md`, current plan.

## Protected compatibility contracts

- route names/URLs `titles.show`, `requests.create/show`, localized request
  routes and `admin.requests`;
- `CatalogTitle` slug binding and public visibility scopes;
- existing `ContentRequestType`, statuses, votes/followers, notifications,
  UUID route identity, active duplicate and submission keys;
- legacy correction field values `cast` and `episode_list`;
- API response formats and importer command;
- player signed source/progress state and sole `wire:ignore` boundary;
- public cache/privacy, recommendation/search/SEO/sitemap behavior;
- all foreign staged/unstaged changes present before Task 97.

## Requirement-compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Existing ContentRequest aggregate reused | `completed` | Design and discovery inventory |
| Field actions for 11 requested domains | `completed` | Title/player SSR links plus Playwright `3/3` on desktop/mobile/tablet |
| Five stable tag reasons | `completed` | Backed enum, required tag validation and RU/EN radio options |
| Community support | `completed` | Existing unique vote/action/policy; two-user and unverified-user regression |
| Moderator accept/reject | `completed` | Existing status matrix/admin/action; approved/rejected regression |
| Server-side target validation/IDOR protection | `completed` | Resolver re-checks target ownership/current value; tamper, foreign target and poster privacy tests |
| Additive reversible SQLite-compatible schema | `completed` | Isolated forward/rollback verification of both nullable columns |
| No Blade queries / responsive Russian UI | `completed` | Prepared URLs/context; 44 px/no-overflow/error-free Playwright matrix |
| Performance/index/query review | `completed` | No per-chip query; existing active identity unique index selected by SQLite EXPLAIN |
| Documentation/README/CHANGELOG | `completed_with_external_docs_blocker` | Canonical owners, visitor history and changelog updated; managed `MAINTENANCE_LOG` refresh belongs to foreign staged scope |
| Commit only in `main` | `in_progress` | Final Git evidence pending |
| Push current remote branch | `unresolved` | Pending; existing HTTPS auth failure is known but will be retried |

## Verification evidence

- Focused field correction: `12` tests, `66` assertions.
- Blade/translation/visual matrix including focused scope: `83` tests,
  `79 084` assertions.
- Catalog/admin/content-request regression: `95` tests,
  `1 818` assertions.
- Browser: `3/3` Playwright projects at `1440×1200`, `390×844` and
  `768×1024`.
- `Pint`, Composer validation, route inventory and Vite production build
  passed.
- Full PHPUnit executed with a temporary `1G` runner config because the
  tracked `256M` limit exhausts memory in the accumulated process:
  `2 086` tests observed, `2 056` passed, `11` skipped, `18` failures and
  `1` error. Failures are outside Task 97 and originate in the concurrent
  catalog UX/session/importer scope, including
  `title-output-controls.blade.php`, missing
  `CatalogTitlesViewModel::viewOptions()` and missing
  `SeasonvarImportDispatchBatcher`; focused Task 97 matrices remain green.
