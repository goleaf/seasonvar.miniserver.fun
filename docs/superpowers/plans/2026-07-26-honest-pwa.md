# Implementation plan: честная PWA, offline library/help и Web Push

Дата: 26.07.2026.

Статус: `preparation_completed_tdd_pending`.

Design:
[`2026-07-26-honest-pwa-design.md`](../specs/2026-07-26-honest-pwa-design.md).

Каждый пункт содержит причину, затрагиваемые модули, зависимости, риск и
проверку. Checklist не имеет искусственного ограничения по размеру.

## Critical — требования, baseline и contracts

1. `[completed]` Fresh-read root instructions, requirements index и все
   применимые owners. Почему: repository docs — source of truth. Файлы:
   `AGENTS.md`, `docs/requirements/*`, security/cache/frontend/operations.
   Зависимости: нет. Риск: legacy PWA intent принять за текущий факт.
   Проверка: read evidence и новый canonical owner.
2. `[completed]` Проверить PHP/Laravel/packages/frontend/DB/CI/Git. Почему:
   version-dependent API и shared-tree isolation. Файлы: lock files, runtime,
   `.github`, Git metadata. Зависимости: 1. Риск: stale assumptions.
   Проверка: exact CLI/Boost inventory.
3. `[completed]` Проследить routes/layout/library/help/sync/logout/notification/
   queue/poster/deletion boundaries. Почему: reuse существующих owners. Файлы:
   связанные classes/views/tests. Зависимости: 1–2. Риск: duplicate sync,
   notification или SSRF layer. Проверка: data-flow в design.
4. `[completed]` Зафиксировать cache denylist, data minimization, VAPID,
   offline queue, deployment и rollback. Почему: capability cross-feature и
   production-affecting. Файлы: canonical PWA owner/design. Зависимости: 3.
   Риск: offline-video promise или private response cache. Проверка: security
   review design.
5. `[completed]` Зафиксировать expected/protected files, migrations/routes/
   translations/cache/permission risks и task matrix. Почему: обязательный
   workflow. Файлы: current plan/compliance matrix. Зависимости: 4. Риск:
   scope drift. Проверка: перечитать plan до RED.

## Critical — TDD public PWA shell

6. `[pending]` RED manifest route tests: name/id/scope/start URL/display/icons,
   MIME/cache/security headers. Почему: installability contract. Файлы: new
   `PwaHttpContractTest`. Зависимости: prepared plan. Риск: static server bypass.
   Проверка: focused test fails because route absent.
7. `[pending]` RED service-worker tests: scope header, no-store update response,
   exact asset list, strict media/private denylist. Почему: safest boundary.
   Файлы: same test + source contract test. Зависимости: 6. Риск: token/media
   cache. Проверка: expected missing worker.
8. `[pending]` RED offline full-page Livewire route/layout tests. Почему: new
   HTML route convention and no CSRF/session leakage. Файлы: offline tests.
   Зависимости: 6. Риск: cached CSRF/Set-Cookie. Проверка: missing route and
   cache headers.
9. `[pending]` Реализовать manifest responder, worker asset resolver/template,
   offline Livewire component/layout/view and routes. Почему: real install
   shell. Файлы: PWA responders/services/Livewire/resources/routes. Зависимости:
   RED 6–8. Риск: dev build manifest missing. Проверка: GREEN in production and
   controlled no-store fallback when assets unavailable.
10. `[pending]` Добавить source SVG, 192/512/maskable/touch PNG и manifest/head
    links. Почему: browser install requirements. Файлы: `resources/images`,
    `public/icons`, layout. Зависимости: 9. Риск: empty/corrupt icon.
    Проверка: image dimensions/type and browser manifest inspection.

## Critical — offline snapshots через TDD

11. `[pending]` RED owner-only library snapshot tests: auth/active, visible
    titles, minimal fields, bound/order, versions, private no-store. Почему:
    personal offline copy. Файлы: `PwaLibrarySnapshotTest`. Зависимости: 5.
    Риск: IDOR/private progress leak. Проверка: guest 302/401 contract and
    cross-user exclusion.
12. `[pending]` Реализовать bounded projection/query/responder. Почему:
    no Blade query and no full model serialization. Файлы:
    `Services/Pwa/*`, routes. Зависимости: RED 11. Риск: N+1/large payload.
    Проверка: GREEN and query budget.
