# Центр классификации подборок

Дата: 25.07.2026

Статус: рекомендованный подход подтверждён пользователем; письменная
спецификация ожидает финального просмотра перед implementation plan.

## Контекст

Предыдущий этап создал двухуровневую таксономию из 5 корневых категорий и
31 подкатегории, добавил ручное пакетное назначение и обновил
`/discover/popular`. В текущей базе находятся:

- 1 403 подборки без категории;
- 501 публичная одобренная подборка без категории;
- 3 294 655 membership-строк в `catalog_collection_items`.

Полный пересчёт состава всех подборок при открытии административной страницы
неприемлем. Полностью ручной выбор остаётся точным, но плохо масштабируется.
Полностью автоматическая запись по словам или внешнему AI создаёт
необъяснимые и ошибочные назначения.

## Цель

Добавить в существующий `/admin/catalog?section=collections` безопасный центр
классификации, который:

1. показывает очередь подборок без категории и прогресс заполнения;
2. вычисляет детерминированное предложение только для текущей страницы;
3. показывает предложенную категорию, уверенность и понятные причины;
4. позволяет изменить предложение вручную;
5. никогда не сохраняет категорию без отдельного подтверждения администратора;
6. применяет подтверждённый пакет одной короткой транзакцией;
7. немедленно обновляет существующие discovery/cache/API consumers через
   каноническую collection mutation boundary.

## Рассмотренные подходы

### 1. Полностью ручная классификация

Сохраняет максимальный контроль и не требует suggestion engine. При 1 403
строках администратор вынужден открывать и оценивать каждую подборку без
подсказки. Это слишком медленно и не использует уже существующие название,
описание и metadata тайтлов.

### 2. Автоматическое назначение по правилам или AI

Позволяет быстро заполнить всё дерево, но ошибочная категория сразу становится
публичным фактом. Внешний AI добавляет provider, стоимость, privacy и
failure-mode, которых у проекта сейчас нет. Простые слова не различают
отрицания, смешанные темы и редакционные формулировки.

### 3. Детерминированные предложения с обязательным подтверждением

Система анализирует ограниченный набор уже сохранённых данных, объясняет
результат и готовит выбор, но запись выполняет только администратор.
Неуверенные и конфликтующие случаи остаются ручными. Отдельный AI,
dependency, queue, scheduler и таблица догадок не требуются.

Выбран подход 3. Пользователь повторно делегировал реализацию рекомендуемого
варианта. Автоматическое сохранение, включая предложения с высокой
уверенностью, запрещено.

## Scope

Входит:

- административная очередь классификации;
- grouped progress counters;
- поиск и фильтры очереди;
- bounded deterministic suggestion engine;
- RU/EN объяснения и confidence states;
- выбор предложений текущей страницы;
- ручная корректировка каждой выбранной категории;
- двухшаговый preview/confirm flow;
- bounded transactional assignment с optimistic concurrency;
- targeted collection cache invalidation и safe admin audit;
- feature, query-budget, authorization и browser tests;
- обновление канонической документации, `README.md` и `CHANGELOG.md`.

Не входит:

- автоматическое сохранение предложений;
- классификация через внешний AI/API;
- новая публичная category landing page;
- изменение двухуровневой модели или добавление many-to-many tags;
- изменение пользовательского редактора категорий;
- изменение изображений, которые уже удалены предыдущим этапом;
- фоновый catalog-wide пересчёт;
- новая migration, queue, scheduler, dependency или environment variable.

## Архитектура

### `CatalogCollectionClassificationQuery`

Новый read-only query service владеет административной очередью и статистикой.
Он не заменяет `CatalogCollectionQuery` для публичных страниц.

`summary()` выполняет один grouped aggregate и возвращает:

- общее количество активных подборок;
- назначено;
- без категории;
- публичных одобренных без категории;
- процент заполнения.

`paginateUncategorized()`:

