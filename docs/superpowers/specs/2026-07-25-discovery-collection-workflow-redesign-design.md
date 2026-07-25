# Task 56 — redesign каталога и классификации подборок

Дата: 25.07.2026.

Статус: `approved`.

## Контекст и подтверждённая проблема

Публичный каталог подборок уже встроен в canonical
`/discover/popular`, использует двухуровневые категории и полностью
text-only карточки. Отдельный route каталога подборок намеренно не
существует. Административный центр классификации уже рассчитывает
детерминированные объяснимые предложения только для текущей страницы и
никогда не сохраняет их без отдельного подтверждения.

Read-only проверка 25.07.2026 показала:

- всего активных подборок: `1 403`;
- распределено по категориям: `0`;
- без категории: `1 403`;
- публичных одобренных без категории: `501`;
- каталог подборок на desktop начинается примерно после `7 700 px`
  основного содержимого `/discover/popular`;
- глобальная ссылка «Подборки» ведёт на начало страницы без
  `#collections`;
- публичная навигация выводит пять корневых категорий с нулевыми
  счётчиками;
- на диагностической странице из 50 строк scorer вернул `3` high,
  `5` low и `42` none, поэтому workflow обязан эффективно обслуживать
  ручные решения, а не только уверенные предложения;
- bounded classification query сохраняет постоянное число SQL-запросов:
  четыре запроса active category tree, до четырнадцати запросов очереди
  после scoped schema probes; suggestion scoring после eager loading не
  выполняет SQL.

Изображения подборок уже удалены из public/admin/member presentation.
Task 56 не возвращает cover, poster, fallback image, upload или storage.

## Рассмотренные варианты

### 1. Только ссылки-якоря и перестановка блоков

Самый маленький diff быстро делает каталог достижимым, но не решает
нулевое заполнение категорий и медленную ручную работу с 1 403 строками.

### 2. Public collection-first navigation и classification sprint

Публичный каталог становится первым содержательным разделом popular page,
глобальные ссылки ведут к нему напрямую, а административная очередь
получает page-bounded массовую подготовку, one-click принятие предложения
и request-scoped повторное использование данных. Финальная запись остаётся
двухшаговой и авторизованной.

### 3. Background classifier и сохранённые догадки

Этот вариант позволил бы глобальную confidence-сортировку, но потребовал бы
новых persisted inference rows, background owner, invalidation и
failure-recovery. Он противоречит каноническому запрету автоматического
назначения и создаёт неприемлемый риск ложной классификации.

Выбран вариант 2. Пользователь явно утвердил выполнение рекомендуемого
варианта. Вариант 3 отклонён.

## Scope

Входит:

- collection-first порядок на `/discover/popular`;
- стабильная section navigation «Подборки / Популярные сериалы»;
- `#collections` в глобальной header/footer навигации;
- скрытие публичных category/subcategory controls с нулевым count;
- сохранение выбранного zero-count URL state для совместимости;
- SEO state recognition для collection category/subcategory query;
- административная очередь, по умолчанию сфокусированная на публичных
  одобренных строках;
- фильтр moderation status;
- выбор всех строк текущей bounded страницы;
- очистка текущего выбора;
- one-click подготовка объяснимого предложения отдельной строки;
- автоматическое включение строки в selection после ручного category
  select;
- page-bounded применение одной категории к выбранным строкам;
- confidence-first presentation только внутри уже выбранной database
  страницы;
- компактный progressive disclosure справочника категорий;
- request-scoped reuse category tree/page/suggestions между Livewire
  action и последующим render;
- focused/query/translation/layout/browser tests;
- canonical docs, README и CHANGELOG.

Не входит:

- новый route `/collections`, `/discover/collections` или `/discover`;
- автоматическая запись category FK;
- изменение категории любой production collection без explicit
  `content.manage` preview/confirm;
- catalog-wide confidence inference;
- изменение score thresholds или добавление mood/`other-*` guessing;
- новая migration, index, dependency, environment variable, queue,
  scheduler, cache store или external AI/API;
- изменение public API shape, collection identity, ownership,
  moderation, visibility, membership или recommendation ranking;
- восстановление изображений подборок.

## Публичная информационная архитектура

`CatalogDiscoveryPage` остаётся единственным full-page Livewire owner.
Route names, localized aliases, canonical URL и девять recommendation modes
не меняются.

Для `popular` порядок становится таким:

1. общий header/H1;
2. навигация девяти discovery modes;
3. компактная section navigation;
4. `CatalogCollectionExplorer`;
5. recommendation filters;
6. section популярных сериалов и pagination.

Section navigation использует обычные ссылки `#collections` и
`#popular-titles`. Оба target имеют `scroll-mt-*`, понятные heading и
keyboard-visible focus. Custom JavaScript, sticky subnavigation и
внутренняя прокрутка не создаются.

Другие восемь discovery modes не монтируют collection explorer и не
получают section navigation.

