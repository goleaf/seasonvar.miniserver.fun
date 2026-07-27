# Текущая задача — Task 111: homepage, discussions и query boundaries

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Requirements, versions и repository audit | `completed` | [Task 111 evidence](archive/2026-07-27-task-111-homepage-query-classes-performance.md) |
| Discussion, homepage, query и validation implementation | `completed` | [Plan](../superpowers/plans/2026-07-27-homepage-query-classes-performance.md), [matrix](task-111-homepage-query-classes-performance-compliance.md) |
| Skills, security и compatibility review | `completed` | [Task 111 evidence](archive/2026-07-27-task-111-homepage-query-classes-performance.md) |
| Broad verification и regression repair | `completed` | Backend gate: 2286 tests / 208315 assertions; frontend audit/build, homepage 6/6 и player lifecycle 16 passed / 6 skipped |
| Exact commit и push в `main` | `planned` | Ожидает green verification, exact staged snapshot и lease approval |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
|---|---|---|
| Production migration rehearsal | `unresolved` | Backup/restore и timing создания индекса требуют целевого production evidence |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Постоянные requirements и protected contracts | `completed` | [Compliance matrix](task-111-homepage-query-classes-performance-compliance.md) |
| Guest discussion и homepage facets | `completed` | [Task 111 evidence](archive/2026-07-27-task-111-homepage-query-classes-performance.md) |
| Query/SQL performance и reversible migration | `completed` | [Design](../superpowers/specs/2026-07-27-homepage-query-classes-performance-design.md) |
| Security, authorization и privacy boundaries | `already_compliant` | Public read contracts сохранены; write validation проходит до service layer |
| Production activation | `unresolved` | Требуется production backup/restore и migration timing rehearsal |
| Commit и remote delivery | `in_progress` | Green verification получена; выполняются exact staged review, lease approval, commit и push |

## Последнее подтверждённое evidence

- [Task 111: homepage, discussions и query boundaries](archive/2026-07-27-task-111-homepage-query-classes-performance.md)

## Цель

Исправить гостевую загрузку обсуждений, вывести на главной все жанры, страны
и допустимые годы, сократить и ускорить связанные SQL-запросы, внедрить
проверяемый Query Class pattern без изменения публичных маршрутов/API и
закрыть связанные замечания по Laravel Collections, request-scoped
memoization, nested validation и project skills.

## Активный checklist

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions, repository/production audit | `completed` | PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, SQLite; применимые owners и внешние материалы проверены |
| critical | Workspace lease и exact manifest | `completed` | `task-111-homepage-query-classes-performance`, NUL-delimited paths declared |
| critical | Guest discussion regression | `completed` | RED воспроизвёл `MissingAttributeException`; GREEN service test проверяет roots/replies |
| high | Homepage all-facets projection | `completed` | Один grouped query, все web facets, прежние API limits; 33 focused tests зелёные |
| high | Query Class pattern | `completed` | Три `final readonly` класса, один public `handle()`, cache API сохранён |
| high | Personal homepage release index | `completed` | Additive reversible migration, schema/SQLite EXPLAIN test |
| high | Collection callback safety | `completed` | 19 callbacks исправлены, AST regression охватывает `app` и `tests` |
| high | Request-scoped schema memoization | `completed` | `scopedIf` и lifecycle test после `forgetScopedInstances()` |
| high | Composite nested identifier validation | `completed` | Strict row keys, backed enum и normalized pair rejection |
| medium | Project skills optimization | `completed` | Правила исправлены; все 23 project skills прошли `quick_validate.py` |
| high | Security, performance и compatibility review | `completed` | Player/query, prepared view data, Top‑100 и framework compatibility contracts проверены полным suite |
| high | Documentation и verification | `completed` | Backend 2286/208315, frontend build/audit, 23 skills, homepage 6/6 и player lifecycle 16 passed / 6 skipped |
| critical | Exact commit и push в `main` | `pending` | approved staged snapshot, commit hash, clean pre-push |

## Подтверждённые причины

- Гостевой `CommentDiscussionQuery` не выбирает
  `viewer_private_replies_count`, но `CommentPresenter` читает атрибут всегда;
  strict Eloquent выбрасывает `MissingAttributeException`.
- Web projection загружает genre и country двумя запросами, затем ограничивает
  жанры в builder/Blade, страны в Blade, а годы — двенадцатью строками snapshot.
- Authenticated homepage выполняет запрос featured collections, хотя
  соответствующая секция для него не рендерится.
- Коррелированный personal-update subquery фильтрует
  `release_schedule_entries` по `(catalog_title_id, status, released_at, id)`,
  но существующий индекс использует `starts_at`.
- `CatalogTasteOnboardingSchema` memoizes результат внутри объекта, однако
  несколько consumers получают разные transient instances.
- В repository есть arrow callbacks у `Collection::each()`; возвращённый
  `false` является управляющим сигналом досрочного завершения.
- Nested external identifiers имеют составную identity
  `(provider, normalized_identifier)`; одиночный `distinct` некорректен,
  а текущий сервис молча удаляет повторы.
- Laravel 13.22.0 вызывает `Arr::last(null)` из
  `CookieJar::hasQueued()` при logout других browser sessions после password
  rehash и до события `OtherDeviceLogout`.
- Full-page player owner не передавал валидированное начальное состояние
  вложенному component; при наивном исправлении direct `#[Url]` hydration
  перезаписывалась пустыми аргументами.
