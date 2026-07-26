# Implementation plan: предпочтения переводов и субтитров

Дата: 26.07.2026.

Статус: `verification_completed_delivery_in_progress`.

Design:
[`2026-07-26-playback-translation-preferences-design.md`](../specs/2026-07-26-playback-translation-preferences-design.md).

## Critical — подготовка и contracts

1. `[completed]` Прочитать root `AGENTS.md`, requirements index, применимые
   canonical requirements и feature Markdown.
   Почему: repository rules являются source of truth.
   Files: `AGENTS.md`, `docs/requirements/*`, playback/account/import/notification
   docs.
   Dependencies: none.
   Risks: пропуск cross-feature boundary.
   Verify: fresh-read evidence в compliance matrix.
2. `[completed]` Проверить actual PHP/Laravel/packages/frontend/DB/Git.
   Почему: version-dependent API и shared-tree isolation.
   Files: `composer.lock`, `package-lock.json`, runtime, Git metadata.
   Dependencies: installed environment.
   Risks: stale version assumptions, foreign changes.
   Verify: exact command output and baseline.
3. `[completed]` Проследить existing account, resolver, player, importer,
   cards, recommendation, notification, export/delete/onboarding paths.
   Почему: расширяем owners без duplicate architecture.
   Files: соответствующие models/services/Livewire/tests.
   Dependencies: steps 1–2.
   Risks: второй preference/player/notification contract.
   Verify: design data-flow и protected contracts.
4. `[completed]` Зафиксировать alternatives, schema, precedence, privacy,
   rollout и rollback в design.
   Почему: изменение влияет на persistent state и player selection.
   Files: design spec, canonical playback owner.
   Dependencies: step 3.
   Risks: неоднозначные favorite/fallback/hidden semantics.
   Verify: reread spec before RED.
5. `[completed]` Зафиксировать expected files, compatibility domains,
   migration/routes/translations/cache/permission risks.
   Почему: обязательный repository workflow.
   Files: current plan и этот plan.
   Dependencies: step 4.
   Risks: scope drift.
   Verify: living checklist updates at every discovery.

## Critical — schema и account service через TDD

6. `[completed]` Добавить RED schema/model tests.
   Почему: additive columns/table/index/cascade/down должны быть доказаны.
   Files: new focused schema test.
   Dependencies: prepared design reread.
   Risks: SQLite FK/drop order, long constraint names.
   Verify: targeted test fails for missing schema.
7. `[completed]` Создать reversible additive migration, enum и hidden model.
   Почему: normal form и stable values.
   Files: new migration, `PlaybackPreferenceMode`,
   `UserHiddenPlaybackVariant`, User relationships.
   Dependencies: RED 6.
   Risks: rolling deployment.
   Verify: migrate/rollback/forward/integrity test.
8. `[completed]` Расширить schema guard, DTO и defaults.
   Почему: safe reads during partial deploy and backward compatibility.
   Files: `AccountSettingsSchema`, account/playback DTO, config.
   Dependencies: step 7.
   Risks: constructor callers.
   Verify: existing tests compile; missing-column test uses safe defaults.
9. `[completed]` Добавить RED service tests: success, empty values, invalid
   mode/language/key, equal favorite/fallback, hidden conflicts, max set,
   unavailable retained, no-op version, atomic reset.
   Почему: frontend input is untrusted.
   Files: new `AccountTranslationPreferencesTest`.
   Dependencies: step 8.
   Risks: partial write and race.
   Verify: precise validation keys and unchanged DB after failure.
10. `[completed]` Реализовать atomic AccountSettingsService write/reset/resolve.
    Почему: one policy/transaction boundary.
    Files: service, options query, DTO/model.
    Dependencies: RED 9.
    Risks: N+1 options and unnecessary version bumps.
    Verify: GREEN service tests and query inspection.
