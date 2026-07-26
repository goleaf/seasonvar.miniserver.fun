# Центр качества каталога

Дата: 26.07.2026.

Статус: approved for implementation. Пользователь прямо потребовал
самостоятельно реализовать функцию, проверить её и довести до commit/push без
остановки на дополнительное согласование.

## Проблема и измеренный baseline

Каталог содержит 33 002 активные карточки: 24 887 сериалов, 3 924
документальных проекта, 3 034 аниме и 1 157 передач. Проверка качества сейчас
распределена между импортёром, provenance тегов, media health, полями
`SourcePage`, ограничениями сезонов/серий и рейтингами, но единой объяснимой
оценки и административной очереди нет.

Кейс `CatalogTitle #16585` «Цветок зла/The Flower Of Evil» подтверждён
фактической базой: восемь импортированных тегов не имеют current provenance и
не соответствуют описанию/жанрам, тогда как четыре правдоподобных тега имеют
актуальные Seasonvar observations. Это отдельный диагностический сигнал;
существующий provenance-first repair остаётся владельцем безопасной очистки.

Текущий snapshot также показывает:

- 15 карточек без допустимого года, 10 без жанра, 91 без страны;
- 697 карточек без работоспособного media source;
- database unique constraints уже исключают дубликат номера внутри
  `(season_id, kind, number)`, но 457 сезонов имеют крупные разрывы;
- максимальный фактический сезон содержит 3 538 последовательных серий, поэтому
  простой абсолютный лимит номера серии дал бы ложные срабатывания;
- 880 966 media sources имеют playable health, 248 из них ещё не имеют
  `checked_at`;
- текущие рейтинги находятся в диапазоне `1.09–9.53`, а максимальное число
  обычных сезонов равно 37;
- source pages сейчас свежие, однако freshness должна оцениваться по
  `last_crawled_at`/`last_imported_at`, а не по техническому `updated_at`
  карточки.

Полный request-time aggregate media превысил безопасный диагностический
интервал. Следовательно, административный экран не может рассчитывать качество
на лету.

## Рассмотренные варианты

### 1. Вычислять оценку при каждом открытии админки

Не требует новой схемы, но повторно сканирует media/episodes/tags, создаёт
непредсказуемую задержку и не даёт стабильной очереди. Отклонено.

### 2. Добавить только `quality_score` в `catalog_titles`

Ускоряет сортировку, но не хранит объяснение, смешивает derived operational
state с публичной карточкой и потребует много отдельных индексов/флагов.
Отклонено.

### 3. Persisted snapshot + нормализованные текущие причины — выбран

Одна строка snapshot хранит `0..100`, severity, счётчики, freshness, version и
dirty state. Отдельные issue rows хранят стабильный code/category/severity,
penalty и bounded safe evidence. Очереди фильтруются одним нормализованным
индексом, а UI объясняет каждое списание.

## Архитектура и data flow

1. `CatalogTitleQualityInputLoader` получает ограниченный список title IDs.
2. Он выполняет grouped/eager queries только нужных колонок:
   title/source-page/genres/countries/tags/provenance/ratings/seasons,
   агрегаты episode numbers и media health.
3. Чистый `CatalogTitleQualityEvaluator` создаёт versioned
   `CatalogTitleQualityResult`.
4. `CatalogTitleQualityRecalculator` атомарно upsert-ит snapshot, upsert-ит
   текущие issues и удаляет только resolved issue codes этой карточки.
5. `catalog:quality-refresh` сначала выбирает missing, затем dirty, version-old
   и stale snapshots, ограничивает один запуск и обрабатывает IDs пакетами.
6. Scheduler запускает bounded refresh без overlap и на одном сервере.
7. Existing exact catalog/tag/media invalidation boundaries отмечают уже
   существующий snapshot как dirty; отсутствие snapshot остаётся естественным
   кандидатом backfill.
8. `CatalogQualityCenterPage` читает только persisted index через
   `CatalogQualityQueueQuery`; никаких исходных URL или полного Eloquent graph
   в Livewire state нет.

## Сигналы и правила оценки

Оценка начинается со 100 и уменьшается на versioned penalties, после чего
ограничивается диапазоном `0..100`.

- Название: пустое/placeholder/URL/без букв — critical.
- Полнота: валидный год, страна, жанры, poster и содержательное описание.
- Теги: imported tag без current provenance и без нормализованного
  token/prefix overlap с title/original/description/genres считается
  подозрительным. System/editorial tags не получают это автоматическое
  обвинение.
- Конфликты: непустое сохранённое editorial значение сравнивается с последним
  `provider_field_values`; evidence содержит только stable field codes.
  Большое расхождение provider ratings также относится к data conflict.
