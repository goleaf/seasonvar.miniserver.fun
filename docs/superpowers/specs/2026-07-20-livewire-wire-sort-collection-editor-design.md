# Livewire `wire:sort` — ручной порядок элементов подборки

Дата: 20.07.2026

## Контекст

Livewire 4 предоставляет drag-and-drop sorting через `wire:sort` на container и `wire:sort:item` на stable children. Handler получает item identifier и новую zero-based position; persistence остаётся обязанностью приложения. `wire:sort:handle` ограничивает начало drag специальной ручкой, `wire:sort:ignore` защищает вложенные interactive controls, group directives предназначены для межсписочного переноса. Modifiers отсутствуют.

`CatalogCollectionEditor` уже владеет ручным порядком `CatalogCollectionItem.position`, stable `wire:key`, policy/rate-limit/transaction/cache boundary и доступными кнопками «выше/ниже». Список пагинируется по 24 элемента, а automatic sort modes только читают сохранённые manual positions и не должны переписываться drag action.

## Решение

- Добавить modifier-free `wire:sort="sortItem"` только на `<ol>` manual editor page.
- Каждый доступный item получает stable `wire:sort:item` с canonical `CatalogCollectionItem::id` рядом с существующим `wire:key`.
- Добавить pointer/touch handle с `wire:sort:handle`; action buttons завернуть в `wire:sort:ignore`.
- Сохранить видимые touch-sized up/down buttons как keyboard/no-drag baseline.
- Handler переводит page-local zero-based position в absolute index через current `collectionPage` и постоянное окно 24.
- Новый service method под collection row lock повторно авторизует actor, проверяет membership, target и текущий item внутри того же bounded window, переставляет только затронутый диапазон, повышает `content_version` и использует canonical cache invalidator.
- Межстраничный и межгрупповой drag запрещён; `wire:sort:group*` не добавляются. Drag не изменяет `sort_mode` и не переносит title между collections.

## Совместимость, production и rollback

Routes, public/API shape, schema/indexes, stored identities, automatic ordering, imports, recommendations and cache domains остаются прежними. Увеличения payload до полного массива ID нет: browser отправляет один item ID и position, server window ограничен 24. Rollback удаляет sort directives/handle, component handler и service method; существующие up/down controls полностью сохраняют управление порядком. Migration, dependency, environment, backup restore, queue/cache clear не нужны.
