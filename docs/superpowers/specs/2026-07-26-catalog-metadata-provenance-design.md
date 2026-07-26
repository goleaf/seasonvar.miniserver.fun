# Design: provenance метаданных каталога

Дата: 26.07.2026
Статус: approved by explicit user implementation request

## Цель

Для текущего значения каждого ключевого поля карточки редактор должен видеть:

- нормализованное отображаемое значение;
- источник;
- время последнего подтверждения;
- confidence от `0` до `100`;
- состояние `подтверждено`, `проверить` или `конфликт`.

История наблюдений и выбранных значений должна позволять объяснить
`quality_score`, находить конфликты между источниками и в будущем безопасно
исключать слабые автоматические метаданные из публикации.

## Найденные существующие boundaries

- `catalog_titles.provider_field_values` хранит только последний baseline
  отдельных полей Seasonvar и не является историей наблюдений.
- `catalog_title_tag_sources` уже является канонической provenance-таблицей
  назначений тегов, а `tag_provider_mappings.confidence` хранит confidence
  provider mapping. Дублировать эти строки в generic metadata observations
  нельзя.
- `catalog_title_quality_snapshots` и
  `catalog_title_quality_issues` уже являются каноническими производными
  таблицами оценки. Предложенное имя `catalog_quality_issues` реализуется
  расширением существующей таблицы, а не вторым хранилищем проблем.
- Единственная публичная команда импорта остаётся
  `php artisan seasonvar:import`; пересчёт качества остаётся
  `catalog:quality-refresh`.
- Административный HTML уже принадлежит full-page Livewire-компоненту
  `/admin/catalog/quality`.

## Выбранная модель

### `catalog_metadata_observations`

Append-preserving evidence для полей конкретного `CatalogTitle`.

- явный `catalog_title_id`, без polymorphic relation;
- nullable `source_id` и `source_page_id`;
- `field_key`, `source_kind`, стабильный `source_key`;
- JSON-значение и SHA-256 нормализованного значения;
- `confidence`, `is_current`, `is_publication_eligible`;
- `first_observed_at`, `last_confirmed_at`.

Идентичное значение того же source обновляет `last_confirmed_at`.
Изменившееся значение создаёт новое наблюдение, а предыдущее становится
неактуальным. Raw source URL, HTML и закрытые media URL не сохраняются.

### `catalog_field_versions`

История именно выбранного значения поля:

- монотонный `version` внутри `catalog_title_id + field_key`;
- ссылка на observation, если она существует;
- actor для редакторского выбора;
- snapshot значения/hash;
- `selected_at` и `superseded_at`.

Новая версия создаётся только при фактическом изменении нормализованного
значения. Повторное подтверждение не раздувает историю.

### `catalog_metadata_conflicts`

Текущий конфликт выбранного и конкурирующего наблюдения:

- поле и title;
- nullable ссылки на обе observation;
- оба value hash;
- severity/status;
- first/last detected и resolved timestamps.

Повторный импорт обновляет существующий conflict. Совпавшие значения
переводят его в `resolved`; строки не удаляются, чтобы сохранялась
объяснимость.

### `catalog_quality_runs`

Bounded operational record каждого запуска существующего
`catalog:quality-refresh`: trigger, status, scoring version, requested limit,
processed/issue counts, timestamps и безопасный failure code. В snapshot и
issue добавляются nullable ссылки на run.

### Теги

Теги не копируются в `catalog_metadata_observations`.
Административный presenter объединяет:

- `catalog_title_tag_sources`;
- `tag_provider_mappings.confidence`;
- moderation/visibility текущего `Tag`;
- факт отсутствия current provenance.

Тег без current provenance получает объяснимый fallback confidence `12` и
статус `проверить`. Editorial provenance получает `100`. Provider confidence
берётся из mapping. Pending/rejected mapping и не подтверждённое назначение
не становятся новым автоматическим публичным назначением: существующий
`TagImportSynchronizer` продолжает пропускать только globally assignable
теги.

## Поля первого production-safe покрытия

- `title`;
- `original_title`;
- `type`;
- `year`;
- `description`;
- `poster_url`;
- `genres`;
- `countries`.

