# Homepage Import Cache Contention Design

Дата: 25.07.2026.

Статус: `approved_for_tdd_implementation`.

## Контекст и измеренная причина

Предыдущая оптимизация главной уже уменьшила рабочий HTML примерно до
740 КБ, обычный cold TTFB до 0,83–1,32 секунды и hot TTFB до
0,06–0,08 секунды. Сохраняющийся production-риск находится не в Blade и не
в основном запросе главной, а в конкурирующей фоновой работе:

- scheduled `cache:warm-catalog --queue --refresh` каждые десять минут
  принудительно перестраивает точную статистику и home metrics, даже если
  текущие снимки ещё свежие;
- средняя измеренная длительность перестройки `CatalogStats` превышает
  54 секунды, home metrics — несколько секунд;
- каждая успешно импортированная title group массового run вызывает
  scoped-инвалидацию и proactive HTTP-прогрев тайтла;
- финал полного run всё равно выполняет глобальную инвалидацию
  `TitleDetail`, поэтому предыдущий per-title прогрев становится
  недостижимым;
- `WarmCatalogCaches` не учитывает активный импорт, хотя
  `WarmCatalogTitlePage` и `WarmPublicCatalogCaches` уже используют
  `SeasonvarImportActivity`.

Это создаёт SQLite contention, очередь бесполезной работы и редкие тяжёлые
cold-периоды, хотя нормальный cache HIT уже быстрый.

## Рассмотренные варианты

### 1. Только увеличить TTL

Минимальный diff, но он лишь реже проявляет источник нагрузки. Массовый
per-title fan-out и принудительный `--refresh` сохраняются. Вариант
отклонён как неполный.

### 2. Stability-first orchestration

Сохранить точную инвалидацию, но не выполнять работу, которая заведомо будет
перезаписана или глобально инвалидирована:

1. Для title group полного импорта выполнять scoped-инвалидацию без
   proactive warm и без collection-derived global invalidation; для
   visitor/targeted run сохранить текущие dependent scopes и warm.
2. Не claim-ить общий warm intent во время активного импорта. Вместо этого
   ставить один уникальный delayed tail, оставляя durable request pending.
3. Убрать `--refresh` из регулярного расписания, сохранив explicit refresh
   для deployment/manual/finalization boundaries.
4. Читать home metrics с TTL `CatalogStats`, сохраняя домен
   `Homepage`, locale key и отдельный version scope `metrics`.

Вариант выбран пользователем. Он устраняет доказанную нагрузку без schema,
route, API или cache-key migration.

### 3. Материализованный homepage read model

Даёт минимальный cold SQL, но требует новой таблицы, backfill,
transactional handoff, rolling-deploy compatibility и отдельного recovery
пути. Этот этап допускается только если после варианта 2 измеренный normal
cold TTFB остаётся выше 1,5 секунды.

## Утверждённый data flow

### Массовый импорт

1. `FinalizeSeasonvarImportTitleGroup` определяет visitor run до вызова
   invalidator.
2. `CatalogCacheInvalidator::importedTitleChanged()` всегда:
   - bump-ит scoped `TitleDetail`;
   - для visitor/targeted run инвалидирует зависимые collection caches.
3. Collection-derived global scopes и proactive warm полного run
   откладываются до существующего global handoff в
   `FinalizeSeasonvarQueuedImport`. Это сохраняет publication boundary и не
   меняет individual title correctness.

### Общий critical warm

1. `CatalogCacheWarmRequestStore` остаётся durable authority intent.
2. `WarmCatalogCaches` до `claim()` проверяет
   `SeasonvarImportActivity::active()`.
3. При активном импорте job не меняет pending request, а dispatch-ит
   уникальный delayed replacement.
4. `ShouldBeUniqueUntilProcessing` снимает lock перед `handle()`, поэтому
   replacement снова получает unique lock и coalesce-ит последующие
   producers.
5. После завершения импорта существующий job claim-ит и подтверждает work
   тем же контрактом, что сейчас.

### Регулярный прогрев и метрики

- Scheduler использует `cache:warm-catalog --queue`, не forced refresh.
- `--refresh` остаётся публичной явной опцией команды.
- Home metrics сохраняют текущий key shape и version scope, но получают
  более длинную TTL policy `CatalogStats`: 30 минут fresh, 24 часа stale.
- Явный `CatalogHomeMetricsCache::refresh()` по-прежнему перестраивает
  точные значения.

## Совместимость и cross-feature impact

Не меняются:

- web/API routes, response schema, Blade markup и русский интерфейс;
- authentication, authorization, premium, regional/legal и privacy
  boundaries;
- database schema, persisted importer states и counters;
- единственная публичная команда `php artisan seasonvar:import`;
- queue names, scalar job payloads, retries и global finalization;
- cache keys, domains и scoped versions;
- search, recommendations, sitemap, calendar и API sync handoffs;
- media URL-only storage и player/download contracts.

## Rollback и failure recovery

- Code rollback возвращает прежний warm fan-out; данные и cache entries
  остаются совместимыми.
- Delayed job не удаляет pending intent, поэтому interruption не теряет
  invalidation.
- Если import activity ошибочно остаётся active, warm intent остаётся в
  store и будет повторён; recovery использует существующий importer
  reconciliation, без queue/cache clear.
- Если cache store недоступен, сохраняется существующее degraded поведение;
  эта задача не заявляет исправление недоступного Memcached.
- Partial deploy безопасен при атомарном release PHP-кода: новые параметры
  имеют backward-compatible defaults, schema/env migration отсутствует.

## Критерии приёмки

- Mass title-group finalization не dispatch-ит per-title
  `WarmCatalogCaches` и не bump-ит collection-derived `Homepage`, но scoped
  title invalidation остаётся.
- Visitor/targeted title refresh сохраняет proactive warm.
- Active import не позволяет `WarmCatalogCaches` claim-ить или перестраивать
  critical caches и оставляет один delayed unique tail.
- Scheduled command не содержит `--refresh`; explicit CLI refresh работает.
- Home metrics используют TTL `CatalogStats`, сохраняя текущий namespace.
- Hot TTFB остаётся не выше 0,1 секунды; normal cold TTFB — ниже
  1,5 секунды в измеримом рабочем состоянии.
- Focused tests, affected suite, Pint, PHPStan, docs checks и final
  requirement reread проходят либо дают честно классифицированный внешний
  blocker.

## Discovery после первого GREEN

Live-проверка под run `#1255` получила последовательность `MISS 4,47 s`,
`MISS 1,35 s`, `MISS 1,43 s`; после стабилизации generation следующая пара
стала `MISS → HIT`. Production read-only count показал 32 925 тайтлов в
approved public collections. `CatalogCollectionCacheInvalidator::titleChanged()`
для каждого такого title bump-ит `Homepage`, хотя global finalizer всё равно
повышает все public domains. Поэтому сохранение collection invalidation в
каждой mass title group противоречило цели coalescing и существующему тесту
«defer global invalidation until run finalization». Решение уточнено:
mass/global group оставляет только scoped title correctness, а dependent
collection/public generations обновляются единожды terminal global
boundary. Visitor/targeted refresh сохраняет прежнее немедленное поведение.
