# Текущая задача — Task 101: рейтинг качества подборок

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 101 — quality score, очистка и объяснимость подборок | `in_progress: commit and push` | [Design](../superpowers/specs/2026-07-26-collection-quality-rating-design.md), [checklist](../superpowers/plans/2026-07-26-collection-quality-rating.md), [evidence](archive/2026-07-26-collection-quality-evidence.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |
| Общий checkout содержит изменения других задач | `unresolved: clean pre-push gate depends on foreign workstreams` | [Task 101 evidence](archive/2026-07-26-collection-quality-evidence.md) |
| Full suite содержит unrelated failures | `unresolved: foreign/snapshot boundaries` | [Task 101 evidence](archive/2026-07-26-collection-quality-evidence.md) |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Permanent requirements и Laravel 13 boundaries | `completed` | [Compliance matrix](task-101-collection-quality-compliance.md) |
| Additive schema и reversible migration | `completed` | [Design](../superpowers/specs/2026-07-26-collection-quality-rating-design.md) |
| Explainable score, duplicates, theme и engagement | `completed` | [Implementation checklist](../superpowers/plans/2026-07-26-collection-quality-rating.md) |
| Public/admin/verification security gates | `completed` | [Compliance matrix](task-101-collection-quality-compliance.md) |
| Full verification | `completed` | [Task 101 evidence](archive/2026-07-26-collection-quality-evidence.md) |
| Commit и push | `in_progress: delivery gate` | [Task 101 evidence](archive/2026-07-26-collection-quality-evidence.md) |
| Likes, follows, collaborators и public smart rules | `not_applicable` | Они явно остаются нереализованными product boundaries |

## Последнее подтверждённое evidence

- [Архив Task 101](archive/2026-07-26-collection-quality-evidence.md)

## Текущая задача — Task 104: рабочее пространство player

## Активный workstream

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 104 — theatre mode, compact context, descriptive episode navigation и recovery UX | `in_progress: commit and push` | [Design](../superpowers/specs/2026-07-26-player-workspace-redesign-design.md), [implementation checklist](../superpowers/plans/2026-07-26-player-workspace-redesign.md), [compliance](task-104-player-workspace-compliance.md) |

## Приоритеты и живой checklist

| Priority | Status | Что / зачем | Файлы и зависимости | Риск | Проверка |
| --- | --- | --- | --- | --- | --- |
| critical | completed | Повторно прочитать permanent requirements, player docs, фактические версии и реализацию, чтобы не создать второй lifecycle | `AGENTS.md`, `docs/requirements/*`, player docs/code; Laravel 13.22, Livewire 4.3 | скрытый конфликт с playback/PWA/security | audit evidence, Boost application info/docs |
| critical | completed | Получить exclusive lease и сохранить чужие staged/unstaged изменения | repository guard, точный declared scope | пересечение shared docs/index | lease status и exact-path staging |
| critical | completed | Сначала закрепить постоянный theatre/context/recovery contract | `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/architecture.md`, `docs/views.md`, playback report | расходящиеся требования | повторное чтение canonical sections |
| critical | completed | Добавить RED tests для server projection, Blade markers и JS lifecycle | focused PHP/unit/browser tests | тесты могут зафиксировать markup вместо поведения | RED зафиксировал отсутствующие workspace contracts; GREEN: 31 тест, 306 утверждений |
| critical | completed | Подготовить truthful media context без raw URL и Blade query | playback query, view model, transition factory/DTO | stale context после in-place swap | payload содержит только display metadata и public query; browser hot-swap обновляет context controls |
| high | completed | Перестроить player Blade в один спокойный workspace | player/detail Blade, existing components | потеря старых actions или `wire:ignore` ownership | сохранён один keyed `wire:ignore`, обычные ссылки и существующие report/download actions |
| high | completed | Реализовать theatre lifecycle с focus return и modal/fullscreen Escape priority | `player-navigation.js`, CSS | разрушение media DOM или застрявший body class | Playwright проверил scope, Escape, возврат фокуса и геометрию |
| high | completed | Реализовать loading/fallback/error states и source recovery | `player.js`, `player-menu.js`, translations | бесконечный retry, fake source option | browser recovery открывает существующий выбор перевода; вымышленные источники не создаются |
| high | completed | Сделать navigation и mobile controls понятными и ≥44 px | Blade, CSS, existing menu | overlap на 320 px/landscape | Playwright desktop/mobile/tablet: 8 passed, 1 skipped |
| high | completed | Сохранить signed playback, authorization, progress, report и PWA boundaries | existing services/routes/jobs/worker | утечка provider URL/grant или второй report flow | route/service boundaries не менялись; transition payload не содержит URL источника |
| medium | completed | Проверить query columns/N+1/indexes; не добавлять speculative DDL | playback query/schema | лишняя media hydration или потеря позднего выбранного source | точечная projection и eager-loaded relations; UI ограничен 24 уникальными options после active-first, DB-выборка не отбрасывает доступные media; DDL не требуется |
| medium | completed | Запустить focused → full PHP, Pint, Vite и browser QA | existing test/build stack | широкий regression или flaky browser | Task 104 + translation parity: 33/33 PHP и 8 passed/1 skipped Playwright; полный PHPUnit выполнен с отдельным 1G config и выявил только параллельные repository regressions |
| medium | completed | Обновить visitor/product docs и evidence | README, CHANGELOG, archive evidence | неактуальная документация | canonical docs, README, CHANGELOG, compliance и archive evidence обновлены |
| critical | in_progress | Проверить точный diff, staged scope, secrets/debug и main | Git/lease guard | чужие файлы в commit | alternate-index staged diff и guard verify |
| critical | pending | Создать осмысленный commit и выполнить ordinary push | `main`, configured remote | dirty shared checkout блокирует pre-push | hash, command output; честный unresolved при отказе |

## Неизменяемые public contracts

- route `/titles/{catalogTitle:slug}` и route model binding;
- public query keys `season`, `episode`, `media`, `variant`, `quality`, `format`;
- один `CatalogTitlePlayer`, один keyed `wire:ignore`, один video/Plyr/HLS instance;
- signed same-origin playback grant, source authorization, entitlement и fallback;
- progress/session/telemetry/report contracts и обычные navigation `href`;
- responsive player menu и PWA no-video-cache boundary;
- русские интерфейсные тексты и RU/EN dictionary parity.

## Database, routes, translations, cache и rollback

- Migration и новый index не нужны: используется существующий nullable
  `licensed_media.subtitle_language`; projection расширяется только этим полем.
- Новые route, permission, cache key, external dependency и production service
  не вводятся.
- Rollback: revert Task 104 commit. Schema/data остаются неизменными; старые
  playback grants/progress/report routes продолжают работать.

## Последнее discovery

Laravel Boost подтвердил PHP 8.5, Laravel 13.22, Livewire 4.3 и SQLite.
Livewire 4 предоставляет component cleanup/morph hooks; существующая
реализация уже использует правильное разделение `player.js`,
`player-menu.js`, `player-navigation.js`, поэтому новый controller/store не
понадобился. Subtitle track URL/body в schema отсутствует: UI показывает
только реальную доступность source variant. Последняя проверка hot-swap
выявила риск устаревших ссылок context-bar после бесшовной смены серии:
transition payload расширен только безопасными display/query-метаданными, а
контекстные варианты теперь перестраиваются вместе с episode navigation без
раскрытия `source_url` или playback grant.
Code review дополнительно выявил четыре edge case: доступный source за первой
сотней строк, потерю языка субтитров, устаревший encrypted report context после
hot-swap и сброс theatre state после Livewire morph. Все четыре закрыты
регрессионными тестами. Полный PHPUnit подтвердил отсутствие Task 104 failures,
но оставил внешние regressions параллельных workstreams, включая season query
state вложенного Livewire player, шаблон offline layout, shared-plan policy и
отсутствующий collection-quality class; они не входят в этот lease.
