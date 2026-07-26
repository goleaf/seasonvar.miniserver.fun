# План реализации группировки событий календаря релизов

Дата: 26.07.2026.

Design:
[`2026-07-26-release-calendar-episode-batches-design.md`](../specs/2026-07-26-release-calendar-episode-batches-design.md).

Статусы: `pending`, `in_progress`, `completed`, `skipped` с причиной.

## Scope

Сгруппировать синхронные episode-bound события одного тайтла в одну
пагинируемую HTML-карточку с точным summary диапазонов и нативно раскрываемым
списком. Не изменять canonical event rows, notification/feed/admin/import
boundaries, routes, API, cache, schema или dependencies.

## Ожидаемые изменяемые файлы

- `app/DTOs/ReleaseCalendar/ReleaseScheduleCardData.php`;
- `app/DTOs/ReleaseCalendar/ReleaseScheduleGroupData.php` (new);
- `app/Services/ReleaseCalendar/ReleaseCalendarEpisodeRangeFormatter.php`
  (new, только если RED докажет пользу отдельной чистой boundary);
- `app/Services/ReleaseCalendar/ReleaseCalendarQuery.php`;
- `app/Services/ReleaseCalendar/ReleaseCalendarSeoPresenter.php`;
- `app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php`;
- `resources/views/livewire/release-calendar/release-calendar-page.blade.php`;
- `lang/ru/calendar.php`, `lang/en/calendar.php`;
- `tests/Feature/ReleaseCalendarDefaultViewTest.php`;
- `tests/Feature/ReleaseCalendarGroupingTest.php` (new);
- `tests/browser/release-calendar.spec.js`;
- `docs/release-calendar.md`, `docs/frontend.md`, `docs/performance.md`;
- `docs/plans/current-task-plan.md`;
- `README.md`, `CHANGELOG.md`;
- этот plan и связанный design.

Discovery может сократить список, но любое расширение production scope сначала
обновляет design, этот plan и current-task matrix.

## Защищённые contracts

- ветка `main`, чужие staged/unstaged/untracked изменения;
- все calendar/localized/legacy/admin/feed URI и route names;
- query parameters `type`, `status`, `sort`, `title`, `calendarPage`;
- `ReleaseScheduleEntry` schema, UUID, logical key, enums, corrections;
- visibility, personal eligibility, subscription authorization/rate limits;
- importer observers, notification dispatch/deduplication, private ICS;
- API/JSON, title routes, catalog availability, SEO canonical/`hreflang`;
- month event counts, cache keys/invalidation, queue/scheduler;
- SQLite и framework-supported configured engines;
- RU/EN locale parity, Livewire history, mobile layout и keyboard access.

## Риски и решения

| Риск | Приоритет | Решение | Проверка |
| --- | --- | --- | --- |
| Пачка разрывается между страницами | critical | Пагинировать group projection до hydration | 25-member/`per_page=24` regression |
| Склейка разных релизов | critical | Полный server-owned semantic key + individual fallback | type/status/time/season/translation matrix |
| Ложный диапазон через пропущенные номера | critical | Форматировать contiguous runs | `185–190, 192, 194` unit/feature assertion |
| `only_full_group_by`/SQLite mismatch | critical | Group/order только по selected dimensions или aggregates | SQLite tests + SQL inspection; portable syntax review |
| N+1/слишком широкая hydration | high | Одна member query и projected eager loads | query-budget regression |
| Медиаварианты одной серии создают ложную пачку | high | Presentation dedupe по canonical episode number; event rows не изменяются | duplicate-variant fixture |
| SEO меняет identity | high | SEO принимает group DTO и dedupe по title URL | canonical/JSON-LD regressions |
| Livewire DOM нестабилен | high | Group-derived stable `wire:key`; native details | Livewire pagination/browser smoke |
| Перевод становится identity | high | Translation strings только presenter; key uses stable codes/scalars | RU/EN parity + source inspection |
| Shared worktree попадает в commit | critical | Alternate index/path-limited diff; no reset/stash/clean | staged diff and tree audit |
| Push недоступен | medium | Обычный non-force push; точная ошибка как unresolved | фактический command result |

## Живой checklist

### A. Анализ текущего поведения

1. `[completed][critical]` Прочитать root instructions, requirements index,
   canonical owners и task-specific calendar docs.
   - Почему: постоянные правила имеют precedence.
   - Файлы: `AGENTS.md`, `docs/requirements/*`, owner docs.
   - Зависимости: актуальный repository snapshot.
   - Риск: пропустить новый постоянный контракт.
   - Проверка: compliance matrix со ссылками/evidence.