13. `[pending]` RED public help snapshot tests: only published `everyone`,
    locale/fallback, sanitized plain text, bound/public cache. Почему:
    trustworthy offline help. Файлы: `PwaHelpSnapshotTest`. Зависимости: 5.
    Риск: staff/Premium/private article leak. Проверка: explicit excluded rows.
14. `[pending]` Реализовать help snapshot query/responder. Почему: offline help
    without caching authenticated page HTML. Файлы: PWA help service/routes.
    Зависимости: RED 13. Риск: unsafe Markdown/links. Проверка: no HTML/script
    in payload.
15. `[pending]` RED owner-visible poster proxy tests: visibility, SSRF,
    redirects/content type/size, cache headers. Почему: poster cache without
    direct third-party URLs. Файлы: `PwaPosterResponderTest`. Зависимости:
    existing poster guard. Риск: SSRF/data amplification. Проверка:
    `Http::preventStrayRequests()`.
16. `[pending]` Реализовать owner-scoped poster responder через existing guard.
    Почему: no duplicate remote policy. Файлы: PWA responder/routes. Зависимости:
    RED 15. Риск: hidden title access. Проверка: visible query and cross-user
    denial.

## Critical — safe offline action queue через TDD

17. `[pending]` RED Form Request tests for only `watchlist.set`/`rating.set`,
    UUID, strict booleans/int/null, expected versions, extra fields, max 50.
    Почему: frontend untrusted. Файлы: `PwaActionSyncTest`. Зависимости:
    existing API sync. Риск: progress/history sneaks into offline queue.
    Проверка: 422 Russian/English errors.
18. `[pending]` RED endpoint auth/CSRF/rate-limit/idempotency/conflict/not-found
    tests. Почему: server remains authority. Файлы: same feature test.
    Зависимости: 17. Риск: replay/IDOR. Проверка: canonical mutation receipts
    and versions.
19. `[pending]` Реализовать thin responder reusing `ApiSyncMutationService`.
    Почему: one business owner. Файлы: Form Request/responder/routes/rate
    limiter. Зависимости: RED 17–18. Риск: divergent response envelope.
    Проверка: GREEN and API regression.

## Critical — push subscription и VAPID через TDD

20. `[pending]` RED migration/model tests: encrypted endpoint, unique hash,
    installation ownership, health casts, FK cascade/indexes/down. Почему:
    durable subscription state. Файлы: migration/model test. Зависимости: plan.
    Риск: endpoint plaintext/duplicate subscription. Проверка: raw DB value
    differs and schema round trip.
21. `[pending]` Создать additive reversible migration/model/User relationship.
    Почему: storage owner. Файлы: migration/model/User. Зависимости: RED 20.
    Риск: SQLite index names/cascade. Проверка: GREEN/rollback/forward.
22. `[pending]` RED endpoint guard tests for HTTPS, credentials, standard port,
    configured browser hosts, IP/private names. Почему: push endpoint is SSRF
    target. Файлы: unit test/service. Зависимости: config decision. Риск:
    overbroad external POST. Проверка: common provider URLs accepted, others
    rejected.
23. `[pending]` RED subscription write tests: auth/CSRF/rate limit, valid
    endpoint/installation/locale, transfer existing endpoint, current revoke,
    logout cleanup, account cascade. Почему: shared-device privacy. Файлы:
    `WebPushSubscriptionTest`. Зависимости: 21–22. Риск: IDOR/stale account.
    Проверка: exact owner/hash/session mapping.
24. `[pending]` Реализовать subscription Form Requests/service/responders/
    routes/config. Почему: explicit secure write boundary. Файлы: HTTP/PWA
    services/config/routes/auth service. Зависимости: RED 23. Риск: endpoint in
    logs/errors. Проверка: GREEN and secret scan.
25. `[pending]` RED VAPID token tests: ES256/P-256, aud origin, exp <=24h,
    public key and signature format. Почему: standards-compliant delivery.
    Файлы: `VapidTokenFactoryTest`. Зависимости: OpenSSL. Риск: DER/raw mismatch.
    Проверка: decode and OpenSSL verify.
26. `[pending]` Реализовать key decoder/token factory and key-generation
    command without persistence. Почему: production can configure real keys
    without package. Файлы: PWA security services/console command/config.
    Зависимости: RED 25. Риск: secret printed to shared logs. Проверка: command
    warning + tests; documentation requires secure terminal.
27. `[pending]` RED sender tests: empty body, VAPID headers, TTL, timeout/no
    redirects, 201 success, 404/410 disable, 429/5xx transient, no secret logs.
    Почему: reliable privacy-safe provider boundary. Файлы:
    `PayloadlessWebPushSenderTest`. Зависимости: 22/25. Риск: private payload or
    unbounded retry. Проверка: `Http::fake/preventStrayRequests`.
