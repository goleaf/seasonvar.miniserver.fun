# Конвейер импорта Seasonvar

Обновлено: 13.07.2026

## Граница данных

Единственная публичная команда — `php artisan seasonvar:import`. Последовательный и Redis queued-режимы сходятся в `SeasonvarCatalogImporter` и используют один конвейер:

1. `PoliteHttpClient` получает provider response с crawl delay, timeout и retry; успешный ответ и его hash фиксируются в `SourcePage`/`SourcePageSnapshot`.
2. `SeasonvarCatalogParser` извлекает данные без записи в базу.
3. `SeasonvarCatalogData::fromParsed()` валидирует обязательные поля, ограничения размеров и типы, нормализует строки и удаляет дубли внутри коллекций.
4. `SeasonvarCatalogIdentityResolver` ищет тайтл сначала по `(source_id, external_id)`, затем по каноническому URL hash или закреплённой source page. Название не является автоматическим идентификатором.
5. Короткая retry-aware transaction выполняет upsert тайтла, справочников, pivot-связей, provider ratings/reviews/signals, сезонов и серий и только затем помечает страницу разобранной.
6. Внешние playlist/media запросы выполняются вне catalog transaction; `source_media_key`, playback URL и unique keys делают повторную запись идемпотентной.
7. Import run counters обновляются после URL/chunk. `indexed_at` отмечает SQL-search visibility; Scout или внешний поисковый движок в проекте не установлен.
8. Полный sync/queued finalizer пересобирает рекомендации и обновляет stats cache; targeted URL run обновляет stats cache, но намеренно не запускает глобальное обслуживание каталога.
9. Новый тайтл получает `published/public`, но повторный import не меняет локальные publication status, audience, availability window, soft delete или slug. Публичный интерфейс всё равно повторно применяет `CatalogEntitlementService`.

## Идентичность и идемпотентность

- Тайтл: стабильный provider ID внутри `Source`; fallback при отсутствии ID — точный канонический URL hash/source page. Совпадение или похожесть названия не объединяет строки.
- Legacy merge допускается только для строк без `external_id`, если `SeasonvarImportGroupKey` подтверждает одну каноническую URL family. Разные непустые provider ID никогда не объединяются автоматически.
- Актёр/режиссёр: стабильная person URL используется как provider identity. Первый URL может закрепить ранее безадресную строку с тем же slug; другой стабильный URL при том же имени получает детерминированный hash suffix. Если provider не дал пригодный URL, fallback остаётся точным нормализованным именем — это известное ограничение текущей схемы без отдельной external-id таблицы.
- Жанры, страны, переводы, статусы, сети, студии, теги и возрастные рейтинги являются справочными значениями и идентифицируются нормализованным slug.
- Сезоны и серии используют существующие unique `(catalog_title_id, kind, number)` и `(season_id, kind, number)`. Pivot primary keys, alias/rating/review/recommendation unique keys и `licensed_media(catalog_title_id, source_media_key)` запрещают повторные строки.

Перед добавлением person-identity constraint проверена текущая база: обнаружен один неоднозначный повтор `actors.source_url` (`https://seasonvar.ru/actor/H&`) для разных имён. Поэтому migration добавляет только query indexes, а не небезопасный unique constraint. Сначала нужно очистить/переполучить такие обрезанные URL и только затем рассматривать уникальность.

## Владение полями

Provider владеет external/source IDs, URL/hash, crawl/import metadata, импортными рейтингами/отзывами/связями, release metadata и технической доступностью источника. Локальная редакция владеет slug, publication status, audience, availability window и soft delete.

Для `title`, `original_title`, `description` и `poster_url` используется безопасное трёхстороннее сравнение. `provider_field_values` хранит последний вход provider. Новое значение принимается, только если текущее поле пусто или всё ещё равно предыдущему provider value; отличающееся локальное значение сохраняется. Null/пустой provider value не стирает заполненное поле. У уже существующей строки без baseline первый import сохраняет текущее значение и только фиксирует provider baseline.

## Полные и частичные ответы

Parser фиксирует признаки `has_info_list`, `has_season_list` и `has_episode_script`. Отсутствие блока означает частичный ответ, а не удаление данных. Связи синхронизируются через additive `syncWithoutDetaching`, сезоны/серии/media только upsert-ятся, soft-deleted строки не восстанавливаются. Managed recommendation signals заменяются только при полном metadata snapshot. Политики удаления по complete snapshot пока нет; importer ничего не удаляет только из-за отсутствия записи в одном ответе.

## Порядок деплоя

1. Дождаться завершения активных import jobs и сделать backup SQLite.
2. Развернуть код.
3. Выполнить `php artisan migrate --force`: сначала nullable JSON `provider_field_values`, затем неуникальные person source URL indexes.
4. Перезапустить долгоживущие queue workers через `php artisan queue:restart`.
5. Выполнить targeted repeat import на тестовой/проверочной странице и сверить counts/relations до и после.

Обе migrations additive и не делают backfill или удаления. Это намеренно: отсутствие baseline заставляет первый повторный import считать существующее заполненное поле потенциально редакционным.