2. `[completed][critical]` Зафиксировать runtime/framework/package/database и
   frontend stack через установленное приложение и Boost.
   - Почему: решение должно соответствовать Laravel 13.22, Livewire 4.3,
     Tailwind 4.3 и SQLite.
   - Файлы: `composer.lock`, `package-lock.json`, app info.
   - Риск: использовать API другой версии.
   - Проверка: фактические version commands/Boost output.

3. `[completed][critical]` Проверить Git branch, upstream, remote и весь
   shared working tree до edits.
   - Почему: параллельные изменения нельзя перезаписывать или включать.
   - Модули: Git index/worktree.
   - Риск: смешанный commit.
   - Проверка: baseline `git status --short --branch`, scoped diffs.

4. `[completed][high]` Проследить routes → Livewire → query → DTO → SEO →
   Blade → tests, а также feed/notification/admin/import dependants.
   - Почему: grouping не должна менять event identity.
   - Файлы: calendar routes/classes/views/tests.
   - Риск: случайно сгруппировать iCalendar или notification payload.
   - Проверка: repository-wide symbol/route search.

5. `[completed][high]` Сравнить client, post-pagination PHP и two-phase group
   pagination.
   - Почему: выбрать корректный page contract.
   - Риск: визуальный fix с неверным paginator.
   - Проверка: approved design с отклонёнными альтернативами.

### B. Требования, дизайн и документация до кода

6. `[completed][critical]` Записать design с semantic key, data flow,
   accessibility, errors, security, performance и rollback.
   - Файлы: linked spec.
   - Риск: неоднозначная склейка.
   - Проверка: self-review против prompt и calendar owner.

7. `[unresolved_shared_worktree][medium]` Зафиксировать отдельный design
   commit.
   - Почему: brainstorming workflow требует reviewable design checkpoint.
   - Зависимости: repository hooks.
   - Риск: чужой `CHANGELOG.md` блокирует hook.
   - Проверка: обычная hook-enabled попытка; обход hooks запрещён.

8. `[completed][critical]` Актуализировать current task plan и
   task-specific compliance matrix.
   - Файлы: этот plan, `docs/plans/current-task-plan.md`.
   - Риск: shared file уже содержит чужие hunks.
   - Проверка: isolated appended section, позже path/hunk-limited staging.

### C. TDD

9. `[completed][critical]` Добавить RED happy-path test для серий 185–194.
   - Почему: закрепить одну карточку, summary и раскрытый exact list.
   - Файлы: new grouping feature test.
   - Зависимости: factories/current calendar route.
   - Риск: assertion проверяет лишь текст, не количество cards.
   - Проверка: DOM/XPath count + visible strings.

10. `[completed][critical]` Добавить RED edge matrix: gap, season, type,
    status, timestamp, translation, no episode/no number.
    - Почему: не допустить ложную склейку.
    - Риск: чрезмерно сложная fixture.
    - Проверка: отдельные точные DOM group counts/labels.

11. `[completed][critical]` Добавить RED pagination test для одной пачки больше
    `per_page` и нескольких соседних групп.
    - Почему: paginator должен считать карточки.
    - Риск: тест случайно зависит от default sort/time window.
    - Проверка: fixed clock, explicit timestamps, page links/total.

12. `[completed][high]` Добавить query-budget regression.
    - Почему: detail list не должен вызвать query-per-episode.
    - Риск: framework/system queries создают нестабильный абсолютный лимит.
    - Проверка: сравнить calendar-domain query count для 2 и 20 members.

13. `[completed][high]` Запустить focused RED и сохранить точные ожидаемые
    failures до production implementation.

### D. Backend и SQL

14. `[completed][critical]` Разделить общий filtered base query и group-page
    query без изменения visibility/personal/filter semantics.
    - Файлы: `ReleaseCalendarQuery`.
    - Риск: constraints расходятся между фазами.
    - Проверка: filters/sorts/personal regression.

15. `[completed][critical]` Реализовать portable group projection и
    LengthAware pagination.
    - Зависимости: Laravel grouped pagination behavior.
    - Риск: `only_full_group_by`, NULL equality, unstable tie.
    - Проверка: SQL inspection, deterministic aggregate ID tie-break,
      SQLite focused tests.