11. `[completed]` Интегрировать export/delete.
    Почему: user-owned data portability and erasure.
    Files: account export/query/service tests.
    Dependencies: step 10.
    Risks: leaking raw provider URL or orphan rows.
    Verify: exact JSON keys, no secrets, cascade deletion.

## High — media metadata и importer через TDD

12. `[completed]` Добавить RED `ExternalMediaMetadata` language tests.
    Почему: explicit-only parsing contract.
    Files: `ExternalMediaMetadataTest`.
    Dependencies: configured allowlist decision.
    Risks: false language guesses.
    Verify: explicit RU/EN markers recognized, unknown/country/locale null.
13. `[completed]` Реализовать nullable subtitle language normalization.
    Почему: auto subtitle selection needs trustworthy metadata.
    Files: metadata service and playback config.
    Dependencies: RED 12.
    Risks: confusing variant name with language.
    Verify: unit GREEN.
14. `[completed]` Добавить RED importer/backfill tests и записать field in all
    existing write paths.
    Почему: new media and old metadata refresh must converge.
    Files: `SeasonvarCatalogImporter`, `ExternalPlaylistImporter`,
    `SeasonvarImportPipeline`, focused tests.
    Dependencies: step 13.
    Risks: overlapping foreign importer hunk; no full video download.
    Verify: prepared/apply/upsert assertions and existing HTTP fakes.

## Critical — player selection через TDD

15. `[completed]` Добавить RED resolver/transition tests for precedence.
    Почему: explicit > favorite > fallback > mode/language > old ranking.
    Files: player transition/source resolver tests.
    Dependencies: account DTO.
    Risks: breaking explicit media and source health fallback.
    Verify: exact selected media IDs for every combination.
16. `[completed]` Добавить RED hidden source/menu tests.
    Почему: «не показывать» must affect display and automatic selection.
    Files: resolver/player Livewire tests.
    Dependencies: step 15.
    Risks: zero playable sources.
    Verify: hidden absent, safe fallback/error when all hidden.
17. `[completed]` Реализовать preference projection/ranking/filtering.
    Почему: one canonical resolver and server-owned selection.
    Files: `PlaybackPreferencesData`, `CatalogPlaybackSourceResolver`,
    `CatalogTitlePlayer`, transition factory if needed.
    Dependencies: RED 15–16.
    Risks: raw hidden state in client snapshot, explicit contract regression.
    Verify: GREEN plus security hardening tests.

## High — cards и recommendations через TDD

18. `[completed]` Добавить RED card state and query-budget tests.
    Почему: requested labels without Blade/N+1 query.
    Files: card loader/component tests.
    Dependencies: account resolve.
    Risks: shared cache contamination and per-title reads.
    Verify: one bounded media query and transient private attribute.
19. `[completed]` Реализовать grouped card preference overlay и RU/EN label.
    Почему: visitor-visible feedback.
    Files: `CatalogUserCardStateLoader`, `TitleCard`, title-card views,
    `lang/{ru,en}/catalog.php`.
    Dependencies: RED 18.
    Risks: dirty shared translation/tests files.
    Verify: authenticated/guest/preferred/alternative/hidden cases.
20. `[completed]` Добавить RED recommendation reranker tests.
    Почему: global preference should influence personalized availability.
    Files: recommendation availability tests.
    Dependencies: player semantics.
    Risks: extra repeated queries.
    Verify: combined favorite/fallback/mode/language boost and hidden exclude.
21. `[completed]` Реализовать one-pass grouped availability boosts.
    Почему: avoid one query per preference dimension.
    Files: `CatalogRecommendationAvailabilityReranker`.
    Dependencies: RED 20.
    Risks: score/public mode regression.
    Verify: query count and existing recommendation suite.
22. `[completed]` Синхронизировать taste onboarding mode.
    Почему: cold-start playback choice must feed same global profile.
    Files: onboarding service/tests.
    Dependencies: new mode enum.
    Risks: overwrite explicit existing preferences.
    Verify: only relevant mode changes; favorite/fallback preserved.

