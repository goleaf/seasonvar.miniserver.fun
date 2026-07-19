# Обновление Laravel и runtime

Обновлено: 18.07.2026. Канонические правила находятся в [`requirements/maintenance-and-upgrades.md`](requirements/maintenance-and-upgrades.md), точные direct packages — в [`maintenance/dependency-inventory.md`](maintenance/dependency-inventory.md), runtime evidence — в [`maintenance/runtime-compatibility.md`](maintenance/runtime-compatibility.md), а каждое решение — в [`maintenance/update-decisions.md`](maintenance/update-decisions.md).

## Текущее состояние

- Laravel Framework `13.20.0`, Livewire `4.3.3`, PHP constraint `^8.3`; локальные CLI/FPM `8.5.8`.
- Tailwind CSS и `@tailwindcss/vite` `4.3.2`, Vite `8.1.4`, Laravel Vite plugin `3.1.3`.
- `wddyousuf/eloquent-autocache` `0.2.4` остаётся узкой opt-in production dependency Country/Genre queries.
- Node `26.4.0` и npm `12.0.1` наблюдались локально. Node 26 находится в Current, не LTS; переход на проверенную LTS-линию отделён в `UD-R-001` и `TD-001`.
- Composer `2.10.2`; self-update public keys на текущем host отсутствуют и зарегистрированы как `TD-002`.
- Flux/Volt, payment/OAuth/search provider SDK и service worker не установлены.
- Livewire остаётся на `4.3.3`; package default SFC generator не является основанием менять архитектуру и переопределён project config как `type=class` по `UD-LW-CFG-001`.

## Решение Task 29

Version constraints и lock-файлы не обновлялись. PHPUnit 13, `concurrently` 10, Tailwind/plugin patches, Vite patch, FontAwesome patch и Node LTS migration разделены и отложены: ни verified advisory, ни текущий defect не обосновали их объединение с maintenance architecture work.

Единственное Composer configuration исправление — удаление разрешений для двух отсутствующих и незаблокированных plugins. Оно описано как `UD-CFG-001`, не меняет installed packages и повторно проходит validate/platform/audit gates.

## Обязательный staged process

1. Обновить requirements и task-specific decision record.
2. Зафиксировать current/proposed exact versions, purpose, official guidance и affected modules.
3. Проверить direct usage, relevant transitive changes, configuration/providers/routes/commands/assets/data.
4. Описать migration, backward compatibility, production impact и rollback до lock change.
5. Изменить smallest coherent group; не удалять lock-файлы и не принимать unrelated rewrite.
6. Выполнить доступные static, audit, build, browser/manual gates по task policy.
7. Повторно найти old API usage, обновить inventory/matrix/decision/deprecation/debt/deployment/changelog.
8. Commit и push выполняются только из `main`.

Подробные последовательности: [`framework-upgrade-checklist.md`](maintenance/framework-upgrade-checklist.md), [`frontend-upgrade-checklist.md`](maintenance/frontend-upgrade-checklist.md), [`production-compatibility-checklist.md`](maintenance/production-compatibility-checklist.md).

## Rollback

Для version update сохраняются old manifest/lock/config/assets, deployment order и immutable assets. Отдельно проверяются schema, persisted identities, cache/session serialization, pending jobs, provider callbacks и service-worker clients. `git revert` недостаточен, когда хотя бы одна из этих границ изменилась; тогда decision обязан содержать data rollback либо forward-fix.

## Запрещено

- Обновлять package только из-за доступной версии.
- Выполнять широкий Composer/npm update или `npm audit fix --force`.
- Смешивать unrelated framework/runtime/frontend/database/cache/provider majors.
- Менять lock без понимания direct и relevant transitive diff.
- Объявлять install/build доказательством полной функциональной совместимости.
- Вводить Volt, competing architecture, fake admin updater или browser shell access.
