# Livewire `wire:show` — форма сообщения об устаревшей статье

Дата: 20.07.2026

## Контекст

Livewire 4 `wire:show` переключает CSS visibility через `display: none`, не удаляя элемент из DOM. В отличие от Blade `@if`, это сохраняет DOM identity и позволяет показывать/скрывать уже отрендеренный UI. Директива не имеет modifiers.

Форма сообщения об устаревшей help-статье мала, всегда разрешена в публичном article component и сейчас добавляется/удаляется через `@if ($showReportForm)`. Её draft fields используют deferred `wire:model`; повторное создание формы не требуется. Server action `submitReport` уже валидирует reason/details, связывает actor/article/translation и после успеха скрывает форму и очищает draft.

## Решение

- Всегда рендерить report form внутри существующего публичного help article component.
- Заменить conditional `@if` на единственный `wire:show="showReportForm"` без modifiers.
- Добавить `wire:cloak`, чтобы false initial state не мелькал до Livewire/Alpine initialization.
- Связать toggle с формой через stable `id`/`aria-controls`; `aria-expanded` продолжает отражать server state.
- Сохранить текущие `$toggle`, Cancel и submit requests: inline Alpine/business JavaScript не добавляется.
- Не применять `wire:show` к native collection report dialog: его Vite lifecycle зависит от реального add/remove и dispatch events. Не заменять `wire:transition` формы создания подборки.

## Совместимость и rollback

Authorization, actor HMAC, validation, rate limits, report persistence/deduplication, translations, errors/status, routes, SEO, cache, schema, dependencies и assets сохраняются. Initial HTML немного увеличивается на малую скрытую форму. Rollback возвращает `@if` wrapper и удаляет `wire:show`, `wire:cloak`, `id`/`aria-controls`; data migration и production service action не нужны.
