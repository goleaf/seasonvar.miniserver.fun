# План реализации: компактные похожие сериалы

Статусы: `pending`, `in_progress`, `completed`, `skipped`, `unresolved`.

| Приоритет | Этап | Что и зачем | Файлы / зависимости | Риск | Проверка | Статус |
|---|---|---|---|---|---|---|
| critical | Анализ | Повторно прочитать requirements, текущие recommendations/title contracts, версии и Git state | `AGENTS.md`, `docs/requirements/*`, recommendation services/views/tests | пропустить постоянное правило | список evidence и verified versions | completed |
| critical | Интеграция | Дождаться exclusive lease, восстановить stale owner только при мёртвом PID, объявить exact manifest | `scripts/task-workspace-lease.sh` | смешанный commit | `verify-owner`, declared paths | completed |
| critical | Совместимость | Перечитать Task 105 изменения в builder/title view и не вернуть public correction UI | builder, title detail, Task 105 docs | security regression | focused correction test/search | completed |
| critical | Требования | Сначала обновить canonical UI owner и утвердить design 6+6/compact feedback | `docs/UI_STANDARDS.md`, design | конфликт со старым one-reason contract | повторное чтение | completed |
| critical | RED tests | Зафиксировать 6+6, максимум 12, причины, excerpt, metadata, feedback variant и query reduction | recommendation/card/budget/unit/browser tests | хрупкие string assertions | ожидаемые focused failures | completed |
| critical | Backend | Ограничить title-page выдачу 12, подготовить initial/additional collections и убрать subject-option query | `CatalogTitlePageBuilder` | сломать старый `recommendationItems` | DTO order/count/query-log tests | completed |
| high | Component | Подготовить до трёх bounded reasons, 180-char excerpt и IMDb-first recommendation metadata | `TitleCard` | затронуть другие layouts | component tests всех layouts | completed |
| high | Frontend | Реализовать компактную строку и нативное 6+6 раскрытие без Blade logic/query | two Blade views | DOM/ARIA/mobile overflow | render + browser checks | completed |
| high | Feedback | Добавить allowlisted `compact` variant с прямыми positive/not-similar actions | PHP/Blade component, translations | client-trusted action/XSS | unit + Livewire feedback tests | completed |
| high | Авторизация | Сохранить server-side visibility, enum validation, auth boundary и undo | existing Livewire/service/policy | IDOR | authenticated/guest/hidden-title tests | completed |
| high | Производительность | Подтвердить отсутствие second request и шести feedback option SQL queries | builder/budget test, docs | скрытый N+1 | query log/budget | completed |
| high | Доступность | Проверить 44px, visible focus, keyboard details, semantic list, no hover-only action | Blade/browser | touch/keyboard regression | Playwright/DOM assertions | completed |
| medium | Ошибки | Сохранить translated notice/error/loading и retry-safe disabled controls | translations/feedback view | silent failure | Livewire error/action tests | completed |
| medium | Документация | Обновить canonical UI owner, README, CHANGELOG и evidence | docs | stale public docs | docs gates/search | completed |
| critical | Quality | Запустить Pint, focused/related PHPUnit, build и browser suite; исправить task regressions | project tools | foreign failures | фактические exit codes | completed |
| critical | Review | Проверить status/diff/stat/untracked/secrets/debug/legacy implementations и staged manifest | Git/rg | чужие файлы в commit | cached diff + guard scripts | in_progress |
| critical | Release | Exact stage, approve/verify index, commit в `main`, обычный push, release lease | Git/lease | auth/pre-push failure | branch/hash/push output | unresolved |

## Неизменяемые contracts

- `titles.show`, public API, route model binding и query parameters.
- `recommendationItems`, `relatedRecommendationItems` и
  `CatalogRecommendationListItem`.
- `setRecommendationFeedback*`, undo и stored enum values.
- Admin-only correction boundary Task 105.
- Existing discovery full-feedback UI.

## Не требуются

- Schema migration, index, seed, dependency, queue, cache key, route, API
  resource или JavaScript module: `not_applicable`, если discovery не выявит
  новый доказуемый запрос.
