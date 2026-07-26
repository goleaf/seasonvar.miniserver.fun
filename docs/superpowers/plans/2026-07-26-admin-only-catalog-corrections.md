# Task 105 — admin-only catalog corrections implementation plan

Статусы: `pending`, `in_progress`, `completed`, `skipped`, `unresolved`.
Каждый пункт содержит действие / причину / scope / зависимости / риск /
проверку.

| # | Priority | Status | Действие и причина | Файлы / зависимости | Риск | Проверка |
|---:|---|---|---|---|---|---|
| 1 | critical | completed | Перечитать `AGENTS.md`, requirements index и применимые канонические требования. | `AGENTS.md`, requirements/docs | Пропуск обязательного правила. | Read-only audit и matrix. |
| 2 | critical | completed | Проверить версии PHP/Laravel/Livewire/SQLite/npm и frontend stack. | Composer/npm/Boost | Версионная несовместимость. | Application info/manifests. |
| 3 | critical | completed | Проверить `main`, remote, status и foreign changes. | Git | Потеря чужой работы. | Status/log/remote. |
| 4 | critical | completed | Дождаться handoff, получить lease и объявить NUL path manifest. | Lease script | Конфликт shared index. | Owner/declared manifest. |
| 5 | critical | completed | Проследить UI → builder → form → action → policy → query → notification/help/cache. | Request/catalog modules | Неполное закрытие. | Repository audit. |
| 6 | critical | completed | Первым изменить канонический контракт на admin-only. | `docs/catalog-quality.md` | Code/requirement drift. | Повторное чтение. |
| 7 | high | completed | Сохранить design с boundaries/data/cache/deploy/rollback decisions. | Design doc | Скрытые решения. | Design review. |
| 8 | high | completed | Создать compliance matrix и live registry. | Plan docs | Непроверенные claims. | Evidence statuses. |
| 9 | high | completed | Зафиксировать expected paths и совместимые public contracts. | Plan docs | Scope creep. | Final manifest review. |
| 10 | critical | in_progress | RED: public title/player не содержат control/text. | Feature test | Остаточный UI. | HTTP assertions. |
| 11 | critical | pending | RED: user form не показывает administrative types. | Form test | Type выбирается. | Livewire/HTML assertion. |
| 12 | critical | pending | RED: direct correction query даёт 403 non-admin. | Form/policy | URL bypass. | `assertForbidden`. |
| 13 | critical | pending | RED: forged application action отклонён. | Create action | Client-state trust. | Authorization exception. |
| 14 | critical | pending | RED: admin создаёт private correction без vote/follow. | Policy/action/models | Privacy artifacts. | DB assertions. |
| 15 | critical | pending | RED: historical public-flag correction скрыта route/directory/search/sitemap/mine. | Model/query/routes | Legacy leak. | Surface assertions. |
| 16 | high | pending | RED: engagement/update/withdraw/clarify запрещены non-admin. | Policy/presenter | Private mutation. | Policy assertions. |
| 17 | high | pending | RED: admin queue/detail остаются доступны. | Admin boundaries | Функция удалена целиком. | Admin assertions. |
| 18 | high | pending | RED: notification links/recipients fail-closed для non-admin. | Notification services | Private leak. | Recipient/link tests. |
| 19 | high | pending | RED: help escalation не строит correction URL. | Help config/service | Legacy link. | Help assertion. |
| 20 | medium | pending | RED: demo correction rows private и без public notification. | Demo stages | Demo drift. | Demo tests. |
| 21 | medium | pending | RED: cache contract изменён для title/requests. | Cache policy/test | Stale HTML. | Dimension assertion. |
| 22 | high | pending | Browser RED: controls/text отсутствуют, direct form forbidden. | Playwright | Rendered UX regression. | Chromium suite. |
| 23 | critical | pending | Добавить enum helpers administrative/public types. | `ContentRequestType` | Дублированные allowlists. | Tests/static analysis. |
| 24 | critical | pending | Передать type context policy create и требовать moderation gate. | Policy/action/form | Signature regression. | Public/admin create tests. |
| 25 | critical | pending | Сделать scope/route binding type-aware fail-closed. | Model | Admin теряет доступ. | Public/admin route tests. |
| 26 | critical | pending | Сохранять admin-only private, без vote/follow/sitemap bump. | Create action | Public side effects. | Transaction assertions. |
| 27 | critical | pending | Фильтровать form options, direct query и forged state до resolver. | Form page | IDOR. | 403/privacy tests. |
| 28 | high | pending | Фильтровать directory options/normalizer public types. | Directory/query | URL discovery. | State/query tests. |
| 29 | high | pending | Исключить admin types из normal “mine”, сохранить admin queue. | Query | Requester leak. | User/admin assertions. |
| 30 | high | pending | Запретить public engagement и индексирование. | Presenter/SEO | Action/noindex leak. | Presentation/SEO tests. |
| 31 | high | pending | Ограничить notification recipients/URL правом manage/view. | Notification query/service | Title/status leak. | Notification tests. |
| 32 | medium | pending | Сделать demo corrections private и исключить notification sync. | Demo stages | False public model. | Demo tests. |
| 33 | critical | pending | Удалить admin types из help allowlist; fallback fail-closed. | Config/help service | Stored bypass. | Help tests. |
| 34 | high | pending | Reversible guarded migration двух help revisions. | New migration | Перезапись редакции. | Fresh/rollback. |
| 35 | critical | pending | Удалить correction controls из title/player Blade. | Blade views | Остаток UI. | HTTP/browser/rg. |
| 36 | high | pending | Удалить построение URLs из builder/player component. | PHP builders | Лишняя работа/state. | Render/query review. |
| 37 | high | pending | Удалить unused link-builder и Blade component. | Deleted files | Скрытая ссылка. | `rg`, autoload/build. |
| 38 | medium | pending | Удалить dead imports/translations только после full scan. | PHP/lang | Нужный admin text. | Reference scan. |
| 39 | high | pending | Увеличить response contract только затронутых surfaces. | Cache policy | Избыточная invalidation. | Unit test. |
| 40 | medium | pending | Проверить SQL plan нового exclusion, не добавлять индекс без evidence. | SQLite/query | Speculative index. | `EXPLAIN`. |
| 41 | high | pending | Запустить RED focused tests до production code edits. | PHPUnit | Слабый тест. | Expected failure. |
| 42 | critical | pending | Реализовать coherent backend/frontend patch до GREEN. | Code paths | Частичная boundary. | Focused suite. |
| 43 | high | pending | Запустить Pint dirty, убрать debug/dead imports. | PHP | Style regression. | Exit 0 + scan. |
| 44 | high | pending | Запустить related request/help/demo/cache suite. | PHPUnit | Побочная regression. | Green/exact attribution. |
| 45 | high | pending | Migration fresh и rollback/forward на disposable SQLite. | Migration | Необратимость. | Exit 0/assertions. |
| 46 | high | pending | `npm run build` и Playwright correction spec. | Vite/browser | Blade/browser issue. | Exit 0/no console errors. |
| 47 | high | pending | Запустить full PHP suite с достаточной памятью. | PHPUnit | Скрытая regression. | Свежий результат. |
| 48 | medium | pending | Проверить routes/docs/config/translations/stale references. | Artisan/rg | Dead contract. | Exact outputs. |
| 49 | high | pending | Обновить docs, README visitor history и русский CHANGELOG. | Documentation | Behavior undocumented. | Doc hooks/checks. |
| 50 | critical | pending | Перечитать requirements и закрыть matrix честно. | Requirements/matrix | False completed. | Evidence/unresolved. |
| 51 | critical | pending | Проверить diff/status/untracked/secrets/debug/format/foreign paths. | Git/rg | Утечка/foreign commit. | Scoped diff review. |
| 52 | critical | pending | Изолированный exact index, updater, manifest, approve/verify snapshot. | Lease/hooks | Dirty-tree contamination. | Path/hash equality. |
| 53 | critical | pending | Commit логическим `feat` commit в `main`. | Git | Mixed/incomplete commit. | HEAD/cached diff. |
| 54 | high | pending | Read-only senior review; исправить Critical/Important. | Reviewer skill | Boundary regression. | Report + reverify. |
| 55 | critical | pending | Обычный push `main`; при отказе сохранить точную ошибку. | Remote | Auth/protection. | Push output. |
| 56 | critical | pending | Освободить lease после evidence и push attempt. | Lease | Зависший owner. | Empty lease status. |