Глобальные header/footer items с подписью «Подборки» используют тот же
locale-aware discovery URL, но добавляют `#collections`. Active route
semantics остаются прежними.

## Public category navigation

`CatalogCollectionCategoryQuery` продолжает возвращать authoritative active
tree и public counts. Фильтрация dead controls выполняется в
`CatalogCollectionExplorer`, а не в Blade и не в query owner:

- root показывается, если branch count больше нуля;
- выбранный root сохраняется в navigation даже при нулевом count, чтобы
  bookmarked URL не терял состояние;
- child показывается, если count больше нуля;
- выбранный child сохраняется при нулевом count;
- «Без категории» показывается, пока count больше нуля, либо когда этот
  фильтр выбран;
- «Все категории» показывается всегда.

Database semantics, counts и нормализация stable slugs не меняются.
Blade получает только готовые arrays/flags.

## SEO

`collections_category` и `collections_subcategory` становятся такими же
stateful collection query parameters, как `collections_q`,
`collections_sort` и `collectionsPage`.

При любом таком state `CatalogSeoBuilder::discovery()` получает
stateful-variant flag и сохраняет clean canonical/noindex behavior.
Fragment `#collections` не является server state и не создаёт отдельную
canonical страницу.

## Административный classification sprint

Центр остаётся внутри существующего
`CatalogCollectionCategoryManager`. Новый route, controller, endpoint или
permission не создаются.

### Default queue

Component defaults:

- `visibility = public`;
- `moderation_status = approved`;
- `type = all`;
- `perPage = 20`.

Пользователь может вернуть любой visibility/status через native selects.
Query service сохраняет backward-compatible unfiltered defaults, а новые
predicates применяет только по явно переданным enum values.

### Page-bounded selection

Новые действия:

- «Выбрать страницу» выбирает public UUID только текущей страницы,
  максимум 50;
- «Очистить выбор» очищает selection, prepared category map и preview;
- «Выбрать с высокой уверенностью» сохраняет прежнее поведение;
- «Принять рекомендацию» у строки подготавливает только существующее
  объяснимое suggestion этой строки;
- ручное изменение category select автоматически добавляет строку в
  selection; очистка select удаляет её из selection;
- batch category select плюс «Применить к выбранным» заполняет target
  только выбранным UUID текущей страницы.

Все действия только изменяют bounded Livewire state. Они не вызывают
category service mutation, audit или cache invalidation.

### Presentation order

Database page membership и pagination остаются authoritative
`updated_at DESC, id DESC`. После suggestion scoring models текущей страницы
сортируются только для presentation:

1. high;
2. medium;
3. low;
4. none;
5. исходный database order как stable tie-break.

Глобальный confidence filter не создаётся. Page totals и pagination не
притворяются глобально отсортированными.

### Preview и confirmation

Существующий contract сохраняется:

1. selection/category map готовятся без записи;
2. preview повторно разрешает UUID, active categories и current-page
   membership;
3. immutable preview содержит exact `content_version`;
4. final confirm повторно авторизует `content.manage`;
5. malformed/unknown/inactive input отклоняет batch;
6. stale/already-classified rows безопасно пропускаются;
7. допустимые изменения выполняются короткой transaction;
8. `content_version` увеличивается;
9. targeted invalidation и safe audit выполняются после authoritative
   write.

Client-provided confidence, owner, numeric ID, moderation или visibility не
являются trusted input.

## Request-scoped оптимизация Livewire

`CatalogCollectionCategoryManager` получает boot-injected query services и
Livewire `#[Computed]` methods для:

- administration category tree;
- current classification page;
- current-page suggestions;
- classification summary.

Computed data memoized только внутри одного Livewire request. Это позволяет
action `selectHighConfidence`, row suggestion, page selection, batch
preparation и preview использовать тот же page/tree/suggestion result,
который затем читает render.

Между requests data не кешируется. Private collection evidence не попадает
в shared cache, serialized public properties, query string, browser storage
или audit.

Query/data limits сохраняются:

- не более 50 collections на page;
- не более 50 sampled membership rows на collection;
- pagination выполняется до evidence;
- eager relations имеют explicit projections;
- empty page не загружает membership evidence;
- scorer не выполняет SQL;
- Blade не выполняет query.

Новый index не нужен: predicates используют существующие nullable category,
visibility и moderation columns. Финальный `EXPLAIN QUERY PLAN` обязан
подтвердить приемлемый existing index path; discovery, изменяющее это
решение, немедленно обновляет план до DDL.

## Progressive category dictionary

Create form и каждый root справочника получают native `<details>`:

- create form закрыт по умолчанию;
- root summary показывает локализованное имя, active state, aggregate
  assigned count и раскрывающий indicator;
- children и move/edit controls находятся внутри раскрытого root;
- выбранная для редактирования category остаётся в отдельном inline panel;
- никаких modal, custom accordion JavaScript или nested scroll region.

