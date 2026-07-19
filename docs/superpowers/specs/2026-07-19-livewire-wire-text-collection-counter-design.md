# Дизайн мгновенного счётчика выбранных коллекций через `wire:text`

Дата: 19.07.2026
Статус: реализовано и проверено; task-only Git delivery заблокирован существующим общим staged snapshot

## Цель

Счётчик выбранных коллекций в диалоге добавления сериала должен обновляться сразу после изменения checkbox, не отправляя отдельный Livewire-запрос и не меняя серверную операцию сохранения.

## Решение

`CatalogCollectionMembershipManager` продолжает хранить выбранные публичные UUID в `selectedCollectionPublicIds` и сохраняет их только через существующий `apply()`. Checkbox остаётся на deferred `wire:model`, а текстовый узел получает `wire:text`, который читает локальную длину массива `selectedCollectionPublicIds.length`.

Существующий серверный `$selectedCountLabel` остаётся содержимым элемента. Он обеспечивает локализованный SSR/no-JavaScript fallback и исходное доступное сообщение. Клиентское выражение использует тот же перевод `collections.membership.selected` с безопасным техническим placeholder, поэтому порядок числа сохраняется и для `ru`, и для `en`.

## Рассмотренные варианты

1. `wire:text` поверх deferred `wire:model` — выбранный вариант: мгновенная presentation-only обратная связь без HTTP-запроса и отдельного JavaScript-модуля.
2. `wire:model.live` — отклонён: создаёт ненужный запрос при каждом checkbox и расширяет server workload.
3. Alpine `x-text` — отклонён: дублирует доступный Livewire 4 API и создаёт вторую presentation boundary.

## Границы и риски

- Авторизация, validation, UUID normalization и запись membership остаются на сервере.
- Оптимистически меняется только локальный счётчик; список не считается сохранённым до `apply()`.
- Переводы не дублируются и не переносятся в JavaScript bundle.
- Новый dependency, migration, route, cache key, queue, API или production configuration не добавляется.
- `#[Async]` не включается: текущие actions изменяют значимое состояние и не соответствуют fire-and-forget contract.

## Проверка

- Feature test рендерит реальный Livewire component после `openSelector()` и проверяет SSR fallback, deferred `wire:model`, `wire:text` и локальное выражение длины массива.
- Повторный аудит официального reference закрепляет отсутствие modifiers и ровно один application `wire:text`; другие тексты не получают optimistic binding без локального client-owned draft.
- `npm run build` подтверждает frontend/Tailwind/Vite contract после Blade-изменения.
- Repository search подтверждает отсутствие competing `x-text`, `wire:model.live` для этого массива и неподходящего `#[Async]`.