Seasonvar snapshot записывает все эти observations внутри существующей
import transaction. Для incomplete taxonomy snapshot relation evidence
обновляется, но не считается publication-eligible. Редакторское сохранение
scalar-полей создаёт observation с confidence `100` и новую выбранную
версию только для изменённых полей.

## Confidence policy

- подтверждённое редактором значение: `100`;
- прямое непустое scalar-наблюдение Seasonvar: `98`;
- полный taxonomy snapshot Seasonvar: `96`;
- неполный taxonomy snapshot: `70`, publication-ineligible;
- отсутствующее provider-значение: `35`, publication-ineligible;
- legacy current value без наблюдения: `60`;
- imported tag без current provenance: `12`, status `проверить`.

Политика детерминирована и тестируется. Confidence — объяснимый signal, а не
вероятностное обещание истинности.

## Интеграция и порядок записи

1. Parser валидирует `SeasonvarCatalogData`.
2. В существующей retryable import transaction сохраняются title и relations.
3. Один Seasonvar adapter передаёт provider observations из prepared-page
   apply и локального metadata backfill.
4. Recorder фиксирует current selected versions и открывает/разрешает
   conflicts.
5. После commit существующие search/sync boundaries работают без изменения,
   а оба импортных пути пакетно помечают известные quality snapshots dirty.
6. Admin title transaction после сохранения записывает editorial
   observations/versions до audit commit.
7. `catalog:quality-refresh` создаёт run, передаёт его recalculator и
   завершает success/failed без раскрытия exception message в БД.

## Query и индексы

- Current field lookup:
  `(catalog_title_id, field_key, is_current, last_confirmed_at)`.
- Conflict queue:
  `(status, severity, last_detected_at, catalog_title_id)`.
- Current version:
  `(catalog_title_id, field_key, superseded_at, version)`.
- Runs:
  `(status, started_at, id)`.
- Provider lookup:
  `(source_id, source_key, is_current)`.

Quality page сначала paginates snapshots, затем выполняет bounded grouped
queries только для ID текущей страницы. Запросов из Blade и per-card
queries нет.

## Security и privacy

- Страница сохраняет текущий `content.view` gate.
- Новых public/API/write routes нет.
- Значения выводятся только через escaped Blade.
- Description ограничивается для административного списка.
- Raw source URLs, source HTML, media URLs, secrets и exception messages не
  попадают в provenance/UI/run failure.
- Все source/field/status identifiers выбираются сервером, SQL использует
  bindings/Eloquent.

## Совместимость

Не меняются public routes, route names, JSON resources, query parameters,
catalog title binding, search/tag URLs, public tag eligibility, importer
command, cache keys, notification/player/SEO contracts. Новые таблицы
additive; до миграции quality center должен продолжать показывать старую
очередь без provenance, а importer/admin recorder безопасно пропускает
запись, если schema ещё не развернута во время rolling deployment.

## Rollout и rollback

1. Согласованный backup существующей SQLite перед migration.
2. Deploy code и additive migration.
3. Smoke schema/foreign keys.
4. Новые imports/admin writes постепенно заполняют evidence.
5. Bounded `catalog:quality-refresh --limit=...` создаёт run.
6. Проверка quality center без private URL.

Rollback приложения допускает старый код при оставшихся additive tables.
Полный rollback migration удаляет только новый evidence/run слой и nullable
run FKs; authoritative catalog/tag/quality данные не удаляются. Cache flush,
reindex и массовый backfill для rollback не нужны.

## Acceptance

- Повторное одинаковое provider observation обновляет confirmation, но не
  создаёт field version.
- Изменившееся provider значение создаёт observation/version, если оно
  выбрано, либо open conflict, если сохранён editorial current value.
- Editorial save создаёт confidence `100` observation и selected version.
- Конфликт разрешается при совпадении значений и остаётся в истории.
- Quality run success/failure завершается детерминированно.
- Quality center показывает source/date/confidence/status полей и тегов,
  включая fallback `12` для тега без provenance.
- Неавторизованный пользователь не видит страницу.
- Page-sized grouped queries не создают N+1.
- Incomplete taxonomy observation не выдаётся за выбранное значение:
  selected version отражает фактические additive relations.
- Обычный importer и metadata backfill записывают одинаковый provenance.
- Migration/rollback, focused tests, Pint, broad suite, build и docs checks
  проходят либо внешний blocker фиксируется честно.
