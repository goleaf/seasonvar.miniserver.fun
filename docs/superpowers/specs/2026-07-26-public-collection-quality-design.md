# Качество публичных подборок — дизайн

Дата: 26.07.2026.

Статус: `approved_for_implementation`.

## Подтверждённая проблема

Read-only аудит SQLite подтвердил не редакционную вариативность, а два
детерминированных источника шума:

- 447 public/approved user collections принадлежат точным demo accounts,
  имеют deterministic `seasonvar-demo-v1` UUID/slug footprint, только
  19 повторяющихся названий и от 1 527 до 4 201 membership;
- 54 ownerless editorial collections автоматически опубликованы прежним
  HDRezka reconciliation path, все не имеют категории, 39 пусты, а остальные
  содержат не более 30 membership;
- все 501 public/approved rows находятся в виртуальной группе
  «Без категории»;
- 99 recommendation signal rows доверяют 15 source collections без
  классификации и ручной публикации.

Это влияет на directory, collection search, title relations, sitemap, API,
SEO, public profiles и recommendation inputs. Широкое удаление по имени,
описанию, размеру или `demo-%` запрещено: mutable text/slug не является
достаточным ownership evidence.

## Цель

Публичная подборка должна быть ограниченным, классифицированным и проверенным
результатом, а не вторым каталогом. Для этого:

1. public listing требует approved/public/published state, active category,
   не пропавший source и от 1 до 500 membership;
2. public moderation повторно проверяет тот же server-owned quality boundary;
3. demo corpus больше не публикует подборки, а source sync создаёт новые
   editorial rows private/archived/unpublished;
4. source recommendation signals разрешены только для фактически eligible
   public collections;
5. exact demo/source legacy footprint переводится в reversible private
   quarantine отдельной dry-run-first командой;
6. memberships, source provenance, comments, reports, users и stable
   collection identity сохраняются.

## Выбранная архитектура

### Canonical public scope

`CatalogCollection::scopeEligibleForPublicListing()` владеет quality
предикатами, не зависящими от moderation:

- назначена активная категория;
- для дочерней категории активен parent;
- source не помечен missing;
- существует хотя бы один membership;
- общий membership count не превышает
  `catalog-collections.maximum_public_items_per_collection` (`500`).

`scopePubliclyListed()` добавляет public/approved/published state. Все
существующие directory/search/profile/title/related/sitemap/suggestion/API
lists уже используют этот scope и получают одно согласованное поведение.

Correlated `EXISTS`/count используют существующий unique covering index
`catalog_collection_items(collection_id,title_id)`. Новый индекс допускается
только если SQLite `EXPLAIN QUERY PLAN` докажет необходимость.

### Write boundaries

- create/update не доверяют frontend visibility;
- новая editorial public collection остаётся pending/unpublished до
  наполнения и moderation;
- `CatalogCollectionModerationService` не переводит public row в approved,
  пока authoritative locked collection не проходит eligibility;
- item service использует отдельный public cap для public/unlisted rows,
  сохраняя существующий private storage ceiling для owner recovery и load
  fixtures;
- publication readiness дополнительно сообщает missing/inactive category и
  oversized membership и закрывает feature/read paths.

### Import и recommendations

`HdRezkaCollectionReconciler` всегда создаёт новую source collection как
private/archived/unpublished. Следующие sync обновляют только source-owned
name/membership/provenance и не обходят локальную category/moderation
границу. `HdRezkaCollectionSignalSynchronizer` переиспользует canonical
public scope; complete run удаляет старые signals, если collection больше не
eligible.

### Provenance repair

`catalog-collections:repair-public-quality` по умолчанию выполняет dry-run.
Запись требует `--force`; в production дополнительно обязательны
`--backup-confirmed` и `--writers-paused`. Service останавливается при active
Seasonvar import, HDRezka collection sync или незавершённой recommendation
build.

Demo ownership подтверждается одновременно:

- exact allowlist `user1@example.com`–`userN@example.com`;
- deterministic UUIDv5 из `DemoStableValue`;
- exact versioned expected collection ordinal/count.

Source ownership подтверждается FK `catalog_collection_sources`, provider
`hdrezka`, ownerless editorial type, отсутствующей категорией и старым
public/approved/published state. Repair:

- меняет demo public/unlisted rows на private и снимает publication/feature;
- меняет exact source candidates на private/archived и снимает
  publication/feature;
- удаляет только source-key recommendation signals этих quarantined rows,
  инвалидирует materialized recommendations затронутых тайтлов и отмечает
  их dirty;
- не удаляет collection, membership, source item, translation, report,
  comment или owner data.

Операция идемпотентна. Partial failure восстанавливается повторным запуском
после устранения причины; произвольный orphan cleanup не выполняется.

## Frontend и SEO

Отдельный framework, route или collection directory не создаётся.
`/discover/popular#collections` сохраняет search/sort/category/pagination
state. После фильтрации показывается существующий честный empty state.
Direct legacy page, не прошедшая quality gate, не индексируется; public API
show не обходит canonical listing scope. Интерфейсные ошибки и readiness
reasons имеют парные `ru`/`en` translations.

## Data, security и operations

- Migration, dependency и новый cache domain не нужны.
- SQL использует Eloquent/query bindings; пользовательский ввод в raw SQL не
  вставляется.
- Repair принимает только flags, а не IDs, email, URL или SQL.
- Backup/paused-writer flags являются operator assertions, не симуляцией
  backup orchestration.
- Текущую production SQLite нельзя изменять без проверенного backup,
  остановленных writers и отсутствия active importer/build.
- Rollback кода — revert. Безопасный data rollback ошибочного exact match —
  восстановление verified backup при остановленных writers; штатный
  roll-forward — ручная классификация и moderation сохранённых private rows.
  Hard delete отсутствует.

## Acceptance

- 501 текущая uncategorized public row не попадает в directory/search/API
  list/sitemap/profile/title collection links;
- categorized bounded collection остаётся публичной;
- empty, inactive-category и oversized rows fail closed;
- search, sort, category filters и pagination продолжают работать вместе;
- new demo/source rows не становятся public;
- moderation не одобряет ineligible public row;
- source signals не создаются до человеческой классификации/publication;
- dry-run ничего не пишет, repair меняет только exact provenance candidates
  и второй запуск является no-op;
- focused/broad tests, Pint, static analysis, Vite, route/docs checks,
  SQLite `EXPLAIN` и browser QA проходят либо независимый blocker
  документируется точно.
