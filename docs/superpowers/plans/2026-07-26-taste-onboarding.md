# План реализации быстрого onboarding вкусов

Дата: 26.07.2026.

Design:
[`2026-07-26-taste-onboarding-design.md`](../specs/2026-07-26-taste-onboarding-design.md).

Правило: production code начинается только после наблюдаемого RED. Статусы
обновляются здесь и в
[`docs/plans/current-task-plan.md`](../../plans/current-task-plan.md).

## Critical — анализ и границы

1. `[completed]` Прочитать root `AGENTS.md`, canonical index и все owners.
   - Что/почему: применить permanent architecture/security/data/UI rules.
   - Файлы: `AGENTS.md`, `docs/requirements/*`, canonical owners.
   - Зависимости/риски: private cache leak, второй recommender, fake metadata.
   - Проверка: task-specific compliance matrix с evidence.
2. `[completed]` Проверить runtime, packages, frontend и database.
   - Что/почему: использовать exact Laravel 13/Livewire 4 APIs и SQLite DDL.
   - Модули: Composer/npm locks, Boost application info/schema.
   - Риски: unsupported syntax, ложная production claim.
   - Проверка: exact version inventory и DB connection.
3. `[completed]` Проверить `main`, upstream, status/index и foreign changes.
   - Что/почему: shared tree содержит незавершённую collection task.
   - Модули: Git worktree/index/HEAD/remote.
   - Риски: потеря или случайный commit чужого scope.
   - Проверка: baseline manifest и exact alternate-index delivery.
4. `[completed]` Проследить registration → verification → account settings.
   - Что/почему: onboarding должен появиться после verified boundary.
   - Файлы: auth Livewire/services/responders/routes/tests.
   - Риски: redirect loop, unverified write, locale loss.
   - Проверка: route/middleware/verification contract map.
5. `[completed]` Проследить recommendation source/ranking/exclusion/reset flow.
   - Что/почему: переиспользовать один recommender.
   - Файлы: profile builders, query, rerankers, preference services, export,
     merger.
   - Риски: double bonus/demotion, source self-recommendation, stale reset.
   - Проверка: source-to-result and lifecycle map.
6. `[completed]` Выполнить read-only corpus/schema audit.
   - Что/почему: feature weights и fallback должны основываться на facts.
   - Модули: title/genre/country/translation/media/status tables.
   - Риски: fake status/duration/audio-language.
   - Проверка: counts, null coverage and relation availability.
7. `[completed]` Сравнить JSON, canonical feedback и normalized current state.
   - Что/почему: выбрать FK-safe/editable/private representation.
   - Риски: polymorphic identity, provenance loss, notification side effect.
   - Проверка: design alternatives and selected rationale.
8. `[completed]` Зафиксировать design, exact files, protected contracts,
   cross-feature/rollback и unlimited checklist.
   - Файлы: linked spec/plan/current task.
   - Риск: scope drift.
   - Проверка: reread documents before RED.

## Critical — TDD и schema

9. `[completed]` Написать route/auth/verification RED tests.
   - Что/почему: доказать отсутствующий full-page verified onboarding.
   - Файлы: new `TasteOnboardingTest`, existing verification test.
   - Риски: изменение legacy redirect без idempotence.
   - Проверка: exact tests fail only on missing route/component/redirect.
10. `[completed]` Написать schema/model RED tests.
    - Что/почему: закрепить columns, FK, unique/index/down contracts.
    - Файлы: new schema test.
    - Риски: deployed migration edit или SQLite-incompatible DDL.
    - Проверка: RED on missing tables/columns.
11. `[completed]` Написать service validation/atomicity RED tests.
    - Что/почему: browser validation недостаточна.
    - Файлы: onboarding service test.
    - Риски: partial writes, overlap/IDOR, invalid enums.
    - Проверка: 4/5/10/11, duplicate, overlap, unknown/invisible IDs.
