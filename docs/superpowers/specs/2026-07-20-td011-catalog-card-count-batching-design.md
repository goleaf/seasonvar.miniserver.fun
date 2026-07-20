# `TD-011` catalog card-count batching design

Дата: 20.07.2026

## Контекст и подтверждённая причина

В стабильном окне без pending/reserved queue jobs обычный guest `HIT` `/titles` занял 0,07–0,08 секунды, но natural `MISS` `/titles?per_page=96` занял 20,72 секунды. Read-only вызов `CatalogTitlesPageBuilder::data(..., includeFacets: false)` для тех же 96 карточек занял 5 478,85 мс; один hydration query с тремя `withCount()` correlated subqueries занял 5 177,83 мс.

Это тот же подтверждённый query shape, который уже устранён на homepage. Здесь он повторяется в обеих ветках `CatalogTitlesPageBuilder`: обычная выдача выполняет все три count subquery для каждой строки до пагинации, а ranked search повторяет их при второй hydration выбранных ID.

## Выбранный подход

`CatalogTitlesPageBuilder` получает существующий `CatalogTitleCardCountLoader`. Основная выдача и ranked-search hydration больше не запрашивают все card counts через `withCount()`. После получения одной страницы builder передаёт bounded collection карточек в loader, который выполняет grouped season/episode/media queries по уникальным ID и выставляет те же `seasons_count`, `episodes_count` и `published_media_count`.

Count subquery сохраняется только в ID/result query, когда выбранная сортировка реально зависит от соответствующего count: `episodes_desc`, `seasons_desc` или `with_video`. После определения страницы grouped loader всё равно заполняет все три presentation attributes. Rating sort сохраняет существующий `withMax()` contract.

## Отклонённые варианты

1. Добавить отдельный cache или summary table. Это требует новой invalidation/schema architecture при наличии готового bounded loader.
2. Всегда строить отдельный ID paginator. Это расширяет diff и добавляет второй hydration query обычной выдаче без необходимости.
3. Удалить count-based sorting. Это ломает публичный URL и существующую возможность каталога.

## Совместимость и безопасность

- Сохраняются route names, query parameters, Livewire URL state, pagination, card ordering, counts, locale, visibility и authorization.
- Schema, catalog data, cache keys/payloads, queue jobs, Redis/Memcached, dependencies, assets и production services не меняются.
- Loader использует те же `availableTo()`/`forAvailableReleases()` boundaries и ограничен ID текущей страницы: максимум 96 карточек.
- Rollback code-only: вернуть `withCount($cardCounts)` в hydration queries, удалить dependency/call loader; очистка cache или data не нужна.

## Verification design

- RED вызывает реальный builder, проверяет semantic counts и запрещает correlated card-count subquery для обычной выдачи.
- Отдельное assertion подтверждает, что count-based sort всё ещё использует ровно необходимый aggregate и сохраняет порядок.
- GREEN и соседние catalog/search/cache contracts запускаются до полного project gate.
- Production evidence снимается только через read-only direct builder и natural cache MISS/HIT; cache/queue clear, retry, restart и importer interruption запрещены.