- принимает нормализованные `search`, `visibility`, `type` и `perPage`;
- ограничивает `perPage` диапазоном 10–50, default 20;
- выбирает только явную проекцию collection/translation/source данных;
- использует отдельный paginator
  `collectionCategoryClassificationPage`;
- сохраняет стабильный порядок `updated_at DESC, id DESC`;
- после пагинации загружает evidence только для ID текущей страницы.

Для каждой подборки загружаются не более 50 первых membership-строк в
стабильном порядке `position, id`. Laravel 13 relation eager limit
компилирует per-parent group limit; поддержка SQLite подтверждается
установленным framework source. Для sample titles загружаются только:

- `id`, `title`, `original_title`, `type`, `year`;
- projected `genres`, `countries`, `networks` и `studios`;
- source `provider`, `remote_name` только для source-managed collection.

При пустой странице evidence-запросы не выполняются. Верхняя граница —
50 collections × 50 sample items; query count не зависит от общего числа
3,29 млн membership-строк.

### `CatalogCollectionCategorySuggestionRules`

Небольшой immutable registry связывает только stable slug стандартных
подкатегорий с allowlisted RU/EN evidence:

- темы: detective/crime, science-fiction/fantasy, documentary,
  animation/anime, history, family/relationships, comedy, music;
- страны: Russia, United States, Europe, South Korea, China, Turkey;
- платформы: Netflix, HBO/Max, Apple TV+, Amazon, Disney+;
- формат: mini-series, short series, adaptations, new releases.

Registry использует stable category slugs, taxonomy slugs и нормализованные
слова. Он не хранит numeric database ID и не назначает custom admin
categories. Категории «настроение и повод», `other-*` и неоднозначные custom
узлы всегда остаются ручными, пока для них нет доказуемого правила.

### `CatalogCollectionCategorySuggestionService`

Service является чистым вычислителем: получает подготовленную подборку,
активное дерево и bounded evidence, возвращает DTO, но ничего не пишет.

Сигналы:

- точная фраза или имя платформы/страны в названии: 70 баллов;
- тематическая фраза в названии: 60 баллов;
- allowlisted evidence в описании: 30 баллов;
- одинаковый source remote name signal: до 20 баллов;
- dominant taxonomy/platform/country в sample:
  - не менее 70% sample titles: 55 баллов;
  - 50–69%: 35 баллов;
  - 35–49%: 20 баллов;
- однородный title `type`, подтверждающий animation/documentary:
  до 35 баллов.

Баллы одного типа evidence не дублируются бесконечно и ограничиваются 100.
Победитель показывается только когда:

- итог не меньше 60;
- отрыв от второго кандидата не меньше 15;
- найденная category активна и её root активен.

Confidence:

- `high`: 85–100;
- `medium`: 70–84;
- `low`: 60–69;
- `none`: ниже 60, конфликт кандидатов или отсутствует активный узел.

DTO содержит только:

- collection public UUID и `content_version`;
- category public UUID, stable slug и локализованный путь;
- integer score и stable confidence code;
- максимум три локализованных reason code;
- sample size и total membership count.

Raw descriptions, private text, numeric IDs и полный список member titles в
DTO/Livewire state не помещаются.

### Write boundary

`CatalogCollectionCategoryService` получает новый метод
`confirmAssignments(User $actor, array $assignments): Result`.

Каждая assignment содержит:

- collection public UUID;
- ожидаемый `content_version`;
- выбранный category public UUID.

Ограничения:

- от 1 до 100 уникальных collection UUID;
- только `content.manage`;
- только активная category под активным root;
- подборка должна существовать и оставаться без категории;
- numeric ID, owner, visibility, moderation и confidence от browser не
  принимаются;
- malformed/duplicate/unknown collection или category отклоняет весь request;
- stale version или уже классифицированная строка безопасно пропускается и
  попадает в `skipped` result;