## Critical — notification через TDD

23. `[completed]` Добавить RED notification recipient/idempotency/privacy tests.
    Почему: favorite appearance signal must not spam or leak.
    Files: new notification/service tests.
    Dependencies: schema and media publication.
    Risks: notification on unpublished/inaccessible media or repeated episode.
    Verify: eligible/ineligible recipients, deterministic UUID, safe payload.
24. `[completed]` Реализовать database notification and after-commit service.
    Почему: real portal channel already exists.
    Files: notification, service, observer.
    Dependencies: RED 23.
    Risks: notification failure rolling back import.
    Verify: observer/service GREEN and exception isolation.
25. `[completed]` Интегрировать inbox/presentation/export/read boundary.
    Почему: stored row without usable owner UI is incomplete.
    Files: existing notification query/panel or dedicated compatible presenter,
    account export.
    Dependencies: step 24.
    Risks: duplicate route/UI and cross-type mark-read.
    Verify: owner can see/read; other user cannot; payload localized at render.

## High — Livewire UI через TDD

26. `[completed]` Добавить RED Livewire auth/save/validation/reset/render tests.
    Почему: all controls must be functional and server-validated.
    Files: new settings feature test.
    Dependencies: backend service.
    Risks: missing labels/errors/loading and stale unavailable values.
    Verify: wire actions, DB state, errors, no partial write.
27. `[completed]` Реализовать Livewire state/options/form controls.
    Почему: visitor-visible global settings.
    Files: `AccountSettingsPage`, existing playback Blade.
    Dependencies: RED 26.
    Risks: giant component, rerender queries, mobile layout.
    Verify: focused Livewire tests and rendered semantics.
28. `[completed]` Добавить RU/EN parity and public terminology tests.
    Почему: interface locale separation and repository multilingual contract.
    Files: `lang/{ru,en}/settings.php`, catalog/notification keys.
    Dependencies: UI/notification/card.
    Risks: hardcoded strings or placeholder mismatch.
    Verify: translation parity suite.

## High — security, performance и cross-feature

29. `[completed]` Проверить policy, IDOR, CSRF, mass assignment and safe output.
    Почему: private preferences are writeable owner data.
    Files: policy/service/Livewire/Blade.
    Dependencies: implementation.
    Risks: forged variant/user ID, XSS label.
    Verify: unauthenticated/other-user tests; escaped Blade.
30. `[completed]` Проверить SQL/indexes/EXPLAIN and query counts.
    Почему: cards/recommendations/notifications operate over media/users.
    Files: migration/query services/tests.
    Dependencies: representative SQLite data.
    Risks: full scans or duplicate indexes.
    Verify: `EXPLAIN QUERY PLAN` выбрал
    `user_account_preferred_translation_notify_idx`, covering unique индекс
    hidden-вариантов и существующий publication lookup licensed media;
    связанные query-budget regressions устранены и повторно прошли.
31. `[completed]` Проверить cache/SEO/API/mobile/Premium/region/legal/player
    compatibility.
    Почему: system-wide integration requirement.
    Files: relevant tests/docs; code only if defect proven.
    Dependencies: full implementation.
    Risks: private state in shared cache or access bypass.
    Verify: regression matrix and public output assertions.
32. `[completed]` Repository-wide scan for duplicate preference/player/
    notification implementations, TODO/debug/dead controls.
    Почему: completion gate.
    Files: repository read-only scan.
    Dependencies: code complete.
    Risks: false-positive deletion.
    Verify: related routes/services/config/translations/cache paths и
    TODO/debug patterns просмотрены; конкурирующий resolver, preference store,
    notification path или dead control не найден.

## Critical — verification, documentation и delivery

33. `[completed]` Run exact Pint on task PHP paths and `--dirty --format agent`.
    Почему: PSR/project style.
    Files: PHP changes.
    Dependencies: code complete.
    Risks: formatting foreign hunks.
    Verify: inspect formatter diff; never stage unrelated hunks.
