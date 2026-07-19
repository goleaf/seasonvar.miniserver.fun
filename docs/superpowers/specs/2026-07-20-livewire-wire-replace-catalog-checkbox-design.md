# Livewire `wire:replace` — leaf checkbox boundary каталога

Дата: 20.07.2026

## Контекст

Livewire 4 `wire:replace` полностью заменяет потомков элемента вместо их morphing. Вариант `.self` заменяет сам элемент вместе с поддеревом. Это средство предназначено для подтверждённых конфликтов повторного использования DOM или internal state, которые нельзя безопасно оставить обычному morphing.

Repository уже содержит четыре template pattern `wire:replace.self` на leaf-checkbox в contextual filters каталога: годы, тип публикации, субтитры и динамические taxonomy groups. Все они одновременно используют `wire:model.live`; после grouped island response серверное checked state должно получить новый input element, тогда как окружающие label, text, counters и focusable filter UI продолжают обычный morphing. Два route-owned checked checkbox используют отдельный click action и replacement не получают.

Других владельцев replacement нет. Единственный third-party DOM owner — keyed media shell `CatalogTitlePlayer`, защищённый полным `wire:ignore` и явным Plyr/HLS destroy/re-init lifecycle. Native dialogs, forms, editors и text/search inputs должны сохранять focus и draft при обычном morphing.

## Решение

- Сохранить четыре существующих `wire:replace.self` только на leaf live-checkbox contextual filters.
- Запретить bare subtree `wire:replace`, replacement на player, labels/groups/forms/dialogs и text/search inputs.
- Закрепить exact inventory characterization-тестом, включая отсутствие custom-element/shadow-DOM owner.
- Разрешать новый replacement только после воспроизводимого DOM reuse/internal-state defect и проверки более узких `wire:key`, component extraction и lifecycle cleanup.

## Совместимость и rollback

Production HTML не меняется: задача документирует и защищает существующую narrow boundary. Filter URL/history, grouped islands, GET fallback, player lifecycle, focus, drafts, routes, validation, authorization, cache, schema, dependencies, assets и environment сохраняются. Rollback удаляет characterization test и task-specific documentation; existing checkbox contract остаётся без изменения.
