# Финальное закрытие backlog Seasonvar

**Дата:** 13.07.2026

## Цель

Оставить один временный исполняемый план, закрыть все подтверждённые repository/runtime gaps, удалить выполненные пункты по мере проверки и в конце удалить сам план. Постоянными источниками истины остаются тематические документы из `docs/README.md`, `CHANGELOG.md` и `docs/MAINTENANCE_LOG.md`.

## Рассмотренные подходы

1. Реализовать буквально каждый roadmap-пункт. Отклонено: DRM credentials, billing, household profiles, legal retention, новый search engine и product experiments требуют внешних договоров или продуктовых решений; выдуманная реализация создала бы ложные security/product guarantees.
2. Закрыть только P0/P1. Отклонено: это оставило бы вечный технический backlog и нарушило требование завершить весь план.
3. **Выбранный:** выполнить все доказуемые repository/runtime задачи, а неподтверждённые будущие продукты удалить из backlog после фиксации их текущих границ в документах-владельцах. Новый продукт возвращается только новым явным требованием и отдельным дизайном.

## Единственный план

`docs/audit.md` временно становится единственным implementation plan. Другие plan-файлы не создаются. План содержит только конкретные задачи этого прохода, обновляется после каждого проверенного блока и удаляется после полной проверки. Design-specs не являются активными планами и сохраняются как архитектурная история.

## Блок 1. Production environment, logging и cache runtime

- Перевести фактический runtime на `production`, выключить debug, использовать warning-level daily logs с ограниченным retention.
- Не раскрывать и не коммитить значения `.env`; меняются только явно перечисленные безопасные operational keys.
- Проверить config cache, PHP-FPM/public HTTP и непрерывный importer после reload.
- Переключить только уже реализованные именованные Redis/Memcached workloads; БД остаётся source of truth, а outage degradation проверяется существующими integration tests.
- Старый большой log не удалять: сохранить как legacy artifact и включить versioned rotation policy.

## Блок 2. Operations, failed jobs, integrity и deployment

- Расширить существующие read-only health/diagnostic boundaries сводкой failed jobs по безопасным категориям и возрасту без payload/URL/exception text.
- Добавить read-only deployment preflight: environment/debug/logging, migrations, SQLite quick/FK/index checks, FTS counts/state, cache transports и importer process profile.
- Preflight ничего не мигрирует, не очищает и не перезапускает. Он возвращает ненулевой exit code для unsafe production состояния и JSON для automation.
- Документировать атомарный runbook вокруг одного `seasonvar:import --forever`; фактические destructive cleanup/retry операции не выполняются.

## Блок 3. Browser security и воспроизводимый QA

- Добавить конфигурируемый `Content-Security-Policy-Report-Only` header с локальными script/style/font defaults и allowlisted media/image/connect origins. Enforcement не включается без чистого browser report.
- Добавить Playwright browser smoke в CI на deterministic local fixtures: catalog URL state, mobile filter focus, title/player shell, no horizontal overflow и local asset errors.
- Добавить axe critical/serious gate и bounded visual geometry assertions; внешние media requests блокируются.
- Playwright/axe добавляются только как development dependencies.

## Блок 4. Измеримые budgets и audit trail

- Зафиксировать максимальный размер Livewire public snapshot/update payload и query budgets для каталога/title shell на текущих bounded fixtures.
- Переиспользовать существующие cache/health metrics и добавить только low-cardinality operational агрегаты без URL, search text, user labels или tokens.
- Добавить additive append-only admin audit table и сервис для существующих catalog admin mutations. Хранить actor ID, action enum, resource type/ID, version fingerprints и allowlisted changed-field names; значения полей, source URLs и provider payload не хранить.
- Обычный admin flow не получает update/delete endpoint для audit rows.

## Блок 5. Automation и удаление speculative roadmap

- `project:docs-refresh --check` дополнить детерминированной проверкой внутренних Markdown links и migration inventory без изменения неуправляемого текста.
- Подключить low-noise static analysis как development/CI gate без giant baseline; область сначала ограничить `app/DTOs`, `app/Enums`, новым operational и audit-кодом.
- Retention фиксируется по существующим владельцам: importer events/snapshots/prepared rows уже очищаются bounded pipeline; failed jobs получают report/disposition, user history и legal data не удаляются без policy.
- Текущие non-goals — DRM/provider credentials, localized content records, household profiles/billing/PIN, personalized ranking, QoE telemetry, external search, PostgreSQL cutover и feature experiments — удаляются из plan. Их существующие authorization/privacy/threshold boundaries остаются в тематической документации.

## Проверка и завершение

- Для каждого code change: TDD RED → минимальная реализация → GREEN.
- PHP: Pint, focused tests, полный `php artisan test`, `./vendor/bin/phpunit`, syntax/config/route/view cache checks.
- Frontend: `npm audit`, `npm run build`, Playwright/axe matrix.
- Operations: read-only preflight, public HTTP smoke, config/runtime inspection, importer PID/autostart and SQLite integrity.
- Documentation: `project:docs-refresh` idempotency, link check и `git diff --check`.
- После зелёной проверки `docs/audit.md` удаляется, ссылка из `docs/README.md` снимается, результат записывается в единственный `CHANGELOG.md` и журнал обслуживания.

## Self-review

- Placeholder-ов и неопределённых implementation tasks нет.
- Production dependencies и новые публичные product promises не добавляются.
- План не разрешает destructive queue/database cleanup и не скачивает видео.
- Единственная рабочая ветка — существующая `main`; worktree и дополнительная ветка не создаются.
