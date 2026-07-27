# Task 113 — implementation plan современных PHP-практик

Дата: 27.07.2026

## Scope и источники

- External recommendation:
  `https://medium.com/codetodeploy/stop-using-these-bad-php-practices-in-2026-4a889aec5bbf`
- Official Laravel 13 documentation: raw expressions/bindings, errors,
  testing и container lifetimes.
- Repository owners: `AGENTS.md`, `docs/requirements/*`,
  `docs/CODE_STANDARDS.md`, `docs/security.md`, `docs/caching.md`,
  `docs/importer.md`, `docs/technical-issues.md`, `docs/testing.md`,
  maintenance и production operations contracts.

## Ожидаемые изменяемые файлы

- `app/Support/NativeCall.php`
- девять runtime-файлов с 16 операторами `@`
- `tests/Unit/NativeCallTest.php`
- `tests/Unit/ModernPhpPracticeContractTest.php`
- `tests/Unit/StaticAnalysisContractTest.php`
- `phpstan.neon.dist`
- `docs/CODE_STANDARDS.md`, `docs/testing.md`,
  `docs/audits/current-state-audit.md`
- task registry/design/plan/compliance/archive evidence
- `README.md`, `CHANGELOG.md`

## Protected contracts

- `routes/web.php`, `routes/api.php` и все route names/parameters;
- importer command `seasonvar:import`, jobs, payload codecs и exit codes;
- cache keys, format marker, TTL, invalidation и stale fallback;
- `CatalogStatsPosterUrlGuard` HTTPS/public-address SSRF boundary;
- Google read-only scopes, canonical token endpoint и in-memory token;
- profile/technical-issue private upload formats, dimensions and errors;
- policies, gates, API Resources, Livewire state, translations and UI;
- `composer.json`, `composer.lock`, package versions и deployment services.

## Живой checklist

