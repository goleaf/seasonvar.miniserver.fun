# Приватные iCalendar feed'ы — implementation plan

Дата: 26.07.2026.

Статус: implementation_verified_pending_delivery.

Design:
[`../specs/2026-07-26-private-release-calendar-feeds-design.md`](../specs/2026-07-26-private-release-calendar-feeds-design.md).

## Critical — preparation и contracts

1. `[completed]` Прочитать root `AGENTS.md`,
   `docs/requirements/index.md` и все применимые canonical requirements.
   Причина: постоянные требования имеют приоритет. Evidence: task plan
   compliance matrix; риск — пропуск cross-feature boundary; проверка —
   повторное чтение перед final.
2. `[completed]` Подтвердить PHP/Laravel/Livewire/Tailwind/Vite/PHPUnit,
   database connections и package inventory. Причина: version-specific API;
   файлы не меняются; проверка — Boost/application and CLI evidence.
3. `[completed]` Проверить branch/remotes/status/staged/unstaged/untracked.
   Причина: shared dirty tree Tasks 57–59; риск — чужой commit; проверка —
   exact-path diff и alternate index.
4. `[completed]` Проследить route → full-page Livewire → query/visibility →
   model/migration/notifications/import/account lifecycle. Причина: сохранить
   canonical calendar behavior; проверка — file inventory и schema evidence.
5. `[completed]` Сравнить one-token query, plaintext rows и typed
   hash+encrypted rows. Причина: independent revocation/security; проверка —
   approved design.
6. `[completed]` Обновить canonical owner до PHP edits. Причина: новый
   permanent iCalendar contract заменяет прежнее явное ограничение; файлы —
   `docs/release-calendar.md`.

## Critical — TDD и database

7. `[completed]` Добавить RED feature tests для route/headers/invalid token.
   Файл: новый `tests/Feature/ReleaseCalendarFeedTest.php`; зависимости:
   calendar fixtures; риски: тест не должен повторять renderer; проверка —
   focused test падает по отсутствующему route/table.
8. `[completed]` Добавить RED scope tests: all, collection, episode, season,
   title, translation, subtitle, optional title combination, empty/invalid.
   Причина: основная функциональность; тот же test + Livewire test; риск:
   visibility false positives; проверка — exact includes/excludes.
9. `[completed]` Добавить RED lifecycle/security tests: owner/foreign user,
   regeneration, deletion, hard limit, account restriction/delete/export,
   target soft/hard delete и title merge. Причина: capability revocation и
   IDOR; проверка — old token 404 и secret absence.
10. `[completed]` Создать migration через Artisan. Файл:
    `database/migrations/*_create_release_calendar_feeds.php`; причина:
    immutable deployed baseline; риски: SQLite/FK/index names/rollback;
    проверка — migrate/rollback/migrate + foreign key/integrity checks.
11. `[completed]` Создать `ReleaseCalendarFeedScope`, model, policy,
    relationships и schema `feedsReady()`. Причина: typed invariants и rolling
    deploy; риски: accidental serialization/decryption; проверка — model
    tests, hidden attributes and schema guard.
12. `[completed]` Добавить schedule feed-window indexes только после before/after
    EXPLAIN. Причина: status-free OR range query; риск: write amplification;
    проверка — selected plan and duplicate-index audit.

## Critical — backend

13. `[completed]` Вынести reusable personal eligibility constraint без изменения
    result set. Файлы: новый service и минимальная интеграция
    `ReleaseCalendarQuery`; риск: foreign Task 58 hunk/regression; проверка —
    existing personal/default calendar and recommendation suppression tests.
14. `[completed]` Реализовать token generator/hash и feed lifecycle service с
    transactions/locks/limits/policy. Причина: race-safe create/regenerate;
    риски: collision/decryption/partial write; проверка — concurrency-like
    repeated mutations, unique constraints and rollback.
15. `[completed]` Реализовать target resolver/normalizer. Причина: client input
    untrusted; риски: owner collection IDOR, wildcard translation; проверка —
    localized validation matrix and foreign resource tests.
16. `[completed]` Реализовать `ReleaseCalendarFeedQuery` с canonical visibility,
    exact precision window, scopes, projection/eager load, deterministic sort
    и hard limit. Риски: N+1/full scan/hidden target; проверка — query count,
    scope tests, EXPLAIN.
17. `[completed]` Реализовать RFC 5545 renderer с CRLF/folding/escaping/stable
    UID/time/date/range/status. Риски: invalid UTF-8, fake dates, formula-like
    text; проверка — unit tests с multibyte/special text and parser-independent
    string assertions.
18. `[completed]` Реализовать stateless responder и named limiter. Файлы:
    responder, `AppServiceProvider`, `routes/web.php`; риски: token leak,
    session cookie/cache/indexing/existence oracle; проверка — middleware and
    exact headers/status tests.