34. `[completed_with_unrelated_suite_failures]` Run focused tests, migration integrity, related regression
    suites, then full `php artisan test`.
    Почему: behavior and backward compatibility.
    Files: tests.
    Dependencies: Pint.
    Risks: independent shared-tree failures.
    Verify: final related run — `52` tests / `367` assertions; isolated
    forward/down/forward migration green. Full `2 065`-test process completed
    under `1G`: `2 046` passed, `11` skipped, `7` failed and `1` errored.
    Task 93 query/projection failures from that run were fixed and retested;
    remaining isolated failures belong to foreign
    `WebAccountManagementTest` session state and missing
    `SeasonvarImportDispatchBatcher`.
35. `[completed]` Run translation/docs/static/routes/config checks and
    `npm run build`.
    Почему: Blade/Tailwind/asset assumptions and docs policy.
    Files: translations/docs/frontend.
    Dependencies: final UI.
    Risks: foreign build failures.
    Verify: RU/EN playback keys matched; `composer validate --strict`,
    `PHPStan`, task-scoped Rector, route list and `npm run build` passed.
    Global Rector reports only two foreign collection-category return-type
    changes.
36. `[completed]` Update canonical architecture/data/import/notifications/
    frontend/security/performance docs, README visitor section and Russian
    CHANGELOG.
    Почему: product-visible behavior and repository workflow.
    Files: applicable canonical owners.
    Dependencies: verified final behavior.
    Risks: duplicate/outdated documentation, foreign hunks.
    Verify: owner docs, docs map, README visitor sections and dated Russian
    CHANGELOG describe only implemented behavior.
37. `[completed]` Reread requirements/spec/plan and finalize compliance matrix.
    Почему: mandatory final gate.
    Files: requirements and plan.
    Dependencies: all verification.
    Risks: unchecked requirement marked completed.
    Verify: evidence or honest unresolved/not_applicable per row.
38. `[completed]` Inspect `git status`, task diff/stat, cached/uncached/untracked,
    branch/remote, secrets/debug/formatting/unrelated files.
    Почему: huge foreign shared tree.
    Files: Git index/worktree.
    Dependencies: docs complete.
    Risks: accidental foreign commit or secret.
    Verify: alternate/path-limited index and exact cached diff.
39. `[pending]` Commit only Task 93 changes on existing `main`.
    Почему: user requested delivery and AGENTS requires main.
    Files: exact verified task paths/hunks.
    Dependencies: all gates green or documented external blockers.
    Risks: pre-commit staging foreign work.
    Verify: commit hash/message and post-commit scoped status.
40. `[pending]` Ordinary push to configured `origin/main`.
    Почему: requested remote delivery.
    Files: Git remote state.
    Dependencies: local commit.
    Risks: HTTPS authentication or protected branch.
    Verify: exact push output; failure remains `unresolved`, no force.

## Verification evidence

- TDD RED/GREEN cycles covered schema, validation, resolver precedence,
  hidden variants, explicit subtitle language, card labels, recommendation
  ranking, notification idempotency/privacy and Livewire save/reset behavior.
- Final related regression:
  `php artisan test` over the eleven Task 93 and adjacent contract classes —
  `52` passed, `367` assertions.
- Browser QA with Playwright-managed Chromium:
  `1440×1200` and `390×844`, HTTP `200`, save success announced through the
  Livewire region, no horizontal overflow, console error or failed request.
  The run found and fixed an island placement bug in the save announcement
  and a prohibited nested scroll container in hidden translations.
- Separate SQLite verified all migrations, direct Task 93
  down/forward round-trip, foreign keys and representative
  `EXPLAIN QUERY PLAN`.
- `Pint`, `PHPStan`, task-scoped Rector, Composer validation, route listing,
  translation parity and Vite build passed. Repository-wide Rector and full
  PHPUnit retain only the explicitly recorded foreign-tree failures above.
