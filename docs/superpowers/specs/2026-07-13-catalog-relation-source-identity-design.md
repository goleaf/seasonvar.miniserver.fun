# Дизайн стабильной identity справочников при импорте из нескольких источников

Дата: 13.07.2026

## Цель

Повторный импорт и обновление данных из Seasonvar или будущего источника не должны создавать новую строку актёра, режиссёра, жанра, страны, возрастного рейтинга, перевода, статуса, сети, студии или тега, если источник уже сообщал тот же объект. Смена подписи, регистра, пунктуации, кириллицы/латиницы или URL-представления не должна менять закреплённую identity.

Существующая нормализация имени и уникальный canonical slug остаются глобальной защитой от одинаковых значений между источниками. Новый слой добавляет стабильную provider identity для повторных наблюдений одного источника.

## Выбранный подход

Добавляется единый типизированный реестр `catalog_relation_source_identities`. Он не является polymorphic Eloquent-связью и не заменяет десять явных справочников или pivot-таблиц. Строка реестра хранит:

- `source_id` — существующий источник;
- `relation_type` — одно из десяти значений `CatalogTaxonomyRegistry`;
- `source_key_hash` — SHA-256 от namespaced стабильного external ID или канонического HTTPS URL;
- `canonical_key` — slug глобальной записи справочника;
- timestamps.

Unique `(source_id, relation_type, source_key_hash)` гарантирует одно решение для одного provider object. Индекс `(relation_type, canonical_key)` обслуживает maintenance и перенос identity при слиянии.

Raw external ID и raw URL в реестре не сохраняются. Первый пригодный `source_url` в существующей строке справочника остаётся ограниченным provenance-полем для диагностики.

## Почему не другие варианты

Только canonical slug не защищает от реального переименования объекта одним источником: новый текст может породить новый slug. Десять отдельных identity-таблиц дали бы более прямые foreign keys, но повторили бы одну схему, сервис и индексы десять раз. Типизированный реестр сохраняет существующие явные catalog relations и создаёт одну обязательную границу для любого adapter.

## Контракт входных данных

`CatalogRelationSyncer` продолжает принимать нормализуемое имя и тип. Adapter дополнительно может передать `source_external_id`; если его нет, используется валидный канонический `source_url`. `source_id` по умолчанию берётся из `CatalogTitle`, поэтому существующий Seasonvar serial importer не получает новый обязательный аргумент.

При наличии external ID ключ формируется в namespace `external-id`; URL формируется в namespace `url`. Adapter обязан передавать стабильный provider ID без секретов либо URL, уже очищенный от временных query-параметров. Seasonvar adapter продолжает разрешать только `https://seasonvar.ru/` и использует `SeasonvarUrl` для канонизации.

## Поток записи

1. Имя проходит `CatalogRelationNameSanitizer`, тип проверяется через `CatalogTaxonomyRegistry`.
2. Для каждого наблюдения вычисляется fallback canonical key.
3. Если есть provider key, identity registry атомарно закрепляет или возвращает уже закреплённый canonical key. Повторное наблюдение не может перезаписать прежнее решение новым именем.
4. При первом переходе существующей базы lookup по нормализованному `source_url` выполняется до закрепления identity. Это привязывает уже существующую строку без полного production backfill.
5. Общий syncer выполняет bulk upsert по canonical key и `syncWithoutDetaching` pivot-связей.
6. Кириллическая подпись остаётся предпочтительной только среди эквивалентных canonical имён; стабильная identity не разрешает случайному новому тексту переименовать другую сущность.

`SeasonvarTaxonomyPageImporter` использует тот же registry, поэтому metadata-page import и relations, извлечённые из страницы сериала, не образуют две независимые identity-системы.

## Конкурентность и ошибки

Identity закрепляется через unique constraint и `insertOrIgnore`, затем перечитывается из базы. При двух одновременных импортёрах оба получают canonical key победившей строки. Catalog writes остаются внутри существующей короткой retry-aware transaction Seasonvar; будущие adapters обязаны вызывать общий syncer внутри своей catalog transaction.

Невалидный type, source key или HTTPS URL не создаёт identity. Импорт имени всё ещё может безопасно продолжиться по canonical slug без provider identity. Никакие raw credentials, приватные URL или provider payload не попадают в identity и progress events.

## Maintenance

`CatalogMetadataDeduplicator` после слияния legacy-дублей обновляет identity со старых canonical keys на выбранный ключ и удаляет строки неподдерживаемых типов или несуществующих источников через foreign key. Операция bounded, идемпотентна и запускается только в уже существующем full-cycle/queued-finalizer maintenance; новая публичная команда не добавляется.

На production migration применяется только после завершения активных import jobs и резервной копии SQLite. Создание таблицы additive; существующие справочники и pivot не переписываются migration. Identity текущих строк заполняется лениво при первом повторном наблюдении, причём lookup по сохранённому provenance URL не даёт этому первому обновлению создать дубль.

## Проверки

Тесты должны доказать:

- повторный импорт с тем же external ID и изменившимся именем переиспользует одну строку;
- две разные source identity с эквивалентными латинским и кириллическим именами сходятся в один canonical slug;
- URL fallback после канонизации стабилен;
- все десять типов проходят один registry-driven путь;
- taxonomy-page importer закрепляет identity в том же реестре;
- unique constraint не допускает второго решения для одного provider key;
- relation sync failure откатывает справочник, pivot и identity вместе;
- maintenance переносит identity при legacy merge и повторный запуск ничего не меняет;
- миграции проходят на пустой SQLite, focused importer tests и полный PHPUnit suite сохраняют существующие контракты.

## Границы решения

Автоматическое fuzzy-сопоставление разных реальных людей не добавляется: одинаковое звучание без стабильного provider key недостаточно для безопасного объединения. Если будущий источник не предоставляет ни ID, ни стабильный URL, остаётся только текущая deterministic canonical-name identity. Ручное разделение однофамильцев, глобальная person-knowledge-base и внешние authority IDs не входят в эту задачу.