19. `[completed]` Обновить title merge и account export. Причина: system-wide
    lifecycle; риски: secret export/stranded target; проверка — focused tests.

## High — Livewire/frontend

20. `[completed]` Создать child `ReleaseCalendarFeedManager` с server validation,
    owner collection options, bounded title search/select и create/
    regenerate/delete actions. Риски: oversized parent render, tampered IDs,
    double submit; проверка — Livewire tests and query count.
21. `[completed]` Подключить manager только в personal calendar view. Файлы:
    parent Blade/component; риск: public/private output crossover; проверка —
    guest/public pages do not render secrets.
22. `[completed]` Добавить RU/EN translations и accessible responsive Blade.
    Риски: hardcoded strings/overflow/labels/loading/error; проверка —
    translation tests, 390/1440 browser views and axe.
23. `[completed]` Расширить optional `release-calendar.js`: validate same-origin
    feed URL, Clipboard API + fallback, status live region, Google action;
    Apple remains safe rendered `webcal` link. Риски: DOM XSS/storage/open
    redirect/popup; проверка — JS unit-by-browser behavior, console/log scan.
24. `[completed]` Не добавлять third-party JS/package. Причина: существующий
    stack достаточен; статус `not_applicable` для dependency approval.

## High — performance/security/cross-feature review

25. `[completed]` Проверить SQL query count и production-size read-only EXPLAIN.
    Файлы: evidence docs/tests; риск: OR temp sort; проверка — before/after
    actual plan without invented SLA.
26. `[completed]` Проверить SQL injection, XSS, CSRF, mass assignment, IDOR,
    secrets/logs/cache keys, rate limit and response headers. Проверка —
    focused tests + repository pattern scan.
27. `[completed]` Проверить collections/title soft/hard delete, merge, account
    restriction/export/delete, locales, cache, SEO/sitemap, notifications,
    imports, Premium/region visibility, admin and API compatibility.
28. `[completed]` Проверить no queue/cache/service-worker/new infrastructure и
    bounded memory/response/query window.

## High — verification

29. `[completed]` Запустить focused unit/feature/Livewire tests после каждого
    GREEN; любые failures разбирать systematic debugging.
30. `[completed_with_shared_tree_exception]` Выполнить Pint точным списком
    Task 60 PHP-файлов с `--format agent`; broad `--dirty` не использован,
    потому что он затронул бы foreign Tasks 57–61.
31. `[completed]` Выполнить PHP syntax, applicable Larastan/PHPStan и целевой
    Rector dry-run через существующие scripts. Task scope прошёл без ошибок;
    global Rector указывает только на два foreign collection-файла.
32. `[completed_with_preexisting_global_blockers]` Выполнить полный
    `php artisan test`. Calendar matrix прошла 36/614; broad run завершил
    1 832 теста и воспроизвёл один unrelated auth failure и один unrelated
    missing importer class; накопительный GD memory stop отдельно проверен
    свежим успешным exact test.
33. `[completed]` Выполнить process-scoped config/route/view cache validation,
    route listing, changelog policy и docs check; production caches не
    изменялись.
34. `[completed]` Выполнить `npm run build`, проверить chunk sizes и отсутствие
    unrelated dependencies.
35. `[completed]` Выполнить Playwright desktop/mobile/a11y/console QA с
    stateless feed smoke и no horizontal overflow.
36. `[completed]` Выполнить migration/migrate evidence только на disposable
    test SQLite; production DB не изменять.

## Critical — docs/final/Git

37. `[completed]` Обновить `docs/release-calendar.md`, `DATA_RELATIONS.md`,
    `authorization.md`, `security.md`, `performance.md`, `caching.md`,
    `frontend.md`, `notifications.md`/`administration.md` только если contract
    затронут; не дублировать owners.
38. `[completed]` Проверить и осмысленно обновить русский `README.md` и
    датированный русский `CHANGELOG.md`; generated blocks не редактировать.
39. `[completed]` Перечитать requirements и обновить compliance matrix точным
    evidence/status; unrelated full-suite/Rector blockers записаны без
    маскировки.
40. `[completed]` Искать legacy iCalendar limitation, duplicate
    routes/services, stale cache/SEO controls, TODO/debug/secrets and dead UI
    по repository; competing feed route/service и task-scope debug/secret
    material не найдены.
41. `[pending]` Проверить `git status`, branch, remotes, staged/unstaged/
    untracked, diff/stat/cached, secrets/debug/formating/unrelated.
42. `[pending]` Собрать exact-path/hunk commits в `main` без `git add .`,
    сохранив foreign Tasks 57–59 index/worktree.
43. `[pending]` Выполнить обычный push current `main`; force/rebase/new branch
    запрещены. При auth/clean-tree external block сохранить commits и точную
    ошибку.