12. `[completed]` Создать additive migration через Artisan.
    - Что/почему: production-safe schema evolution.
    - Файлы: one new timestamped migration.
    - Риски: SQLite table rebuild/lock, rollback data loss.
    - Проверка: migrate/rollback/migrate on isolated test DB.
13. `[completed]` Добавить typed enums/models/relations/schema guard.
    - Что/почему: stable identity, casts, FK lifecycle.
    - Файлы: enums, three models, `User`, preference model/schema.
    - Риски: mass assignment, lazy-loading or stale readiness.
    - Проверка: schema/model tests and static analysis.

## High — backend

14. `[completed]` Реализовать typed onboarding DTO.
    - Что/почему: передавать только normalized validated state.
    - Файлы: new DTO.
    - Риск: raw arrays leaking into persistence.
    - Проверка: constructor/service tests.
15. `[completed]` Реализовать bounded option/search/read query.
    - Что/почему: один owner read и existing catalog search.
    - Файлы: new query service.
    - Зависимости: search parser/suggestions, visibility, option tables.
    - Риски: N+1, unbounded search, hidden title leak.
    - Проверка: query budget, visibility and search tests.
16. `[completed]` Добавить account-settings onboarding mutation.
    - Что/почему: reuse canonical locale/subtitles owner.
    - Файлы: `AccountSettingsService`.
    - Риски: перезапись volume/quality/variant.
    - Проверка: exact unchanged-field assertions.
17. `[completed]` Реализовать transactional onboarding service.
    - Что/почему: all preferences change atomically.
    - Файлы: new service, preference query invalidation.
    - Риски: races/repeated save/partial sync/rate abuse.
    - Проверка: idempotence, replacement, rollback and rate-limit tests.
18. `[completed]` Добавить full-page Livewire route/component.
    - Что/почему: project HTML architecture.
    - Файлы: `routes/web.php`, new Livewire class.
    - Риски: client-tampered state, route collision, locale hydration.
    - Проверка: route list, guest/unverified/owner tests.
19. `[completed]` Изменить first verification redirect.
    - Что/почему: onboarding должен быть сразу после registration lifecycle.
    - Файлы: `AccountEmailVerificationResponder`, auth tests.
    - Риски: no-session/idempotent link regression.
    - Проверка: matching owner/new/already verified/anonymous cases.

## High — recommendation integration

20. `[completed]` Добавить onboarding liked signals в v2 profile.
    - Что/почему: устранить cold start через существующий scorer.
    - Файлы: evidence/reason enums, profile builder.
    - Риски: stale/reset activity semantics, source cap.
    - Проверка: five chosen titles produce high profile confidence.
21. `[completed]` Добавить liked signals в legacy personalized query.
    - Что/почему: feature не зависит от rollout flag.
    - Файлы: personalized query.
    - Риски: duplicate stronger signal/order.
    - Проверка: deterministic precedence and legacy candidate test.
22. `[completed]` Добавить onboarding title hard exclusions.
    - Что/почему: known/excluded titles не должны рекомендоваться.
    - Файлы: exclusion service.
    - Риски: unbounded IDs or non-personal behavior regression.
    - Проверка: liked/excluded absent across fallback/personal paths.
23. `[completed]` Расширить feature extractor достоверными availability/status/
    duration traits.
    - Что/почему: применить explicit preferences без heuristics.
    - Файлы: feature extractor/config.
    - Риски: queries/N+1, metadata-only media, unknown misclassification.
    - Проверка: known metadata features; unknown remains absent.
24. `[completed]` Добавить capped positive taste boosts.
    - Что/почему: genre/country/mode/status/duration должны влиять на order.
    - Файлы: preference DTO/query and taste reranker.
    - Риски: relevance override/double demotion/exploration break.
    - Проверка: isolated/combined preferences, cap and stable tie-break tests.
25. `[completed]` Расширить reset semantics.
    - Что/почему: «сброс профиля вкусов» должен удалить onboarding influence.
    - Файлы: preference service/query/tests.
    - Риски: удалить library/normal feedback.
    - Проверка: onboarding removed, protected state preserved.