28. `[pending]` Реализовать payloadless sender. Почему: real push without
    provider-visible notification data. Файлы: PWA service. Зависимости: RED
    27. Риск: endpoint redirect/log leak. Проверка: GREEN exact request.
29. `[pending]` RED database `NotificationSent` listener/job tests: database
    only, after-commit dispatch, chunked active subscriptions, generic delivery.
    Почему: all real inbox notifications can wake devices. Файлы:
    `WebPushNotificationFanoutTest`. Зависимости: sender/model. Риск:
    synchronous fan-out/import rollback/duplicate job. Проверка: Queue fake and
    job tests.
30. `[pending]` Реализовать listener + queued fan-out job and registration.
    Почему: existing notification boundary reuse. Файлы:
    listener/job/provider. Зависимости: RED 29. Риск: transaction timing.
    Проверка: after-commit and queue regression.

## High — frontend runtime, UX и localization через TDD

31. `[pending]` RED source contract tests for secure-context registration,
    update lifecycle, no auto install/push prompt and strict no-video cache.
    Почему: browser security contract. Файлы: JS contract test. Зависимости:
    design. Риск: fake controls or player reload. Проверка: source assertions.
32. `[pending]` RED IndexedDB contract tests for opaque scope, logout/switch
    cleanup, snapshot validation/TTL/bounds and no tokens/private media.
    Почему: shared-device privacy. Файлы: JS contract test. Зависимости: 31.
    Риск: stale personal data. Проверка: exact stores/schema/cleanup path.
33. `[pending]` RED mutation queue contract tests for max/retention/batch,
    online flush and conflict retention. Почему: no lost/false applied state.
    Файлы: JS contract/browser test. Зависимости: endpoint 19. Риск: infinite
    retry. Проверка: deterministic mocked fetch.
34. `[pending]` Реализовать modular `pwa-storage.js` и `pwa.js`, integrate once
    into `app.js`/Livewire navigation. Почему: no inline business JS. Файлы:
    resources JS. Зависимости: RED 31–33. Риск: repeated listeners/rerenders.
    Проверка: GREEN/build/browser.
35. `[pending]` Реализовать offline shell library/help/action states through
    safe DOM APIs (`textContent`, no `innerHTML`). Почему: accessible offline
    UX without XSS. Файлы: offline view/JS/translations. Зависимости: 12–19/34.
    Риск: Markdown injection/ambiguous saved state. Проверка: a11y/browser.
36. `[pending]` Реализовать bounded poster prefetch/cache/eviction and offline
    blob rendering. Почему: visual offline library. Файлы: JS. Зависимости:
    16/34. Риск: memory/storage growth. Проверка: entry bound and media
    denylist.
37. `[pending]` RED push controls settings tests for availability, labels,
    loading/error/disabled states and RU/EN parity. Почему: no hidden/fake
    channel. Файлы: settings/translation tests. Зависимости: backend 24.
    Риск: permission requested on render. Проверка: no prompt until click.
38. `[pending]` Добавить notification settings card and JS enable/disable
    controls. Почему: explicit consent/revoke. Файлы: Livewire view/page data,
    JS, `lang/{ru,en}`. Зависимости: RED 37. Риск: browser unsupported/denied.
    Проверка: states and mobile keyboard/focus.
39. `[pending]` Добавить generic RU/EN worker notification and safe click route.
    Почему: payloadless push still needs user-visible notification. Файлы:
    worker template/translations/config. Зависимости: 30/34. Риск: arbitrary
    navigation/private content. Проверка: exact same-origin inbox.
40. `[pending]` Интегрировать logout/account-switch cleanup/re-registration of
    existing subscription. Почему: shared-device isolation. Файлы: logout
    button/auth service/JS. Зависимости: 23/34. Риск: another account sees local
    copy. Проверка: browser and feature tests.

## High — security, performance и compatibility

41. `[pending]` Проверить CSRF/IDOR/mass assignment/XSS/SSRF/capability URL/
    secret logging. Почему: new browser/storage/external boundaries. Файлы:
    all changed backend/frontend. Зависимости: implementation. Риск: account
    takeover/data leak. Проверка: negative tests and repository secret scan.
