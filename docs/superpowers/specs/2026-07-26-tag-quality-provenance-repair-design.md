# Provenance-first качество тегов и очистка demo-footprint

Дата: 26.07.2026.

Статус: approved for implementation. Пользователь прямо потребовал реализовать рекомендованное исправление, продолжить до проверок, commit и push без отдельной остановки на согласование.

## Проблема и доказанный root cause

У `CatalogTitle` 16585 «Цветок зла/The Flower Of Evil» публично отображаются восемь лишних тегов: «Реанимация», «академия», «перерождение», «Власть», «друиды», «Гномы», «Фантомы» и «Бег». Четыре правдоподобных тега имеют current Seasonvar provenance; все восемь шумных связей существуют только в aggregate `catalog_title_tag`.

Последний и все сохранённые source snapshots не содержат шумные labels. Точный повтор прежнего `DemoOrganizationStage::assignPublicTags()` с `seasonvar-demo-v1`, первым набором из 800 public tags и title ID 16585 возвращает ровно эти восемь tag IDs. Catalog-wide read-only reconstruction показал:

- 16 490 выбранных demo-алгоритмом тайтлов;
- 123 305 ожидаемых deterministic pairs;
- 123 121 pairs всё ещё attached;
- 123 057 attached pairs не имеют current provenance;
- 64 совпавшие pairs имеют current provider/editorial provenance и должны сохраниться;
- 229 собственных `demo-tag-*` назначены 4 841 тайтлу и не имеют current provenance.

Следовательно, parser/mapping сейчас не является источником конкретной ошибки. Root cause — demo-seeder, который записал случайную глобальную классификацию напрямую в aggregate pivot без `TagAssignmentService`/`TagImportSynchronizer`, после чего canonical importer правильно не решился удалить неизвестные legacy assignments.

## Рассмотренные варианты

### 1. Отвязать шесть названных тегов от одного сериала

Быстро исправляет одну карточку, но оставляет «Власть», «Бег» и более 123 тысяч аналогичных связей. Поиск, рекомендации, tag pages и SEO остаются загрязнёнными. Вариант отклонён как симптоматический.

### 2. Удалить все `catalog_title_tag` без provenance

Покрывает demo-шум, но может удалить старое ручное назначение, созданное до canonical provenance. Сам факт отсутствия дочерней строки не доказывает demo ownership. Вариант отклонён как слишком широкий и необратимо рискованный.

### 3. Точно восстановить deterministic demo-footprint и требовать provenance — выбран

Repair использует неизменяемые inputs исторического алгоритма: `seasonvar-demo-v1`, configured target, стабильный order тайтлов/tag IDs и прежние scope keys. Он удаляет только пересечение exact footprint с фактически attached rows без любого current provenance. Exact `demo-tag-{version-hash}-*` дополнительно являются доказуемо собственными и после снятия неподтверждённых assignments архивируются, а не hard-delete.

Новые demo-запуски полностью прекращают global tag creation/assignment. Personal tags, collections и остальные user fixtures сохраняются.

## Архитектура

Новый `DemoPublicTagAssignmentCleaner` остаётся внутри существующего `app/Services/DemoData`:

1. строит historical tag pool bounded значением `demo-data.public_tag_target`;
2. читает selected title IDs через существующий `DemoTitleSelector`;
3. восстанавливает 3–12 pairs через `DemoStableValue`;
4. chunk-ами читает только pivot и current provenance projections;
5. объединяет exact legacy pairs с любыми неподтверждёнными assignments exact owned demo tags;
6. fail-closed проверяет owned fingerprint, достаточную match density, отсутствие active Seasonvar run и `building|evaluated` recommendation build;
7. одной retry-aware transaction удаляет pairs группами по tag, архивирует только eligible owned demo tags, удаляет stale active recommendation rows и bulk-mark-ит affected titles dirty;
8. после commit выполняет один global tag/catalog cache generation bump.

Новая таблица, migration, queue, scheduler, route, API, UI или frontend dependency не нужны. Существующая production-gated команда `demo:repair-user-portal` получает дополнительные dry-run counters и вызывает cleaner при `--force`.

## Data safety и concurrency

- Exact provider/editorial current provenance всегда сохраняет assignment, даже если пара совпала с demo selection.
- Не совпавший с historical footprint imported/system/editorial tag сохраняется.
- Owned tag определяется одновременно по exact versioned `code`, соответствующему deterministic UUIDv5 `public_id`, `type=system` и `source=system`; имя и `slug` намеренно не являются ownership evidence, потому что штатная нормализация уже меняла slug этих строк. Prefix или похожее имя без совпавшего UUID недостаточны.
- Cleanup не удаляет `tags`, translations, mappings или provenance: owned rows архивируются с pre-state.
- Active Seasonvar import или activatable recommendation build блокирует write.
- Production command по-прежнему требует `--backup-confirmed --writers-paused`; flags не заменяют фактический operator runbook.
- Delete повторно применяет `NOT EXISTS current provenance`, поэтому новая подтверждённая запись между inspect и transaction не удаляется.
- Вся mutation transaction атомарна; partial cleanup не фиксируется.

## Performance и SQL

Expected pairs генерируются bounded streaming/chunk loop без full Eloquent models. Reads выбирают только два FK и current flag, используют:

- `catalog_title_tag(catalog_title_id, tag_id)` и обратный `(tag_id, catalog_title_id)`;
- `catalog_title_tag_sources(tag_id, catalog_title_id, is_current)`;
- `tags.code` unique и primary-key order;
- published title primary-key order.

Удаление группируется по tag ID: около 800 bound queries вместо 123 тысяч per-row statements. Dirty markers upsert-ятся chunk-ами по существующему unique title key. Cache invalidation глобальная, потому что affected title count превышает targeted 1 000-ID boundary.

## Интеграции

- Search/tag filters/pages/counts получают authoritative pivots без изменения URL/query/API contracts.
- Similarity rows, где affected title source или candidate, удаляются и не показывают устаревшие tag reasons; dirty tracker готовит scoped/full rebuild существующим Seasonvar lifecycle.
- Collections не изменяются; улучшение приходит через рекомендации/search, которые читают очищенную taxonomy.
- SEO/sitemap cache versions меняются через existing `TagCacheInvalidator`.
- Personal tags, user state, history, progress, feedback, collections, comments, reviews, media и source snapshots не меняются.
- Authorization не добавляется в data service: единственный production entry остаётся operator-only Artisan command с текущим explicit safety gate.

## Rollback

До production write обязателен согласованный SQLite backup и `PRAGMA quick_check`/foreign-key evidence. Code rollback возвращает старое поведение, но не должен повторно запускать загрязняющий seed. Data rollback возможен только восстановлением согласованного backup, потому что aggregate pivot не хранит достаточную историю для автоматического восстановления удалённого demo-noise. Архив exact demo tags обратим отдельным forward repair после проверки, но штатный rollback не выдает их снова как публичную классификацию.

## Acceptance

- Exact Flower-of-Evil fixture теряет все восемь demo assignments и сохраняет четыре current provider tags.
- Любой current provider/editorial pair сохраняется.
- Unrelated no-provenance pair вне exact footprint сохраняется.
- Exact demo tags архивируются, hard delete отсутствует.
- Повторный repair — no-op.
- Missing fingerprint, low-confidence reconstruction, active import/build или production без safety flags fail closed.
- Focused PHPUnit, full related DemoData/tag/recommendation tests, Pint, PHPStan, Rector dry-run, full suite, build/docs gates и production read-only EXPLAIN проходят либо честно фиксируют внешний blocker.