16. `[completed][critical]` Реализовать bounded member hydration для максимум
    `per_page` group keys одним query.
    - Риск: OR conditions теряют null semantics либо broad-match rows.
    - Проверка: exact membership tests и bindings-only raw inspection.

17. `[completed][high]` Удалить неиспользуемые eager loads и оставить
    минимальные title/episode projections.
    - Почему: снизить query count/payload.
    - Риск: скрытый Blade/SEO consumer.
    - Проверка: full symbol search, focused rendering tests, strict lazy-load
      test if project enables it.

18. `[completed][high]` Создать typed group DTO и чистый range formatter только
    при подтверждённой cohesive responsibility.
    - Риск: лишняя абстракция.
    - Проверка: small API, PHPStan generics/types, direct unit cases.

19. `[completed][high]` Адаптировать SEO presenter к group projection без
    раскрытия members или изменения canonical URLs.
    - Проверка: JSON-LD/canonical/robots tests.

### E. Frontend, localization и UX

20. `[completed][critical]` Вывести batch headline и native
    `<details>/<summary>` с semantic list.
    - Файлы: calendar Blade.
    - Риск: duplicate H2, invalid nested interactive content.
    - Проверка: DOM validation/XPath, keyboard browser smoke.

21. `[completed][high]` Сохранить single-event card, buttons, countdown,
    loading/error/empty/pagination и subscription behavior.
    - Риск: регрессия layout/action.
    - Проверка: existing focused tests + browser.

22. `[completed][high]` Добавить RU/EN batch/action/detail/a11y keys с
    placeholder parity.
    - Риск: hardcoded text или key mismatch.
    - Проверка: translation completeness test and syntax.

23. `[completed][high]` Проверить mobile/tablet/desktop, long title/range,
    poster/no-poster, expanded/collapsed state и отсутствие overflow.
    - Проверка: Playwright screenshot/DOM/console.

### F. Validation, authorization, security и errors

24. `[completed][high]` Подтвердить, что новые request fields отсутствуют, а
    существующая allowlist normalization выполняется до group query.
    - Проверка: invalid/empty/zero/filter combination regressions.

25. `[completed][critical]` Повторно проверить visibility, personal ownership,
    subscription authorization, XSS escaping, raw bindings и no-private-data
    DTO.
    - Проверка: guest/user/negative feedback tests и scoped security review.

26. `[completed][high]` Сохранить существующий exception/report/user-error flow
    без empty catch и подробных ошибок.
    - Проверка: forced query failure regression или existing test evidence.

### G. Производительность и database review

27. `[completed][critical]` Измерить SQL count/bindings и выполнить `EXPLAIN`
    основных group/member queries на доступной SQLite.
    - Почему: индекс нельзя добавлять вслепую.
    - Риск: group expression вызывает full temp sort.
    - Проверка: actual plan и сравнение с bounded period/filter/order indexes.

28. `[completed][high]` Проверить существующие индексы
    `release_schedule_entries`; добавить migration только при конкретном
    доказанном missing index.
    - По умолчанию schema change `not_applicable`.
    - Проверка: schema inventory + EXPLAIN.

29. `[completed][medium]` Подтвердить отсутствие cache benefit/need.
    - Почему: grouping deterministic и page-specific; cache не должен скрывать
      плохой query.
    - Проверка: cache owner/invalidation unchanged.

### H. Проверки

30. `[completed][critical]` Запустить focused grouping/default/personal/SEO
    calendar tests.

31. `[completed][high]` Запустить весь связанный calendar/feed/admin/import
    matrix.

32. `[completed_with_repository_limits][high]` Выполнить `./vendor/bin/pint --dirty --format agent`,
    scoped PHPStan/Rector dry-run, translation/docs checks и `npm run build`.
    - Task-scoped Pint, syntax, Rector, global PHPStan, translation parity,
      dependency audits и Vite прошли.
    - Full Rector предлагает только чужие `never`-типы в collection classes,
      managed-doc check требует чужой `docs/MAINTENANCE_LOG.md`, а общий
      CHANGELOG policy останавливается на чужой англоязычной записи.