| Priority | Status | Что и почему | Files / dependencies | Риски | Проверка |
|---|---|---|---|---|---|
| critical | `completed` | Повторно прочитать requirements и owners, чтобы решение не нарушило постоянные contracts | `AGENTS.md`, `docs/requirements/*`, thematic docs | Пропустить cache/import/security constraint | Compliance matrix и read log |
| critical | `completed` | Проверить runtime/framework/packages/database/frontend/branch/status | Composer/npm/artisan/Git | Решить задачу по устаревшей версии | Фактические CLI и Boost inventory |
| critical | `completed` | Сопоставить все 8 рекомендаций статьи с кодом | `app`, controllers, SQL, CI/tests | Массовый refactor без подтверждённого дефекта | AST/rg/manual call-site review |
| critical | `completed` | Получить workspace lease после чистого handoff и объявить exact task paths | lease script, `main` | Смешать чужой index | `status`: matching task/PID/manifest |
| high | `completed` | Написать RED AST test: запрет `@`, `exit`, runtime include и `unprepared` | New PHPUnit test, installed PHP-Parser | False positive вне app | RED: ровно 16 `@`; GREEN: 0 |
| high | `completed` | Написать RED runtime tests для callback result, warning conversion и handler restoration | New PHPUnit test | Global handler leak между tests | RED: class отсутствует; GREEN: 3 tests |
| high | `completed` | Добавить generic `NativeCall` с `try/finally` restoration | `app/Support/NativeCall.php` | Nested/global handler corruption | Runtime unit tests + PHPStan passed |
| high | `completed` | Перевести proc read на explicit race-safe `null` | `SeasonvarImportProcessInspector` | Исчезновение process между checks | Existing inspector tests passed |
| high | `completed` | Перевести sitemap/payload gzip decode на sanitized domain exceptions | Two Seasonvar codecs | Изменить size/corrupt message | Existing decoder/compact tests passed |
| high | `completed` | Перевести page-cache gzip decode на cache miss | `PublicPageHtmlPayloadCodec` | Corrupt payload станет 500 | Existing corrupt-payload test passed |
| high | `completed` | Перевести A/AAAA resolution на fail-closed outcome | `CatalogStatsPosterUrlGuard` | SSRF bypass или DNS warning leak | Public/private/unresolvable tests passed |
| high | `completed` | Перевести OpenSSL key parse на safe integration exception | Google access-token service | Credential content/path leak | Invalid-key test passed |
| high | `completed` | Перевести GD/EXIF inspection на explicit validation/fallback | Profile and technical attachment services | Принять malformed raster; temp leak | Valid/corrupt upload tests passed |
| high | `completed` | Сделать temp unlink failure visible via sanitized report | Technical attachment service | Override successful store; leak temp path | Cleanup helper review + full suite passed |
| high | `completed` | Перевести composer-lock timestamp на deterministic safe fallback | `CacheVersionRegistry` | Invalid Last-Modified or repeated report | Cache tests and manual review passed |
| medium | `completed` | Добавить `strict_types` двум затронутым legacy classes | Catalog DNS + Google service | Hidden scalar coercion regression | Focused suites/Pint passed |
| medium | `completed` | Включить support boundary в existing bounded Larastan scope и contract | PHPStan config/test | Uncontrolled whole-app expansion | `composer analyse`: 0 errors |
| high | `completed` | Запустить focused tests всех затронутых domains | Unit/feature suites | External HTTP or shared state | Focused matrices passed |
| medium | `completed` | Обновить code standards/testing/current audit фактическими правилами и метриками | Canonical docs | Переписать историческое evidence | Dated Task 113 section added |
| medium | `completed` | Проверить README и добавить visitor-facing reliability result; обновить русский changelog | README/CHANGELOG | Внутренняя терминология в visitor history | Language/policy checks passed |
| critical | `completed` | Pint, syntax, Rector, PHPStan, backend/frontend gate | Existing tools only | Скрыть failure или менять packages | Оба canonical gate exit 0 |
| high | `completed` | Повторный audit raw SQL/N+1/controllers/security/dead/debug/legacy | Whole repository | Уйти в несвязанный refactor | Actionable gap устранён; unrelated rewrite не потребовался |
| critical | `completed` | Перечитать requirements, закрыть compliance, сохранить archive evidence | Requirements/task docs | Ложный `completed` | Evidence links и honest unresolved обновлены |
| critical | `in_progress` | Проверить status/diff/stat/untracked/secrets/debug/unrelated paths | Git/task manifest | Включить чужой файл или secret | Exact staged paths + staged diff |
| critical | `planned` | Approve exact index, commit в `main`, обычный push | Lease/hooks/remote | Auth failure или history rewrite | Hash/message/push output; no force |

## Database/query assessment

- Schema/migrations/indexes: `not_applicable`; новые query patterns отсутствуют.
- N+1/pagination/result projections: `unaffected`; ни один Eloquent query не
  меняется.
- Raw SQL audit: values bound; identifiers verified through allowlists,
  grammar wrapping or schema-derived constants. Новый raw SQL не добавляется.
- `EXPLAIN`: `not_applicable`, потому что SQL shape и indexes не меняются.
- Transactions/race conditions: DB writes отсутствуют; `/proc` и temporary
  file races получают explicit local outcomes.

## Validation/authorization/security assessment

- HTTP input, Form Requests, query parameters и write endpoints не меняются.
- Policies/gates/CSRF/IDOR/mass assignment не меняются.
- Native warning messages не возвращаются пользователю и не логируются с
  credential, provider URL, request body или private path.
- DNS failure остаётся fail closed; corrupt image/gzip никогда не принимается
  как valid payload.

## Verification sequence

1. RED AST contract.
2. RED runtime `NativeCall` tests.
3. GREEN focused unit tests.
4. Existing affected unit/feature tests.
5. `./vendor/bin/pint --dirty --format agent`.
6. PHP syntax for changed PHP.
7. `composer analyse`.
8. `composer rector:check`.
9. `bash scripts/ci-check.sh backend`.
10. `bash scripts/ci-check.sh frontend`.
11. Full repository legacy/security scans.
12. Exact staged review, lease approval, commit and push.