- все допустимые назначения фиксируются в одной короткой transaction с
  `lockForUpdate()` и deadlock retry;
- `content_version` каждой изменённой подборки увеличивается ровно на один;
- moderation, visibility, featured, owner, membership и translations не
  меняются.

После commit вызывается существующий
`CatalogCollectionCacheInvalidator::changedMany()`. Audit хранит actor,
resource type, количество изменённых/пропущенных записей и fingerprint
stable UUID/category UUID, но не названия, descriptions, member titles или
search text.

Предложения не являются authorization evidence. Final service повторно
разрешает каждую collection/category из authoritative database state.

## Административный UX

Центр классификации остаётся внутри существующего
`CatalogCollectionCategoryManager`; новый route и full-page компонент не
создаются.

### Верхняя сводка

Четыре компактных показателя:

- классифицировано;
- без категории;
- публичных без категории;
- общий процент готовности.

Counts показывают реальное database state. Прогресс не симулируется и не
сохраняется отдельно.

### Фильтры

- поиск по названию, translation, slug и владельцу;
- visibility;
- collection type;
- 20/50 строк на страницу.

Фильтры имеют отдельные URL keys с prefix
`collection_classification_*`, сбрасывают только named paginator и не
конфликтуют с другими paginator islands страницы.

Глобальный confidence-фильтр намеренно отсутствует: confidence вычисляется
только после database pagination для bounded evidence текущей страницы.
Фильтрация всех 1 403 строк по вычисляемому confidence потребовала бы
catalog-wide inference до pagination и нарушила бы performance contract.
Confidence остаётся видимым в каждой строке и используется действием выбора
уверенных предложений текущей страницы.

### Строка классификации

Каждая строка показывает:

- checkbox;
- text-only название;
- visibility/type/status и количество элементов;
- предложенный category path или «Нужно выбрать вручную»;
- confidence pill и score;
- до трёх коротких причин;
- native category `<select>` для ручной корректировки.

Изображения и fallback poster отсутствуют. Touch targets не меньше 44 px,
layout не создаёт внутренний horizontal scroll.

Действие «Выбрать уверенные на этой странице» только отмечает `high`
предложения и заполняет category select. Оно не пишет в базу.

### Двухшаговое подтверждение

1. Администратор выбирает строки и при необходимости меняет category.
2. «Проверить назначения» показывает preview: количество, category paths и
   stale-warning.
3. Отдельная кнопка «Назначить категории» вызывает final write boundary.
4. Результат сообщает фактически изменённые и пропущенные строки, очищает
   selection и обновляет progress/queue.

Cancel возвращает к очереди без mutation. Empty state различает полностью
заполненный каталог, отсутствие search results и отсутствие доказуемых
предложений.

## Авторизация и privacy

- Initial page и Livewire hydration сохраняют существующие
  `auth`, `auth.session`, `verified`, `account.private`, `account.active`,
  `admin.access`, `content.view` boundaries.
- Queue и suggestion evidence доступны только при `content.manage`, как
  существующее bulk assignment.
- Каждая mutation повторно выполняет `content.manage` через
  `Gate::forUser($actor)`.
- Private collection description и member titles не попадают в audit,
  query string, logs, browser events или public cache.
- Client не выбирает owner, moderation, confidence или algorithm score.
- Новый endpoint/API/token ability не создаётся.

## Производительность

- Нет catalog-wide membership scan на request.
- Pagination выполняется до evidence loading.
- Per-parent sample hard-capped 50.
- Page hard-capped 50, default 20.
- Category tree загружается один раз с projected translations.
- Suggestions вычисляются в памяти только для текущей страницы.
- При пустой странице nested evidence queries отсутствуют.
- Grouped summary/counts не загружают collection models.
- Query-budget test фиксирует постоянный верхний предел количества queries.
- SQLite `EXPLAIN QUERY PLAN` должен использовать
  `catalog_collections_category_public_order_idx` или более подходящий
  существующий index для `category_id IS NULL`.
