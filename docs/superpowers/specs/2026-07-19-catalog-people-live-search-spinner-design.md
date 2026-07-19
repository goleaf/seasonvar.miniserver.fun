# Realtime-поиск актёров и режиссёров — дизайн исправления

Обновлено: 19.07.2026

## Цель и причина

На `/titles` поля «Актёры» и «Режиссёры» остаются видимыми и ищут варианты в реальном времени. Spinner рядом с полем виден только во время запроса этого поля; выбор человека сразу обновляет фильмы через существующую группу islands `catalog-live` без полной перезагрузки.

Дефект воспроизведён в Chromium: иконка имеет `fa-spinner fa-spin hidden`, но нелайерный `display` FontAwesome перекрывает layered Tailwind `hidden`, поэтому вычисленное значение в покое — `display: block`. Рядом существует второй архитектурный разрыв: `CatalogSeries::$optionSearch` и `catalogFacets()` уже умеют выполнять bounded server-side поиск, но поле не привязано к ним и использует отдельный JavaScript `fetch` к `/api/catalog/people`.

## Решение

- Поле получает `wire:model.live.debounce.300ms="optionSearch.<type>"`; `CatalogSeries::updated()` сохраняет allowlist `actor|director`, нормализацию и максимум 80 символов, а facet query — минимум 2 символа и максимум 24 варианта.
- FontAwesome-иконка помещается внутрь обычного `<span wire:loading.delay wire:target="optionSearch.<type>">`. Wrapper, а не иконка, владеет `display`, поэтому CSS-cascade больше не может показать spinner в idle.
- Deferred `@island(name: 'catalog-live', defer: true)` и три одноимённых result islands уже связаны Livewire 4.3.3. `wire:model.live` checkbox возвращает realtime-обновление результатов, счётчиков и URL-state.
- Duplicate people combobox/fetch удаляется из `resources/js/app.js`. Совместимый публичный `GET /api/catalog/people` сохраняется для API-клиентов.
- Поле сохраняет label, `type="search"`, `maxlength="80"`, локализованный placeholder и 44 px control. Варианты остаются touch-sized checkbox-строками без absolute dropdown и внутреннего scroll.

## Cross-feature и production

Маршруты, API shape, visibility, authentication, authorization, privacy, SEO, cache, imports, admin, premium, region/legal и database не меняются. Livewire requests уже исключены из full-response cache по `X-Livewire`. Deploy меняет только code/assets; миграция, dependency, `.env`, data mutation и cache clear не требуются. Rollback — revert task commit и возврат предыдущих manifest/assets.

Проверенные version-specific источники: [Livewire `wire:model`](https://livewire.laravel.com/docs/4.x/wire-model), [loading targets](https://livewire.laravel.com/docs/4.x/wire-loading), [named/grouped islands](https://livewire.laravel.com/docs/4.x/islands).
