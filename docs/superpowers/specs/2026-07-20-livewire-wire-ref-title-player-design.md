# Livewire `wire:ref` — scoped refresh тайтла и проигрывателя

Дата: 20.07.2026

## Контекст

Livewire 4 позволяет именовать element или child component через `wire:ref`. Ref scoped текущим компонентом; server event можно адресовать конкретному child через `->to(ref: 'name')`, DOM доступен через `$refs`, streaming — через `to(ref:)`. При повторяющемся имени внутри одного component используется первый ref.

`CatalogTitleDetail::refreshCatalog()` после активного polling очищает page cache и отправляет `catalog-title-refreshed` всем экземплярам класса `CatalogTitlePlayer`. На текущей странице вложен ровно один player, которому и принадлежит refresh. Class target шире фактической parent-child boundary и зависит от глобальной component identity вместо локального назначения.

## Решение

- Добавить `wire:ref="player"` к единственному `<livewire:catalog-title-player>` внутри `CatalogTitleDetail`.
- Заменить `->to(component: CatalogTitlePlayer::class)` на `->to(ref: 'player')`.
- Сохранить имя события и payload `catalogTitleId`; child listener продолжает перепроверять locked title ID и очищать только player render caches.
- Не использовать refs для external Vite modules и DOM selectors: они управляют progressive/browser lifecycle за пределами inline Livewire script и уже имеют scoped data attributes.
- Не добавлять dynamic refs: один player на странице имеет статическое имя в пределах parent.

## Совместимость и rollback

Poll interval/visibility, SSR, URL selection, player key, listener, progress, grants, signed source, import refresh, authorization и cache invalidation сохраняются. Routes, schema, dependencies, assets и environment не меняются. Rollback — вернуть class target и удалить child ref; persistent data не затрагивается.