- Новая migration/index не добавляется без измеренного подтверждения, что
  существующего category-leading index недостаточно.

## Cache и cross-feature impact

- Suggestion output не cacheable и не сохраняется.
- Category assignments используют существующую targeted invalidation.
- Public `/discover/popular`, collection detail, search suggestions, API,
  sitemap и recommendations читают только подтверждённый FK.
- Import/reconciliation не вызывает suggestion service и не меняет ручное
  назначение.
- Account export/delete, profile, player, calendar, Premium, region и legal
  boundaries не меняются.
- API shape, routes, translations identity и deprecated `cover_url=null`
  сохраняются.

## Ошибки и конкурентность

- Недоступная category schema показывает truthful unavailable state.
- Ошибка suggestion evidence не выполняет mutation; строка становится
  `none` с безопасным сообщением без exception details.
- Если category архивирована между preview и confirm, весь request
  отклоняется до write.
- Если collection уже назначена или её `content_version` изменился, она
  пропускается; остальные допустимые строки применяются атомарно.
- Validation error сохраняет выбранные значения для исправления.
- Database failure откатывает весь write batch и не вызывает invalidation.
- После успешной transaction invalidation failure не откатывает authoritative
  assignment; existing invalidator failure contract остаётся источником
  истины для recovery.

## Rollout и rollback

Schema, dependency, environment, queue, scheduler и storage changes
отсутствуют.

Rollout:

1. развернуть PHP/Blade/lang вместе;
2. собрать Vite assets из lock file;
3. обновить compatible route/view cache обычным documented workflow;
4. проверить admin authorization и query budget;
5. browser smoke на desktop/mobile/tablet;
6. подтвердить, что public category counts меняются только после explicit
   admin confirmation.

Rollback code возвращает прежний ручной bulk UI. Уже подтверждённые category
FK остаются корректными domain data и не откатываются массово. Если конкретное
назначение ошибочно, администратор исправляет его через тот же authoritative
category assignment boundary; broad reset запрещён.

## TDD и acceptance

До production code добавляются failing tests:

1. rule registry и confidence/margin scoring;
2. no-suggestion для конфликтующих/неподдерживаемых данных;
3. bounded 50-item sample и empty-page short circuit;
4. constant query budget относительно общего числа collections/items;
5. authorization и отсутствие queue для non-manager;
6. high-confidence selection не выполняет write;
7. preview не выполняет write;
8. confirm validates UUID/category/activity/content version;
9. successful batch changes only category FK/content version;
10. stale rows skipped, invalid category rejects batch;
11. cache invalidation after commit и safe audit;
12. RU/EN key parity;
13. responsive text-only browser flow без horizontal overflow.

Acceptance:

- ни одно предложение не сохраняется автоматически;
- администратор видит причины и может изменить каждую category;
- максимум 100 строк на write, максимум 50×50 evidence rows на render;
- request query count не растёт с 1 403/3,29 млн total rows;
- confirmed assignment появляется в `/discover/popular`;
- public routes/API/SEO/importer/image-removal contracts не регрессируют;
- focused tests, broad relevant tests, Pint, build, docs checks и Playwright
  проходят либо внешний blocker фиксируется как `unresolved`.

## Совместимость и риски

- Главный product risk — ложная уверенность. Его ограничивают score threshold,
  margin, reasons, no auto-save и manual override.
- Главный performance risk — крупные membership sets. Его ограничивают
  pagination-before-evidence и relation group limit.
- Главный concurrency risk — stale preview. Его ограничивают
  `content_version`, locks и повторное разрешение category.
- Главный delivery risk — общий смешанный Git index. Implementation не
  reset/stash/unstage чужие изменения; exact Task 52 scope фиксируется
  отдельно, а commit/push выполняется только без поглощения параллельной
  работы.