- Builder подготавливал directory suggestions и related tags, но Blade их не
  отображал; Top‑100 ранжировался по выбранному provider, а карточка могла
  предпочесть другой рейтинг.
- Subprocess-тест отсутствующего owner наследовал lease-переменные активного
  PHPUnit process и потому не моделировал изолированную среду.
- `CatalogTitleDetail` участвовал в начальной player selection, но отсутствовал
  в явном `resources/player-release.json`, поэтому mixed PHP/assets rollout
  не включал этот source в release fingerprint.
- Player lifecycle acceptance смешивал ожидаемый optional PWA poster miss и
  Firefox navigation cancellation с media failures; expired state также
  показывал две одинаковые retry-кнопки.
- После client hot-swap Livewire morph мог вернуть root session identity к
  server `title:episode:media`, оставив новый UUID внутри `wire:ignore` video;
  корректные progress/navigation events затем fail-closed отклонялись.
- Firefox acceptance выявил test-environment drift: playback fixture host не
  входил в test-only CSP, font requests отменялись слишком быстрым login
  navigation, а `<details>` option имел actionability race между двумя tasks.
- Firefox дополнительно сообщает навигационный `NS_BINDING_ABORTED` как
  console font code `0x804b0002` и ограничение частоты report-only CSP; общий
  console-error фильтр ошибочно смешивал точные diagnostics с failures.

## Выбранное решение

- Для guest projection всегда выбирать нулевой private-reply count.
- Ввести узкие `app/Services/Catalog/Queries/*Query` с одним `handle()`;
  snapshot/metrics cache оставлять только cache boundary.
- Получать public genre/country одной union-группой, web отдавать все строки,
  API оставить с прежними limits.
- Добавить доказанный составной индекс, не заменяя существующие calendar
  indexes, и проверить его через schema introspection и `EXPLAIN`.
- Использовать request/worker-scoped binding, а не process-wide singleton.
- Сделать side-effect callbacks явными `void` closures и закрепить AST-тестом.
- Валидировать nested rows, enum provider и составные normalized duplicates до
  action layer.
- Обновить только полезные project skills; внешние наборы с устаревшими или
  конфликтующими правилами не устанавливать.
- Применить large-project blueprint только к доказанным границам; не добавлять
  interfaces/repositories/view composers или domain rewrite без реального
  второго implementation и измеримой пользы.
- Для Laravel 13.22.0 ограничить compatibility path exact
  version/message/stack, восстановить только пропущенное событие и сохранить
  fail-closed поведение остальных исключений.
- Передавать initial player state только через validated full-page owner и
  применять nullable mount arguments только при явном значении.
- Рендерить уже подготовленные directory/tag данные и передавать карточке
  выбранный Top‑100 rating provider без новых запросов.
- Явно удалять task lease variables из env изолированного hook subprocess.
- Добавить full-page player owner в release descriptor и повторить
  readiness/browser decode matrix после нового fingerprint.
- Сохранить строгий browser guard, исключив только точные optional
  poster/navigation outcomes, и оставить одну recovery retry action.
- Защитить только client-owned root attributes через `wire:ignore.self`, не
  отключая обновление дочернего Livewire workspace.
- Согласовать только Playwright CSP с fixture host, дождаться fonts и
  объединить visibility/click в один проверяемый browser task.
- Классифицировать только exact Firefox diagnostics и оставлять все остальные
  font/asset/CSP errors блокирующими.

## Совместимые contracts

- `GET /`, localized home, `GET /api/v1/home`, discussion routes и Livewire
  component public methods;
- API limits: 18 genres, 12 years и прежняя JSON shape;
- taxonomy/year URLs, catalog visibility, localized Russian UI;
- personal library ownership, release visibility и existing calendar indexes;
- content request provider combinations и database unique constraint;
- PHP compatibility `^8.3`; PHP 8.5-only syntax не вводится.

## Риски и rollback

| Domain | Risk | Mitigation / rollback |
|---|---|---|
| Database | Длительное создание индекса на production SQLite | Проверенный backup, остановка writers, additive migration; rollback только нового index |
| Cache | Старый homepage snapshot скрывает полный годовой список | Versioned cache key; rollback к предыдущей версии key |
| API | Случайное расширение старой JSON projection | Отдельные assertions web/all и API/bounded |
| UI | Очень длинные country/year lists | Все элементы остаются в DOM; bounded keyboard-focusable scroll regions |
| Auth/privacy | Facets или updates раскрывают чужое состояние | Public facets не принимают viewer; existing owner-scoped update query сохраняется |
| Long-lived workers | Process-wide singleton сохраняет stale schema state | `scopedIf`, lifecycle test после `forgetScopedInstances()` |
| Validation | `distinct` запрещает одинаковые ID разных providers | Составной provider-aware normalized key |
| Skills | Supply-chain/устаревшие Laravel правила | Read-only source review, no blind install, local validator |
| Framework | Временный exact shim переживёт upstream fix | Version gate автоматически отключает его; удалить после controlled patch и green regression |

## Детальные документы

- [Design](../superpowers/specs/2026-07-27-homepage-query-classes-performance-design.md)
- [Implementation plan](../superpowers/plans/2026-07-27-homepage-query-classes-performance.md)
- [Compliance matrix](task-111-homepage-query-classes-performance-compliance.md)
