# Текущая задача — Task 113–118

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 113: безопасные практики современного PHP | `completed: commit a7322a91; push unresolved authentication` | [Task 113 evidence](archive/2026-07-27-modern-php-practices-evidence.md) |
| Task 114: video-first режим «Театр» | `completed: commit cc77728a; push unresolved authentication` | [Task 114 evidence](archive/2026-07-27-player-theatre-video-first-evidence.md) |
| Task 115: заметные карточки персональных рекомендаций | `completed: commit cc77728a; push unresolved authentication` | [Task 115 evidence](archive/2026-07-27-personalized-series-cards-evidence.md) |
| Task 116: восстановление default-входа discovery | `completed: commit cc77728a; push unresolved authentication` | [Task 116 evidence](archive/2026-07-27-discovery-default-route-restoration-evidence.md) |
| Task 117: восстановление публичных редакционных подборок | `completed: commit b2e97d80; push unresolved authentication` | [Evidence](archive/2026-07-27-public-editorial-collection-restoration-evidence.md) |
| Task 118: адаптивная панель действий фильтров каталога | `in_progress: design approved; implementation pending` | [Design](../superpowers/specs/2026-07-27-catalog-filter-actions-design.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 115 inherited browser gate | `unresolved` | Старый PWA poster fixture даёт `404`; focused Task 115 gates прошли |
| Task 117 inherited PWA browser signal | `unresolved` | Existing `/service-worker.js` возвращает `404`; collection page/local requests проходят |
| Remote delivery Tasks 113–117 | `unresolved` | Посторонние изменения были адресно сохранены и точно восстановлены; push дошёл до GitHub HTTPS username prompt, credential helper отсутствует, данные не отправлены |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Native warning и architecture boundaries | `completed` | [Task 113 evidence](archive/2026-07-27-modern-php-practices-evidence.md) |
| Routes, schema, cache keys и permissions | `already_compliant` | Публичные и data contracts не менялись |
| Full verification | `completed` | Backend: `2294` tests, `208356` assertions; frontend: build и release-check прошли |
| Commit и remote delivery | `unresolved: commits complete, push authentication unavailable` | Task 113 = `a7322a91`, Tasks 114–116 = `cc77728a`; dependency lock не входит в разрешённый scope |
| Task 114 theatre presentation | `completed` | [Task 114 evidence](archive/2026-07-27-player-theatre-video-first-evidence.md) |
| Task 114 public/data contracts | `already_compliant` | Routes, schema, translations, cache keys и permissions не меняются |
| Task 115 approved green direction | `completed` | [Task 115 design](../superpowers/specs/2026-07-27-personalized-series-cards-design.md) |
| Task 115 implementation и verification | `completed` | [Evidence](archive/2026-07-27-personalized-series-cards-evidence.md): RED/GREEN, `44` PHPUnit tests, build, focused Playwright `3/3` |
| Task 116 route contract | `completed` | Default/trailing-slash/RU/EN ведут к существующему `popular#collections` |
| Task 117 root cause и canonical recovery contract | `completed` | 501 строка сохранена; exact allowlist ограничен 10 проверенными source collections |
| Task 117 implementation и production apply | `completed` | 10 restored/listed; verified backup, quarantine и live integrity checks |
| Task 118 root cause и утверждённая компоновка | `completed` | `18rem` sidebar конфликтует с `sm:flex-row`; пользователь выбрал три полноширинных действия |
| Task 118 implementation и verification | `unresolved` | Ожидает RED/GREEN, build и desktop/mobile Playwright |

## Последнее подтверждённое evidence

- [Task 113: современные PHP-практики](archive/2026-07-27-modern-php-practices-evidence.md)
- [Task 114: compliance video-first theatre](task-114-player-theatre-video-first-compliance.md)
- [Task 114: video-first theatre evidence](archive/2026-07-27-player-theatre-video-first-evidence.md)
- [Task 115: compliance заметных карточек](task-115-personalized-series-cards-compliance.md)
- [Task 116: compliance default discovery](task-116-discovery-default-route-restoration-compliance.md)
- [Task 117: compliance восстановления редакционных подборок](task-117-public-editorial-restoration-compliance.md)
- [Task 118: compliance панели действий фильтров](task-118-catalog-filter-actions-compliance.md)

## Task 118 — цель и checklist

Убрать переполнение трёх действий формы `/titles`, сохранив один
Livewire/GET filter contract: основной CTA и два служебных действия должны
располагаться вертикально, занимать ширину панели и оставаться удобными при
длинном result count.

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions и root-cause audit | `completed` | Laravel `13.22.0`, Tailwind `4.3.2`; `18rem` sidebar + `sm:flex-row` |
| high | Visual direction и design-spec | `completed: option 1 approved` | [Design](../superpowers/specs/2026-07-27-catalog-filter-actions-design.md) |
| high | TDD implementation plan | `completed` | [Detailed plan](../superpowers/plans/2026-07-27-catalog-filter-actions.md) |
| high | Blade implementation и focused verification | `pending` | PHPUnit, Vite и Playwright desktop/phone |
| medium | Canonical docs, README, CHANGELOG и archive evidence | `pending` | Обновляются после GREEN |
| critical | Exact commit и push in `main` | `pending` | Посторонние dependency/debugbar изменения исключаются |

Task 118 не меняет migrations, routes, query semantics, API, translations,
cache keys, permissions, importer, recommendation или persistent data.
Rollback — revert UI/tests/docs и rebuild без data restore/cache flush.

## Task 117 — цель и checklist

Вернуть в `/discover/***#collections` десять сохранённых и вручную
проверенных source-managed подборок, назначив им существующие подкатегории и
не возвращая demo, пустые или ошибочно сопоставленные записи.

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions и root-cause census | `completed` | 501 public/approved, 0 listed; 447 demo и 54 source |
| critical | Canonical contract и exact reviewed manifest | `completed` | [Design](../superpowers/specs/2026-07-27-public-editorial-collection-restoration-design.md) |
| high | TDD recovery service/command | `completed` | 176 тестов / 1 258 утверждений; Pint, focused PHPStan, Rector |
| critical | Production backup/writer-safe apply | `completed` | Backup 31 423 373 312 bytes; restore 10; quarantine 897+44; quick/FK green |
| high | Directory/category/browser verification | `completed` | HTTP 200; 10 cards; 5 roots/31 children; desktop/mobile no overflow |
| critical | Commit и push в `main` | `unresolved: commit b2e97d80; push authentication` | Exact scope committed; GitHub запросил отсутствующий HTTPS username |

Task 117 не меняет migrations, routes, API shape, sitemap format,
translations, packages, permissions или cache keys. Data scope ограничен
exact десятью source keys; rollback — verified backup либо авторизованный
roll-forward в private/archived.

## Task 116 — цель и checklist

Вернуть `/discover/` и localized aliases как совместимые `302` entry routes
к существующему `/discover/popular#collections`, где уже отображается полное
дерево из пяти категорий и 31 подкатегории.

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, latest specs и root-cause audit | `completed` | Удалённый route найден; Task 109/110 hierarchy уже работает |
| high | Canonical contract и task plan | `completed` | [Design](../superpowers/specs/2026-07-27-discovery-default-route-restoration-design.md), [plan](../superpowers/plans/2026-07-27-discovery-default-route-restoration.md) |
| high | PHPUnit RED/GREEN и route implementation | `completed` | RED `404`; GREEN `58` tests / `494` assertions |
| high | Browser hierarchy/mobile smoke | `completed` | Playwright `2/2`: `/discover/` → `popular#collections`, 5/31 tree |
| medium | README, CHANGELOG и archive evidence | `completed` | [Evidence](archive/2026-07-27-discovery-default-route-restoration-evidence.md) |
| critical | Exact commit и push in `main` | `unresolved: commit cc77728a; push authentication` | Посторонний `composer.lock` исключён и после попытки push восстановлен |

Task 116 не меняет migrations, database data, collection quality/visibility,
API, sitemap, cache keys, permissions, recommendation ranking или imports.
Rollback — удалить два entry routes и вернуть прежний `404` contract.

## Task 115 — цель и checklist

Сделать блок «Сериалы каталога» на `/discover/personalized` визуально
отделённым светло-зелёным фоном, поместить результаты в белые карточки и
дать каждой карточке постоянно заметную кнопку перехода к сериалу.

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, implementation и production browser audit | `completed` | Desktop/mobile подтвердили прозрачный shell и слабую текстовую CTA |
| high | Visual direction и design-spec | `completed` | [Design](../superpowers/specs/2026-07-27-personalized-series-cards-design.md) одобрен пользователем |
| high | TDD implementation plan | `completed: approved option executed` | [Plan](../superpowers/plans/2026-07-27-personalized-series-cards.md) |
| high | TDD implementation и focused verification | `completed` | `44` PHPUnit tests/`81 536` assertions; focused Playwright `3/3` |
| medium | Canonical docs, README, CHANGELOG и evidence | `completed` | [Evidence](archive/2026-07-27-personalized-series-cards-evidence.md) |
| critical | Exact commit и push in `main` | `unresolved: commit cc77728a; push authentication` | User-authorized Tasks 114–116 зафиксированы; dependency lock excluded |

Task 115 не меняет migrations, routes, API, cache keys, permissions,
recommendation ranking или persistent data. Rollout требует согласованной
публикации Blade/assets; rollback — revert UI release unit без data restore.

## Task 114 — цель и checklist

Только в активном режиме «Театр» сделать существующее видео первым
визуальным элементом, скрыть breadcrumbs/«Просмотр»/текстовую сводку и
оставить реальные source controls под video, а кнопку выхода — поверх его
правого верхнего угла.

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions, current implementation audit | `completed` | UI/frontend/views/player/production/maintenance owners и Task 112 lifecycle проверены |
| critical | Workspace lease и exact manifest | `completed` | `task-114-player-theatre-video-first`, 19 путей после browser discovery |
| high | RED marker/CSS/browser contracts | `completed` | Три RED-этапа подтверждены до соответствующих production edits |
| high | Scoped Blade/CSS video-first implementation | `completed` | Video-first, compact toggle и точный scroll restore GREEN |
| high | Vite/player release и browser matrix | `completed` | `31` sources/`19` assets; Playwright `19 passed`/`2 skipped` |
| medium | Canonical docs, README, CHANGELOG, archive evidence | `completed` | Owners и evidence обновлены |
| critical | Exact commit и remote delivery | `unresolved: commit cc77728a; push authentication` | User-authorized Tasks 114–116 зафиксированы; dependency lock excluded |

Task 114 не меняет migrations, routes, translations, cache keys, policies,
permissions, playback grants, progress, PWA media denylist или persistent
data. Rollout требует согласованной Vite-сборки; rollback — revert frontend
release unit и rebuild без data restore.

## Цель

Проверить рекомендации статьи «Stop Using These Bad PHP Practices in 2026»
на фактическом коде Seasonvar, устранить подтверждённые подавления нативных
предупреждений без изменения публичных контрактов и закрепить результат
автоматическими архитектурными и runtime-тестами.

## Активный checklist

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions, article и current implementation audit | `completed` | PHP `8.5.8`, Laravel `13.22.0`, SQLite; 8 рекомендаций сопоставлены с repository |
| critical | Workspace lease и exact manifest | `completed` | `task-113-modern-php-practices`, paths declared |
| high | `@`/`exit`/manual include/unsafe SQL AST contract | `completed` | `0` запрещённых AST-узлов после GREEN |
| high | Typed native-warning boundary и 16 call sites | `completed` | `App\Support\NativeCall`; все 16 call sites переведены |
| high | Existing domain behavior и security compatibility | `completed` | Focused suites и полный backend gate прошли |
| medium | Bounded PHPStan expansion и code-quality documentation | `completed` | Larastan level 6: `0` errors; owners обновлены |
| critical | Full verification | `completed` | Backend/frontend gates exit `0` |
| critical | Exact commit и push in `main` | `unresolved: commit a7322a91; push authentication` | GitHub HTTPS запросил username; credential helper отсутствует |

## Подтверждённые выводы

- В `app/` найдено 16 выражений подавления ошибок `@` в девяти runtime-файлах.
- В `app/` не найдено `die`/`exit`, ручных `include`/`require` и
  `DB::unprepared`.
- Проверенные raw SQL boundaries используют bindings для значений и
  allowlisted либо schema-derived identifiers; подтверждённой SQL injection
  нет.
- HTML routes остаются full-page Livewire, а API-контроллеры делегируют
  Form Requests, services/query objects и API Resources; новый repository
  layer не нужен.
- Composer lock, bounded Larastan level 6, Rector, Pint и PHPUnit уже входят
  в канонический CI gate; dependencies не меняются.

## Решение

- добавить `App\Support\NativeCall`, который на время одного callback
  преобразует `E_WARNING`/`E_USER_WARNING` в `ErrorException` и всегда
  восстанавливает прежний handler;
- заменить каждый `@` явным вызовом boundary и локально сопоставить ожидаемую
  ошибку с существующим domain exception, fail-closed `null`/`false` либо
  sanitized operational report;
- запретить AST-тестом возврат `@`, `exit`/`die`, runtime
  `include`/`require` и `DB::unprepared` в `app`;
- включить новый support boundary в существующий bounded PHPStan scope;
- не менять schema, routes, query parameters, cache keys, translations,
  authorization, queue payloads, provider URL policy и UI.

## Compatibility, rollout и rollback

- migrations/data writes/backfill/backup: `not_applicable`;
- routes/API/Blade/Livewire/translations/permissions: `unchanged`;
- cache key, format и invalidation: `unchanged`; corrupt gzip по-прежнему
  считается miss либо существующей безопасной domain error;
- external providers: DNS/OpenSSL/EXIF/GD/proc errors остаются bounded и не
  раскрывают credential, URL, body или private path;
- rollout: обычный application commit без dependency install, migration,
  asset build или worker payload conversion;
- rollback: согласованный revert PHP/tests/docs commit; cache flush и data
  rollback не требуются.

## Детальные документы

- [Design](../superpowers/specs/2026-07-27-modern-php-practices-design.md)
- [Implementation plan](../superpowers/plans/2026-07-27-modern-php-practices.md)
- [Compliance matrix](task-113-modern-php-practices-compliance.md)

## Детальные документы Task 114

- [Design](../superpowers/specs/2026-07-27-player-theatre-video-first-design.md)
- [Implementation plan](../superpowers/plans/2026-07-27-player-theatre-video-first.md)
- [Compliance matrix](task-114-player-theatre-video-first-compliance.md)
