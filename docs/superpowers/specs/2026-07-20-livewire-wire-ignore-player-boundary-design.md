# Livewire `wire:ignore` — точная граница проигрывателя

Дата: 20.07.2026

## Контекст и официальный контракт

`wire:ignore` запрещает Livewire morph содержимого выбранного элемента и предназначен прежде всего для DOM, которым владеет сторонняя JavaScript-библиотека. `wire:ignore.self` игнорирует только изменения атрибутов корневого элемента, продолжая morph его потомков.

В проекте найдено ровно одно application usage: media shell `CatalogTitlePlayer`, которым после рендера владеют Plyr и HLS.js. Shell имеет `wire:key`, зависящий от media ID и authorization version; JavaScript создаёт, обновляет и уничтожает вложенные controls, media source, status, captions, countdown и dialog lifecycle. Livewire-owned loading overlay, выбор media и portal actions находятся вне shell.

## Решение

- Сохранить единственный полный `wire:ignore` на текущем keyed player shell.
- Не заменять его на `.self`: Plyr/HLS изменяют потомков, поэтому Livewire morph внутри shell конфликтовал бы с library-owned DOM.
- Не расширять boundary до всего `CatalogTitlePlayer`: server-owned media selection, ошибки, loading и personal actions должны продолжать обновляться Livewire.
- Не добавлять ignore к native dialogs, help editor, filters или forms: их DOM либо не переписывается third-party library, либо обязан отражать server state.
- Добавить статический characterization test для точного inventory, ключа и расположения Livewire-owned controls снаружи.

## Совместимость и rollback

Production Blade/JavaScript остаются без изменений. Player grants, signed playback URLs, progress, HLS retry, captions, localization, authorization, routes, cache, schema, dependencies и environment не меняются. Rollback — удалить test/docs записи; данные и assets не восстанавливаются.