Touch targets остаются не меньше 44 px. На mobile controls переносятся,
длинные названия не создают horizontal overflow.

## Переводы и accessibility

Все новые visible/ARIA/status/validation labels добавляются одновременно в
`lang/ru` и `lang/en` с exact recursive parity.

Сохраняются:

- один H1;
- последовательные H2/H3;
- semantic `nav`, `section`, `article`, `details`, `summary`;
- visible focus;
- `aria-current` только для реального page state;
- status/error live regions;
- native checkbox/select/button semantics;
- reduced-motion-safe отсутствие декоративной анимации;
- светлая тема и локальные icons.

## Ошибки и edge cases

- Если suggestion отсутствует, row action не показывается.
- UUID не текущей страницы отклоняется без state mutation.
- Invalid batch category даёт локализованную validation error.
- Selection больше 50 после browser hydration нормализуется до 50.
- Filter/page change очищает selection и preview.
- Empty filtered queue показывает точное empty state.
- После final confirm current page может стать пустой; paginator
  нормализуется существующим reset к первой странице.
- Если category архивирована между staging и preview, preview отклоняется.
- Если row изменена между preview и confirm, она попадает в `skipped`.
- Query/cache failure не превращается в автоматическую запись.

## Cross-feature compatibility

### Authentication и authorization

Существующие admin middleware, `content.view` и `content.manage` остаются
единственными boundaries. UI visibility не заменяет server authorization.

### Cache

Public explorer продолжает читать существующий collection cache domain.
Staging actions не инвалидируют cache. Final confirmation использует
существующий after-commit invalidator.

### Search, API и SEO

Search suggestion, collection detail/profile, API resources, sitemap и
recommendations читают только подтверждённый category FK. API shape и
deprecated `cover_url = null` не меняются.

### Importer

Import/reconciliation сохраняет ручную category assignment и никогда не
вызывает suggestion/staging actions.

### Privacy

Private descriptions/member titles остаются только в manager-only bounded
render. Они не попадают в audit, public cache, URL или client-owned identity
state.

### Player, Premium, payments, advertisements, calendar, regional/legal

Эти domains не изменяются. Title/card visibility остается существующей.

## TDD и acceptance

До production code добавляются failing tests:

1. global collection navigation содержит locale-aware `#collections`;
2. popular page рендерит collection explorer раньше title results;
3. other discovery modes не получают collection section navigation;
4. category/subcategory query включает noindex state;
5. zero-count category controls скрыты, выбранный zero-count state
   сохраняется;
6. default admin queue ограничена public/approved, filters нормализуются;
7. moderation predicate покрыт query test;
8. page selection/clear не выполняют write;
9. row suggestion staging не выполняет write и отклоняет foreign UUID;
10. manual category selection автоматически синхронизирует checkbox;
11. batch category staging работает только с current-page UUID и active
    category;
12. preview/final confirmation contracts не регрессируют;
13. computed reuse не дублирует classification page query внутри action
    request;
14. presentation confidence order не меняет page membership;
15. dictionary использует native progressive disclosure;
16. RU/EN parity;
17. browser desktop/mobile/tablet подтверждает порядок, anchors,
    wrapping, touch targets, no overflow и отсутствие collection images.

Acceptance:

- header/footer «Подборки» открывает `#collections`;
- collection explorer находится до длинного recommendation list;
- публичный UI не предлагает zero-result category clicks;
- content manager может page-select, stage one category, review и confirm;
- ни одно staging действие не пишет в database;
- existing optimistic confirmation остаётся authoritative;
- query count не растёт от общего числа collections/memberships;
- no collection image/upload/storage path возвращён;
- routes/API/schema/dependencies остаются совместимыми;
- focused tests, Pint, build, docs checks и Playwright проходят либо
  внешний blocker честно фиксируется.

## Rollout и rollback

Schema/data/dependency/environment/queue changes отсутствуют.

Rollout:

1. развернуть PHP/Blade/lang одним code release;
2. собрать Vite assets из lock file;
3. обновить совместимые route/view caches documented deployment flow;
4. проверить guest RU/EN popular page и anchors;
5. проверить manager default queue и no-write staging;
6. проверить explicit preview/confirm на test fixture;
7. проверить public counts только после confirm.

Rollback выполняется revert commit:

- возвращает прежний порядок popular page и ссылки без fragment;
- возвращает прежние row-by-row controls;
- не требует schema/data rollback;
- уже подтверждённые category FK остаются корректными domain data;
- ошибочное отдельное назначение исправляется существующим
  authoritative category workflow, broad reset запрещён.

## Ограничение production data

Task 56 улучшает систему и workflow, но не присваивает категории 501
production collection автоматически. Каждое реальное назначение требует
явного review/confirm пользователя с `content.manage`. Это обязательный
data-safety contract, а не незавершённость реализации.