42. `[pending]` Проверить cache matrix and strict player/media exclusions.
    Почему: core user honesty rule. Файлы: worker/config/middleware/player
    regressions. Зависимости: implementation. Риск: offline protected video.
    Проверка: source/runtime cache audit and player suite.
43. `[pending]` Проверить query counts/EXPLAIN/index justification/payload sizes.
    Почему: snapshots and fan-out must stay bounded. Файлы: query/tests/schema.
    Зависимости: backend GREEN. Риск: N+1/large fan-out. Проверка: query log,
    EXPLAIN and byte counts.
44. `[pending]` Проверить auth/locales/search/notifications/help/player/Premium/
    administration/import/API/SEO/service-worker/deployment impact. Почему:
    system-wide rule. Файлы: related regression suites/docs. Зависимости:
    implementation. Риск: protected contract regression. Проверка: matrix.
45. `[pending]` Проверить error handling: storage unavailable, quota, corrupt
    snapshot, unavailable network/provider, stale worker, failed migration,
    queue worker down. Почему: predictable recovery. Файлы: JS/services/docs.
    Зависимости: implementation. Риск: silent data loss. Проверка: unit/browser
    failure cases.

## Medium — documentation и operations

46. `[pending]` Обновить canonical security/cache/frontend/authorization/
    notifications/data relation/architecture owners ссылками на PWA owner.
    Почему: implementation and docs parity. Файлы: thematic docs. Зависимости:
    verified behavior. Риск: duplicate contracts. Проверка: docs map.
47. `[pending]` Обновить service-worker/deployment/environment/rollback/
    production checklist с VAPID, migration, assets, queue, smoke/recovery.
    Почему: production-affecting change. Файлы: operations docs. Зависимости:
    final config. Риск: fake readiness. Проверка: exact commands/no secrets.
48. `[pending]` Обновить README visitor capability/history и русский
    CHANGELOG. Почему: visible product change and hook contract. Файлы:
    README/CHANGELOG. Зависимости: verified result. Риск: claim untested push.
    Проверка: wording distinguishes implemented code from configured
    production readiness.
49. `[pending]` Обновить `.env.example` и configuration inventory without
    secret values. Почему: deploy configuration. Файлы: `.env.example`,
    environment/dependency inventory if needed. Зависимости: config final.
    Риск: committing private key. Проверка: staged secret scan.

## Critical — verification, delivery и final evidence

50. `[pending]` Pint dirty, PHP syntax and focused GREEN tests. Почему: coding
    standard/correctness. Файлы: changed PHP/tests. Зависимости: implementation.
    Риск: formatter overlaps foreign hunks. Проверка: exact commands/diff.
51. `[pending]` Related auth/library/help/sync/notification/player/cache
    regressions. Почему: protected domains. Файлы: existing suites.
    Зависимости: 50. Риск: hidden regressions. Проверка: exact results.
52. `[pending]` Full `php artisan test`, frontend build and available static
    checks. Почему: broad change. Файлы: whole project/build. Зависимости:
    50–51. Риск: foreign shared-tree failures. Проверка: honest attribution,
    never `|| true`.
53. `[pending]` Playwright desktop/mobile/offline/installability/a11y/console/
    network checks. Почему: browser APIs cannot be proven by PHP alone. Файлы:
    browser tests/runtime. Зависимости: build. Риск: HTTPS/push unavailable in
    local context. Проверка: local secure-context support and explicitly
    unresolved production push delivery if keys absent.
54. `[pending]` Reread canonical requirements and task, update compliance
    matrix item by item. Почему: mandatory final gate. Файлы: requirements/
    compliance. Зависимости: all checks. Риск: unsupported completion claim.
    Проверка: evidence links or `unresolved/not_applicable`.
55. `[pending]` Repository-wide legacy/duplicate/TODO/debug/secret/cache/media
    audit and exact diff review. Почему: no stale old PWA controls or accidental
    files. Файлы: repository/diff. Зависимости: 54. Риск: foreign shared edits.
    Проверка: scoped search and staged diff.
56. `[pending]` Stage exact Task 100 paths/hunks on existing `main`, review
    cached diff and create logical commit(s). Почему: preserve foreign work.
    Файлы: only Task 100. Зависимости: green gates. Риск: mixed commit.
    Проверка: alternate/exact index manifest and commit contents.
57. `[pending]` Ordinary non-force push current `main` to configured remote.
    Почему: explicit delivery. Файлы: Git refs. Зависимости: commit. Риск:
    authentication/network/protected branch. Проверка: command result; external
    refusal remains `unresolved` with exact hash/error.
