# Дизайн ускорения `recently_added` на главной странице

Дата: 26.07.2026.

Статус: approved. Пользователь многократно поручил выполнить рекомендуемый
вариант без дополнительного согласования.

## Контекст и измеренный root cause

После Task 62 гидратация новых серий перестала быть главным узким местом.
Свежий read-only профиль `CatalogHomePageBuilder::data()` на production-like
SQLite размером `30 186 242 048` bytes показал:

- тёплая доменная сборка: median `124,20 ms`, `45` SQL-запросов и
  `43,79–47,49 ms` суммарного SQL;
- live anonymous HTML-cache HIT: `0,099–0,109 s`;
- первый проверенный live-ответ: `0,431 s`;
- холодный публичный `recently_added` candidate query:
  `302,21–328,06 ms` для лимитов `24`, `48` и `180`.

`EXPLAIN QUERY PLAN` выбирает
`catalog_titles_publication_lookup_idx`, затем выполняет canonical
correlated media/season/episode availability probes и создаёт
`USE TEMP B-TREE FOR ORDER BY`. Уменьшение candidate limit не устраняет
сортировку видимого каталога и поэтому почти не влияет на время.

Тот же exact SQL с существующим
`catalog_titles_created_at_idx` вернул те же ID в том же порядке во всех
шести сравнениях за `0,417–3,052 ms`. План больше не содержит temporary
B-tree: SQLite идёт по `created_at` и завершает scan после bounded `LIMIT`.

## Рассмотренные варианты

### 1. Уменьшить общий candidate window

Отклонено. Лимиты `24`, `48` и `180` дали сопоставимые `304–432 ms`.
Кроме отсутствия эффекта, глобальное уменьшение ухудшило бы diversity и
pagination discovery-страниц.

### 2. Добавить composite index

Отклонено для этой задачи. Новый индекс дублировал бы уже существующий
order index, увеличил бы стоимость массовых импортных записей, потребовал
бы production DDL и rollback миграции. Измерения не требуют нового
storage contract.

### 3. Зафиксировать существующий order index только для SQLite

Выбрано. `CatalogPublicDiscoveryQuery::recentlyAdded()` после построения
полного canonical visibility query заменит `FROM catalog_titles` на
SQLite-only `FROM catalog_titles INDEXED BY
catalog_titles_created_at_idx`. Имя таблицы и индекса формирует текущая
database grammar, пользовательский ввод в raw fragment не попадает.

Для остальных database drivers query остаётся без изменений. Stable
`created_at DESC, id DESC`, availability predicates, exclusions,
candidate limit, cache identity и DTO/resource contracts сохраняются.

SQLite документирует `INDEXED BY` как непереносимое обязательное
требование к planner и рекомендует применять его в конце разработки для
фиксации доказанного time-sensitive plan. Здесь это условие выполнено:
schema/index уже постоянны, alternative limit проверен, exact result
identity сопоставлена, а проект уже использует такую же SQLite-only
границу для homepage content events.

## Поток данных

1. Главная создаёт guest `CatalogRecommendationContext` типа
   `recently_added`.
2. `CatalogRecommendationCache` продолжает читать/писать bounded scalar
   candidate pool в существующем domain/version namespace.
3. Только при cache MISS `CatalogPublicDiscoveryQuery` строит canonical
   `eligibleQuery()`.
4. Только на SQLite query source фиксируется на существующем
   `catalog_titles_created_at_idx`.
5. Availability, filters и exclusions применяются server-side как прежде.
6. Loader гидратирует только итоговое display window; HTML-cache contract
   не меняется.

## Совместимость и безопасность

- Routes, Livewire ownership, HTML, SEO, translations и API shape не
  меняются.
- Guest/user authorization и audience/region/premium availability не
  ослабляются: `eligibleQuery()` не заменяется и не сокращается.
- `created_at` остаётся canonical identity для `recently_added`;
  `indexed_at` не возвращается.
- Cache key/version/TTL/invalidation не меняются, flush не нужен.
- Migration, DML, dependency, env и queue changes отсутствуют.
- Если обязательный индекс исчезнет из SQLite schema, query намеренно
  завершится ошибкой вместо тихого возврата к многосотмиллисекундному
  плану; regression test проверяет наличие индекса и compiled SQL.
- Rollback: revert application commit. Database/cache cleanup не требуется.

## Проверка

- RED/GREEN feature test фиксирует SQLite-only `INDEXED BY`, stable
  `created_at DESC, id DESC`, result order и exclusions.
- Existing recommendation/homepage tests подтверждают публичное поведение,
  availability и cache contracts.
- Task-scoped Pint, Larastan и Rector dry-run проверяют PHP.
- Повторный exact read-only profile сравнивает ID, query plan и пять
  builder samples.
- Safe anonymous HTTPS series подтверждает status, page-cache header,
  TTFB/total и payload без cache flush.