33. `[unresolved_repository_baseline][high]` Выполнить полный `php artisan test`; при resource limit
    повторить диагностически с документированной memory boundary, не скрывая
    unrelated failures.
    - Монолитный процесс дважды исчерпал закреплённые в `phpunit.xml` 256 МБ
      на существующем large-HTML cache test; отдельно он прошёл 1/9.
    - Все 359 test classes дополнительно выполнены шестью process-bounded
      пакетами: 1 953 passed, 11 expected skipped, 2 unrelated failures.
      Один импортёрский тест ссылается на отсутствующий и в `HEAD` класс
      `SeasonvarImportDispatchBatcher`; browser-session test независимо
      воспроизводит прежний `sessionStatus=null`.

34. `[completed][high]` Выполнить Playwright desktop/mobile/tablet calendar
    smoke с раскрытием batch и проверкой console/page/network errors.
    - Полный calendar spec: 9/9; дополнительная extended batch matrix: 7/7.

### I. Документация и final review

35. `[completed][high]` Обновить canonical `docs/release-calendar.md`,
    `docs/frontend.md` и measured `docs/performance.md`.

36. `[completed][critical]` Обновить русский visitor-facing `README.md` и
    отдельную русскую датированную запись `CHANGELOG.md`.

37. `[completed][critical]` Перечитать applicable requirements/design/plan,
    обновить compliance matrix и выполнить repository-wide legacy/dead/
    duplicate/TODO/debug/secret scan.

38. `[pending][critical]` Проверить `git status`, unstaged/staged/untracked,
    exact `git diff`, `--stat`, staged diff, branch/upstream/remote и
    task-only scope.

### J. Commit и push

39. `[pending][critical]` Сформировать alternate/path-limited index только из
    task files/hunks, запустить hooks и создать логический implementation
    commit в `main`.

40. `[pending][critical]` Проверить commit tree/hash/message и чистоту
    task scope; чужие изменения не добавлять и не сбрасывать.

41. `[pending][critical]` Выполнить обычный `git push origin main` без force.
    Внешний отказ сохранить как `unresolved` с точной командой/ошибкой/hash.

## Compliance matrix

| Requirement/domain | Статус | Evidence / gate |
| --- | --- | --- |
| Root/index/canonical fresh read | `completed` | Mandatory owners и task evidence повторно прочитаны перед Git-аудитом |
| Installed versions/database | `completed` | PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, Boost 2.4.13, Tailwind 4.3.2, PHPUnit 12.5.32, SQLite |
| Existing implementation first | `completed` | Routes/query/DTO/page/Blade/SEO/tests/feed/notification/admin/import traced |
| Alternatives and authorization | `completed` | Repeated explicit instruction authorizes recommended two-phase design |
| Design spec | `completed` | Linked design reviewed; hook-enabled design commit blocked by foreign changelog |
| Current plan/files/contracts/risks | `completed` | Plan, expected files, protected contracts и discovery update сохранены |
| Schema/migration/index | `not_applicable` | SQLite EXPLAIN выбирает существующие public time/date indexes; DDL не оправдан |
| Input validation | `already_compliant` | Existing enum/integer normalization unchanged; combined-filter regression green |
| Authorization/privacy | `already_compliant` | Existing visibility/personal/action boundaries unchanged; escaped DTO/Blade review complete |
| Localization | `completed` | RU/EN batch keys и placeholders совпадают; localized feature test green |
| Accessibility/mobile | `completed` | Native details, 44 px summary, keyboard focus, no-overflow; Playwright 9/9 standard + 7/7 extended |
| Query/pagination performance | `completed` | Group paginator, one member query, constant 2-vs-20 query count and EXPLAIN evidence |
| Cache | `not_applicable` | Keys/TTL/invalidation unchanged |
| Notifications/feed/admin/import/API | `already_compliant` | Canonical rows untouched; related feed/default calendar tests green |
| Error handling/logging | `already_compliant` | Existing report + localized error unchanged |
| TDD RED/GREEN | `completed` | Initial 3 RED failures; focused 11 tests/56 assertions and related 40/303 green |
| Full/static/build/browser verification | `completed_with_repository_limits` | Focused 11/56, related 137/1 228, translation 3/74 795, global PHPStan, scoped Rector/Pint/syntax, audits/build and browser green; monolithic suite/full Rector/docs have recorded foreign baseline blockers |
| Canonical docs/README/CHANGELOG | `completed` | Calendar/frontend/performance owners, visitor README and dated Russian changelog updated |
| Final legacy/security/diff audit | `completed` | Task scope, dependencies, duplicate symbols, debug/TODO, secrets, routes and foreign files checked before staging |
| Commit/push main | `pending` | Exact task scope; external failure honest |