26. `[completed]` Расширить title merge.
    - Что/почему: FK cascade не должен молча потерять choice.
    - Файлы: `CatalogTitleUserDataMerger`, merge tests.
    - Риски: unique conflict and wrong precedence.
    - Проверка: excluded > liked, one canonical row.
27. `[completed]` Расширить account export.
    - Что/почему: private portability/lifecycle contract.
    - Файлы: export service/tests.
    - Риски: internal IDs or foreign private data leak.
    - Проверка: codes + safe labels, no IDs/raw features.

## High — frontend, localization, UX

28. `[completed]` Создать RU/EN onboarding translation catalogs.
    - Что/почему: exact locale parity and no hardcoded copy.
    - Файлы: `lang/ru/onboarding.php`, `lang/en/onboarding.php`,
      recommendation reason key.
    - Риски: placeholder/order/vertical-format mismatch.
    - Проверка: translation parity and PHP syntax.
29. `[completed]` Создать responsive accessible onboarding view.
    - Что/почему: быстрый usable flow на phone/tablet/desktop.
    - Файлы: new Blade view.
    - Риски: oversized DOM, horizontal overflow, dead controls.
    - Проверка: component assertions and Playwright matrix.
30. `[completed]` Добавить ссылку повторной настройки в personal discovery.
    - Что/почему: onboarding должен быть editable.
    - Файлы: discovery Blade/component prepared URL.
    - Риски: guest visibility/localized route mismatch.
    - Проверка: owner-only link RU/EN.
31. `[completed]` Добавить loading/error/empty/success and double-submit guards.
    - Что/почему: predictable Livewire UX.
    - Файлы: component/view/translations.
    - Риски: lost selection on failure.
    - Проверка: Livewire validation/error/retry tests and browser.

## Medium — performance, security, compatibility

32. `[completed]` Проверить EXPLAIN owner/kind/genre/country reads.
    - Что/почему: каждый новый index должен иметь exact query.
    - Файлы: migration/query evidence only.
    - Риски: duplicate indexes/write amplification.
    - Проверка: SQLite query plan uses unique/covering indexes.
33. `[completed]` Проверить constant query count 5 vs 10 choices.
    - Что/почему: исключить per-item queries.
    - Файлы: service/query tests.
    - Риск: Livewire render N+1.
    - Проверка: bounded query-count assertion.
34. `[completed]` Выполнить security/privacy review.
    - Что/почему: authenticated private preference write.
    - Модули: gate, verified middleware, CSRF, locked IDs, visibility,
      export/cache/logs.
    - Риски: IDOR, XSS, mass assignment, private cache leak.
    - Проверка: negative auth/tamper tests and repository scan.
35. `[completed]` Проверить cross-feature compatibility.
    - Что/почему: auth/settings/recommendation/merge/export/delete affected.
    - Модули: public API/routes/cache/importer/SEO/notifications.
    - Риски: public contract or notification suppression change.
    - Проверка: related regression matrix.
36. `[completed]` Выполнить architecture/code review.
    - Что/почему: удалить duplication/dead code/oversized methods/imports.
    - Модули: all changed code.
    - Риски: abstraction ради abstraction.
    - Проверка: manual review + static tools.

## Critical — verification и delivery

37. `[completed]` Запустить focused RED→GREEN tests после каждого slice.
    - Команды: exact onboarding/auth/recommendation/reset/merge/export tests.
    - Риск: false green from unrelated test.
    - Проверка: recorded counts/assertions.
38. `[completed]` Запустить Pint, Larastan и Rector dry-run.
    - Почему: style/types/framework modernization gate.
    - Проверка: zero relevant errors/diff.
39. `[completed]` Запустить focused broad recommendation/auth/account matrix.
    - Почему: cross-feature regression coverage.
    - Проверка: exact test counts/assertions.