- Переводы: URL/control/placeholder/quality-only/без букв и чрезмерно длинные
  значения агрегируются в `strange_translation`.
- Серии: DB uniqueness учитывается как доказанная граница; проверяются
  отрицательные/нулевые regular numbers, существенные gaps относительно размера
  сезона и противоречия actual/released/total. Большой последовательный сериал
  не штрафуется только за высокий номер.
- Freshness: max `last_crawled_at`/`last_imported_at`; never checked и
  configurable stale threshold образуют отдельную очередь.
- Видео: отсутствие published playback location или отсутствие playable
  `active|degraded` source — critical; never checked playable rows — warning.
- Рейтинги/сезоны: out-of-range/negative/чрезмерные значения и provider
  disagreement; аномальный season count/number оценивается по отдельным
  консервативным порогам.

Penalty агрегированного сигнала ограничен, поэтому сотни шумных тегов не могут
в одиночку переполнить score. Critical severity формирует отдельную очередь и
не вычисляется из цвета или клиентского состояния.

## Схема и индексы

`catalog_title_quality_snapshots`:

- PK/FK `catalog_title_id`, cascade delete;
- `quality_score`, `severity`, issue/critical counts;
- `needs_refresh`, `scoring_version`;
- `last_source_checked_at`, `evaluated_at`, timestamps;
- indexes для score order и refresh eligibility.

`catalog_title_quality_issues`:

- FK title, stable `code`, `category`, `severity`, `penalty`;
- bounded JSON evidence без source/media URL, credentials и raw provider
  payload;
- `first_detected_at`, `last_detected_at`, timestamps;
- unique `(catalog_title_id, code)`;
- queue indexes `(category, severity, catalog_title_id)` и
  `(severity, catalog_title_id)`.

Индексы соответствуют реальным admin filters и refresh query; отдельный индекс
на каждый boolean не создаётся.

## Административный UX

Новый full-page Livewire route `/admin/catalog/quality`:

- наследует canonical admin middleware;
- требует `content.view`, поскольку экран read-only;
- имеет отдельный permission-filtered navigation item;
- поддерживает query string для queue/search/score range/sort/per-page;
- нормализует и валидирует все значения server-side;
- сбрасывает paginator при изменении фильтров;
- показывает coverage и очереди:
  critical, suspicious tags, data conflicts, no poster, no video,
  suspicious episodes, stale;
- выводит score, freshness, bounded reasons и ссылку в существующий catalog
  admin;
- имеет loading, empty, unavailable/error и mobile states;
- использует только русско-/англоязычные translation catalogs и existing light
  UI components/classes.

## Security, privacy и compatibility

- Нет нового public/API contract и влияния на search/recommendation/SEO ranking.
- Все admin decisions server-side; скрытая кнопка не используется как auth.
- SQL строится через Eloquent/query builder и allowlisted sort/filter maps.
- Evidence ограничено публичными names, field codes и числовыми counters.
- Raw source URL, playback URL, exception, provider payload и user data не
  сохраняются и не выводятся.
- Public routes, route model binding, API Resources, cache keys, import command,
  player, permissions и existing database relations сохраняются.
- New schema additive и reversible; production backfill не выполняется в
  migration.

## Performance и operations

- Request path читает только snapshots/issues и paginates 15/25/50 rows.
- Recalculation использует bounded batches, grouped aggregates и selected
  columns; no queries in Blade и no per-episode Eloquent load.
- Scheduler не делает network calls и не подменяет media health check.
- Initial deployment постепенно заполняет missing snapshots; operator может
  безопасно повторять bounded command с большим `--limit`.
- Rollout: backup assessment, additive migration, deploy code/assets, migrate,
  restart scheduler/workers only as required, run bounded refresh and inspect
  coverage/query plan.
- Rollback code сохраняет additive tables; schema rollback допустим только если
  previous code не использует их и operational window подтверждён. Derived
  snapshots можно безопасно пересоздать, production catalog data не меняется.

## Acceptance

- Полная карточка с актуальным playable media получает высокий score и без
  issues.
- Missing metadata/media, stale source, conflicts, strange translations,
  rating/season anomalies и episode gaps получают стабильные issues/penalties.
- Flower-of-Evil fixture попадает в suspicious-tag queue с восемью шумными
  tags, не обвиняя четыре current provider tags.
- Score всегда `0..100`; повторный recalculation идемпотентен и сохраняет
  `first_detected_at`.
- Queue filters комбинируются с search/score/sort/pagination; `0` не теряется.
- Guest, ordinary user и admin без `content.view` не получают доступ.
- Query plan использует new indexes; no N+1/query growth between 1 and 20 rows.
- Migration up/down, focused/full tests, Pint/static/build/docs и desktop/mobile
  Playwright verification проходят либо внешний blocker честно фиксируется.
