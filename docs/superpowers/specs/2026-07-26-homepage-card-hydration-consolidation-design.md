# Дизайн консолидации hydration карточек главной

Дата: 26.07.2026.

Статус: approved. Пользователь прямо поручил выполнить рекомендуемый вариант
без дополнительного согласования.

## Контекст и измеренный root cause

После Task 70 web-проекция больше не строит API-only секции, но
`CatalogHomePageBuilder` по-прежнему независимо выполняет одинаковую
гидратацию `CatalogTitle` и canonical card relations для каждой включённой
секции:

- web: `latestTitles` и `videoTitles`;
- full/API: `latestTitles`, `featuredTitles` и `videoTitles`.

Проверка зафиксированного `main` подтвердила canonical card contract из пяти
taxonomy relations (`genres`, `countries`, `ageRatings`, `translations`,
`tags`) и отдельный `latestSeason` только для latest-секции. Read-only
профиль текущего production-shaped SQLite snapshot подтвердил:

- `webData()` — два одинаковых root title hydration и по две section-группы
  каждой taxonomy relation; семь последовательных samples дали медиану
  `106,70 ms`;
- `data()` — три одинаковых root title hydration и по три section-группы
  каждой taxonomy relation; медиана семи samples `193,98 ms`;
- ещё одна taxonomy group в каждом пути принадлежит самостоятельному
  recommendation loader и не входит в scope этой задачи;
- full snapshot содержит 48 latest, 12 featured и 8 video IDs, но только 60
  уникальных IDs; все восемь video IDs одновременно входят в featured.

Следовательно, текущий full path создаёт отдельные Eloquent instances и
повторно читает одни и те же taxonomy pivots для пересекающихся карточек.
Целевой структурный выигрыш составляет шесть SQL statements для web и
двенадцать для full/API path: один/два root queries и пять/десять повторных
taxonomy queries.

## Рассмотренные варианты

### 1. Увеличить TTL полного ответа или snapshot

Отклонено. Homepage cache HIT уже быстрый, а cache MISS, authenticated path,
cache outage и прямой builder/API вызов сохранят доказанную повторную
гидратацию. Изменение freshness contract не требуется.

### 2. Объединить только `latest` и `video` в web-проекции

Отклонено. Вариант снижает риск локально, но оставляет full/API path с тремя
одинаковыми hydration groups и создаёт две расходящиеся реализации одной
операции.

### 3. Один union hydration с последующей ordered projection

Выбрано. Builder один раз загружает уникальное объединение IDs всех
включённых card sections и существующие `cardSummaryLoads()`, затем
восстанавливает каждую коллекцию по её snapshot order.

Чтобы сохранить поведение, каждая секция получает clone модели:

- одинаковый title в featured и video остаётся разными PHP objects;
- `content_added_at` устанавливается только на latest instance;
- card counts и authenticated personal state присваиваются каждой секции
  так же, как до оптимизации;
- `latestSeason` повторно загружается одним bounded relation query только
  для latest clones; featured/video остаются без этой relation, как в
  зафиксированном `main`.

## Архитектура и поток данных

1. `CatalogHomeSnapshotCache` возвращает прежние ordered scalar ID lists.
2. `buildData()` формирует три группы: latest, optional featured и video.
3. Новый private helper объединяет IDs, нормализует их в positive integers,
   удаляет дубли и выполняет один существующий visibility-aware
   `titleSummaryQuery()` с одним canonical набором `cardSummaryLoads()`.
4. Hydrated models индексируются по primary key.
5. Для каждой исходной ID list helper строит
   `Eloquent\Collection<CatalogTitle>` в прежнем порядке, пропускает
   недоступные/отсутствующие IDs и клонирует найденную модель.
6. Latest collection одним bounded relation query получает
   `latestSeason`; featured/video clones не получают relation.
7. Существующие content-addition, count, personal-state, recommendation,
   SEO и serialization этапы получают те же section collections.

Новый service, cache family, DTO, route, migration, dependency, job или
client request не добавляется.

## Compatibility contracts

- `/`, `/ru`, `/en`, full-page Livewire и Blade markup не меняются;
- `/api/v1/home` сохраняет прежние keys, order и section limits 48/12/8/12;
- `CatalogHomeSnapshotCache` schema/key/TTL/stale/invalidation не меняются;
- homepage full-response `response_contract=2` не требует bump, потому что
  data/HTML shape не меняется;
- существующие `visibleTo()`, publication, availability, audience, region,
  Premium, legal и authorization predicates остаются внутри того же
  `titleSummaryQuery()`;
- card taxonomy projections и localized tag labels остаются каноническими;
- recommendation candidates, exclusions, ranking и shown-state не
  меняются; recommendation loader остаётся отдельной границей;
- latest release groups, card counts, personal state, SEO и route model
  binding сохраняются;
- SQLite и in-memory SQLite tests поддерживаются без нового
  database-specific SQL или индекса.

## Риски и ограничения

- Shallow clone разделяет сам Eloquent model и его attributes/relations
  array, но загруженные relation objects могут оставаться общими. Текущий
  downstream code читает taxonomy relations и не мутирует связанные
  модели; отдельные mutable card attributes устанавливаются на clone.
- Union query содержит больше IDs, чем каждая прежняя секция отдельно, но
  остаётся bounded максимумом snapshot и устраняет больше round trips и
  повторных pivot scans, чем добавляет данных.
- `latestSeason` нельзя eager-load в общий union: это расширило бы relation
  state featured/video и читало бы сезоны для большего набора IDs. Отдельный
  collection load сохраняет прежнюю latest-only границу.
- Recommendation hydration не объединяется: у неё отдельный ranking,
  presentation и ownership contract.

## Failure и rollback

Изменение read-only. Cache outage продолжает использовать authoritative
builder с теми же visibility scopes и меньшим количеством запросов.
Отсутствующий ID по-прежнему молча исключается из соответствующей секции.

Rollback — revert PHP/test/docs commit и штатный application/PHP-FPM refresh.
Database restore, migration rollback, cache flush, generation bump, queue
cleanup и asset rollback не нужны.

## Проверка

- TDD RED требует один root title hydration, один запрос каждой canonical
  card relation и один latest-only `latestSeason` query для web и full;
- overlap regression подтверждает разные model instances, отсутствие
  `content_added_at` вне latest section, loaded `latestSeason` у latest и
  unloaded relation у video;
- full/API semantic tests сохраняют counts, order и JSON shape;
- focused homepage/recommendation/cache/eager-load tests проверяют соседние
  contracts;
- before/after builder profile сравнивает query count, SQL time и wall time
  без нестабильных timing assertions в PHPUnit;
- HTTPS/Chromium проверяют status, cache state, desktop/mobile DOM,
  overflow и browser errors;
- Pint, PHPStan, Rector, docs check, full test attempt и repository legacy
  scan завершают verification.