40. `[completed]` Запустить полный `php artisan test`.
    - Почему: wide schema/auth/recommendation change.
    - Риск: known cumulative 256M limit.
    - Проверка: literal result; exact failed test separately if unrelated.
41. `[completed]` Запустить Vite build и translation/docs checks.
    - Почему: new Blade/Tailwind/translations/docs.
    - Проверка: build, parity, `project:docs-refresh --check`.
42. `[completed]` Запустить Playwright desktop/mobile/tablet.
    - Почему: responsive/accessibility/browser acceptance.
    - Проверка: no console/page/request failure or horizontal overflow;
      screenshots under ignored `output/playwright`.
43. `[completed]` Обновить canonical feature docs, README и CHANGELOG.
    - Почему: visitor/product/data/deployment change.
    - Файлы: data/architecture/frontend/security/performance/deployment,
      README, CHANGELOG, plans.
    - Риски: managed blocks/foreign doc hunks.
    - Проверка: exact task patch and Russian policy.
44. `[completed]` Перечитать requirements/design и закрыть compliance matrix.
    - Почему: discovery/implementation drift.
    - Проверка: completed/already_compliant/not_applicable/unresolved evidence.
45. `[completed]` Выполнить final Git/diff/secret/debug/legacy scan.
    - Почему: shared dirty tree и user-required exact scope.
    - Проверка: status, unstaged/staged diff/stat, untracked, route, branch,
      remote, debug/TODO/secrets/unrelated formatting.
46. `[pending]` Создать exact logical commit в existing `main`.
    - Почему: завершить разрешённую работу без foreign changes.
    - Риск: concurrent HEAD/index changes.
    - Проверка: alternate index exact manifest, cached diff/check, resulting
      hash/message and parent.
47. `[pending]` Выполнить configured non-force push текущей `main`.
    - Почему: user explicitly requested delivery.
    - Риск: existing HTTPS authentication blocker.
    - Проверка: literal command/exit/output; failure remains unresolved.

## Verification evidence

- TDD RED: 11 tests дали 5 passed, 2 failures и 4 errors только из-за
  отсутствующих schema/routes/service/component; recommendation RED дал
  5 tests, 2 failures и 3 errors на отсутствующих signals/features/lifecycle.
- Final task matrix: 52/52 tests, 73 746 assertions; после исправления
  full-suite Blade policy точные onboarding/auth/merge/translation tests
  повторно прошли 27/27 с 73 610 assertions.
- Schema: isolated SQLite migrate → rollback → migrate прошёл с
  `APP_ENV=testing`; FK/index contracts и `EXPLAIN QUERY PLAN` проверены
  `CatalogTasteOnboardingSchemaTest`.
- Style/static: scoped Pint, full Larastan (0 errors) и scoped Rector
  (0 changes) прошли. Global Rector предлагает только `never` return types в
  двух foreign collection files.
- Frontend: Vite 8.1.4 build прошёл; final Playwright desktop/mobile/tablet
  matrix прошла 3/3 без console/page/network/overflow failures.
- Routes: fresh uncached route list показывает `onboarding.tastes` и
  `localized.onboarding.tastes` с `auth`, `account` и `verified` middleware.
  Local stale route cache намеренно не очищался destructive command.
- Docs: `composer validate --strict` и
  `php artisan project:docs-refresh --check` прошли.
- Full suite: default `php artisan test` воспроизвёл накопительный 256 MiB
  limit; exact interrupted security test прошёл 1/1. Эквивалентный test-only
  1 GiB suite выполнил 1 909 tests: 1 895 passed, 11 skipped, 2 failures и
  1 error. Единственный Task 71 failure (`truncate` в новом Blade) исправлен
  и точечно прошёл; оставшиеся exact foreign failures —
  `WebAccountManagementTest::test_logout_other_browser_sessions_preserves_current_session`
  (нет flash message) и
  `SeasonvarImportDispatchBatcherTest::test_dispatch_next_registers_serial_pages_in_one_bounded_batch`
  (foreign class ещё отсутствует в shared working tree).
