# Таксономия подборок и обновление discovery без обложек

Дата: 25.07.2026

Статус: согласовано пользователем через делегирование рекомендуемого решения.

## Контекст и цель

В каталоге хранится более 1400 подборок, из которых 501 сейчас публична и
одобрена. Единственный публичный список подборок встроен в
`/discover/popular`, не имеет категорий и показывает карточки с крупными
изображениями. Для поиска нужной подборки посетителю приходится просматривать
плоский список. Текущий `CatalogCollectionQuery::publicDirectory()` также
строит тяжёлый summary со связанными агрегатами до пагинационного `LIMIT`, что
плохо масштабируется при миллионах membership-строк.

Требуется:

- полностью убрать изображения именно у подборок во всех интерфейсах и
  контрактах;
- удалить существующие файлы обложек без сохранения резервной копии;
- ввести управляемые категории и подкатегории для большого каталога;
- сохранить право всех владельцев выбирать категорию для своей подборки;
- дать администраторам управление словарём категорий;
- полностью обновить встроенный раздел подборок и улучшить общий discovery;
- ограничить стоимость запросов размером текущей страницы, а не размером
  всего каталога.

Постеры сериалов, передач, аниме и документальных тайтлов внутри подборки не
являются обложками подборки и остаются без изменений.

## Рассмотренные варианты

### 1. Управляемая двухуровневая таксономия и одна основная категория

Категории хранятся в нормализованных таблицах. Подборка ссылается на один
активный узел: корневую категорию или подкатегорию. Если выбран дочерний узел,
его родитель однозначно определяет категорию верхнего уровня. Все владельцы
могут выбирать узел, но создавать, переименовывать, сортировать и архивировать
узлы может только администратор.

Преимущества: непротиворечивые данные, простой UX, быстрые индексы, понятные
URL-фильтры и предсказуемая модерация. Ограничение: одна подборка не может
одновременно принадлежать нескольким тематическим веткам.

### 2. Фиксированный словарь в config и вычисление категории по названию

Преимущество — нет новых административных записей. Недостатки — категории
нельзя безопасно менять без deployment, автоматическая классификация по
названию даёт ложные совпадения, а переименования приводят к дрейфу.

### 3. Many-to-many теги без ограничения глубины

Преимущество — максимальная гибкость. Недостатки — сложный редактор,
дублирующиеся пути, более дорогие joins/counts, неоднозначная навигация и
неограниченный пользовательский словарь. Для текущей задачи это избыточно.

Выбран вариант 1. Он соответствует пользовательскому поручению сделать всё по
рекомендованной схеме и оставляет возможность добавить вторичные тематические
метки отдельным будущим решением.

## Публичные маршруты и совместимость

- Сохраняется канонический публичный маршрут `/discover/{type}` и
  локализованный эквивалент.
- `/discover/popular` остаётся единственным discovery-разделом со списком
  подборок.
- Новый `/discover` landing route не добавляется: существующий 404-контракт не
  меняется этой задачей.
- Сохраняются detail-маршруты `/collections/{slug}`, локализованный detail,
  профиль владельца, личный dashboard и существующие route names.
- Сохраняются query keys `collections_q`, `collections_sort` и paginator
  `collectionsPage`.
- Добавляются отдельные URL-state keys `collections_category` и
  `collections_subcategory` со stable slug, без numeric ID.
- Маршрут `collections.cover` удаляется после переходного аудита всех
  потребителей. Старые URL обложек после deployment получают обычный 404 и не
  перенаправляются на изображение-заглушку.
- API сохраняет ключ `cover_url` со значением `null` как deprecated
  совместимый shape в текущей версии API. Новые category-поля добавляются
  additive. Следующая версия API сможет удалить deprecated ключ отдельно.

## Модель данных категорий

### `catalog_collection_categories`

- `id` — внутренний ключ;
- `public_id` — immutable UUID для административных действий;
- `parent_id` — nullable self-FK; `null` означает корневую категорию;
- `slug` — уникальный стабильный URL-код, не переводится;
- `position` — явный порядок внутри одного родителя;
- `is_active` — доступность для нового выбора и публичного фильтра;
- timestamps.

Глубина ограничена двумя уровнями. Корень не может иметь родителя, дочерний
узел не может ссылаться на другой дочерний узел, цикл запрещён. Активировать
подкатегорию под неактивным родителем нельзя.

### `catalog_collection_category_translations`

- FK категории;
- allowlisted locale `ru|en`;
- отображаемое `name`;
- timestamps;
- unique `(catalog_collection_category_id, locale)`.

Идентичность, slug и parent relation не переводятся. Интерфейс использует
текущую locale, затем русский fallback, затем stable slug.

### Связь с подборкой

`catalog_collections.catalog_collection_category_id` — nullable FK на один
узел. Nullable нужен для существующих и private подборок. Хранить одновременно
category и subcategory ID запрещено, потому что это позволило бы записать
несовместимую пару.

Для новой или обновляемой владельцем `public|unlisted` подборки активная
категория обязательна. Private подборка может оставаться без категории.
Trusted source reconciliation не выдумывает классификацию удалённой
подборки и может создать source-managed запись без категории; последующее
ручное редакторское сохранение требует реального выбора. Старые записи не
получают выдуманную классификацию: до ручного выбора они попадают в системный,
не хранимый в таблице фильтр «Без категории».

## Начальный справочник

Система получает небольшой управляемый справочник reference data:

1. `Темы и жанры`
   - `Детективы и криминал`
   - `Фантастика и фэнтези`
   - `Документальные истории`
   - `Анимация и аниме`
   - `История`
   - `Семья и отношения`
   - `Юмор`
   - `Музыка`
2. `Настроение и повод`
   - `На выходные`
   - `Для спокойного вечера`
   - `Для обсуждения`
   - `Напряжённые истории`
   - `Вдохновляющие истории`
3. `Формат`
   - `Мини-сериалы`
   - `Короткие сериалы`
   - `Долгие истории`
   - `Экранизации`
   - `Новинки и премьеры`
4. `Страны и регионы`
   - `Россия`
   - `США`
   - `Европа`
   - `Южная Корея`
   - `Китай`
   - `Турция`
   - `Другие страны`
5. `Платформы и студии`
   - `Netflix`
   - `HBO и Max`
   - `Apple TV+`
   - `Amazon`
   - `Disney+`
   - `Другие платформы`

RU и EN-названия создаются отдельной идемпотентной reference-data
операцией после DDL, с детерминированными public UUID/slug. Это не
production-контент каталога и не пытается классифицировать существующие
подборки. DDL и data change остаются раздельными.

## Управление и авторизация

Административный интерфейс остаётся вложенной секцией существующего
`/admin/catalog`; новый full-page HTML route не создаётся.

- Просмотр очереди использует существующий `content.view`.
- Создание, перевод, переименование, сортировка, активация и архивирование
  категорий требуют `content.manage` и повторной server-side проверки.
- Операции принимают public UUID и optimistic version, а не numeric ID.
- Архивирование не удаляет уже назначенные связи. Архивная категория
  отображается владельцу как требующая замены, но не доступна в новом выборе
  и публичном фильтре.
- Удаление категории с назначенными подборками не предоставляется. После
  снятия всех назначений администратор может выполнить отдельное явное
  удаление, если это действительно потребуется в будущем.
- Для исходного каталога доступно bounded пакетное назначение: администратор
  фильтрует неклассифицированные подборки, явно выбирает не более 100 stable
  UUID и одну активную category/subcategory, затем видит число фактически
  изменённых и пропущенных записей. Выбор «всех результатов» и автоматическая
  классификация по словам отсутствуют.
- Изменения пишут существующее безопасное admin audit evidence без названий,
  private text и raw query.

Владелец редактирует только свою подборку через
`CatalogCollectionPolicy`. Livewire принимает UUID выбранного узла, сервис
повторно разрешает активную категорию и применяет правило обязательности для
public/unlisted. Тип, status, owner и parent relation не доверяются browser.

Для исходных редакционных подборок синхронизация не выводит категорию из
удалённого HTML и не меняет локальное административное назначение.

## Полное удаление обложек подборок

Удаление является product/schema/data/storage change, а не CSS-скрытием.

### Удаляемая функциональность

- поля `cover_disk`, `cover_path`, `cover_mime_type`, `cover_size`,
  `cover_version` из `catalog_collections`;
- source-поля `cover_source_path`, `cover_path`, `cover_content_hash` из
  `catalog_collection_sources`;
- upload/remove controls и Livewire upload state;
- cover services/responder/route и upload validation для подборок;
- импорт и конвертация remote collection cover;
- demo generation/repair/audit обложек;
- image/fallback-poster projection в collection card;
- collection cover в detail hero, profile, dashboard, поиске, sitemap,
  JSON-LD/SEO и API URL generation;
- конфигурация и переводы, используемые только этой возможностью.

Карточка подборки становится текстовой. Она не использует первый постер
тайтла как fallback, потому что пользователь просил убрать изображения
подборок везде.

### Порядок необратимой очистки

1. Развернуть код, который больше не пишет, не импортирует и не показывает
   обложки.
2. Выполнить dry-run точечной команды очистки и записать только безопасные
   counts/bytes.
3. Выполнить команду с явным `--execute`, которая:
   - принимает только configured uploads disk;
   - разрешает только точный префикс `catalog-collections/`;
   - удаляет файлы потоково/пакетами;
   - очищает cover metadata короткими транзакциями;
   - повторный запуск делает no-op;
   - не переносит файлы в trash, cache или backup.
4. Убедиться, что файлов и ненулевой metadata не осталось.
5. Применить отдельную schema migration, удаляющую cover-колонки.
6. Удалить временную compatibility-логику очистки после подтверждённого
   production rollout отдельной задачей, если она больше не нужна.

Пользователь явно запретил сохранять копии изображений. Поэтому rollback не
восстанавливает обложки: возможен только rollback к текстовому UI и прежним
collection records без файлов. До выполнения production cleanup оператор
обязан ещё раз проверить точный disk/prefix и dry-run; очистка не должна
затрагивать постеры каталога, аватары или другие uploads.

## Публичный UX `/discover/popular`

Раздел становится текстовым каталогом, оптимизированным для 500+ записей:

- заголовок, короткое объяснение и действие «Мои подборки»/«Войти»;
- desktop: слева компактный список корневых категорий с counts, справа
  результат;
- mobile: нативные select/раскрывающиеся фильтры без внутреннего scroll;
- после выбора корня показываются только его подкатегории;
- отдельный фильтр «Без категории» помогает найти старые записи;
- поиск, category, subcategory и sort видимы как активное состояние;
- действие «Сбросить» доступно только при изменённых фильтрах;
- результаты — вертикальные текстовые строки без картинок: название,
  категория/подкатегория, описание, число доступных тайтлов, автор,
  редакционный/imported/featured status и дата обновления;
- весь заголовок результата является понятной ссылкой; touch targets не
  меньше 44px;
- paginator сохраняет фильтры и scroll position без отдельного scroll-box;
- empty state различает пустую категорию и отсутствие результатов поиска.

На detail, home, профиле, dashboard, странице тайтла и поисковых подсказках
используется тот же text-only collection card. Внутренние карточки тайтлов
внутри detail остаются с постерами.

## Общее обновление discovery

Существующие девять mode routes и URL contract сохраняются. Обновление
выполняется прогрессивно:

1. Упростить верхнюю часть страницы: один H1, короткое mode-specific
   описание, компактная навигация и отсутствие ложного «обновления» как
   главного действия.
2. Показывать только релевантные выбранному режиму фильтры, а дополнительные
   facets раскрывать по запросу.
3. Сохранять URL-shareability, нативные controls, focus, reduced motion и
   русско-английский parity.
4. Для `popular` визуально отделить список тайтлов от каталога подборок, но
   не создавать второй маршрут или параллельную модель.
5. Не добавлять fake персонализацию, Premium, региональные или социальные
   возможности, которых нет в server-side contracts.

## Запросы и индексы

### Двухфазный public directory

Первая фаза строит только eligibility/filter/sort запрос по
`catalog_collections.id`:

- public + approved + active + not deleted;
- optional search;
- optional category/root/subcategory;
- deterministic order;
- `paginate()` по ID без item aggregates и owner payload.

Вторая фаза получает только ID текущей страницы и одним bounded запросом
загружает:

- явную проекцию полей подборки;
- owner public identity/name;
- current/fallback translation;
- category parent + allowlisted translations;
- grouped visible item count;
- source existence flag.

Порядок первой фазы восстанавливается в памяти. Если страница пуста, вторая
фаза не выполняется. Никакой fallback poster query не выполняется.

### Counts

Counts категорий вычисляются одним grouped query по публично допустимым
подборкам и category FK. Они не зависят от paginator page и не считают
membership. Результат допускает versioned bounded cache, инвалидируемый после
публикации, модерации, смены категории, restore/delete и изменения словаря.

### Индексы

- `(catalog_collection_category_id, visibility, moderation_status,
  deleted_at, updated_at, id)` для category directory;
- `(parent_id, is_active, position, id)` для дерева;
- unique category slug/public UUID;
- unique translation category/locale.

Search `%LIKE%` по имени/описанию остаётся ограничением SQLite. Первая версия
не добавляет FTS/внешний search engine; при подтверждённой нагрузке это
отдельная совместимая оптимизация.

## Cache, SEO, API и cross-feature impact

- Смена категории public collection инвалидирует collection detail,
  discovery/public directory, search suggestions, sitemap и связанные
  публичные HTML/API keys after commit.
- Мутация справочника инвалидирует category tree/counts и discovery.
- Sitemap сохраняет detail URL подборки, но перестаёт генерировать image
  extension для обложки.
- JSON-LD и OpenGraph подборки не содержат collection image/fallback poster.
- Account export добавляет stable category slug и локализованное имя, но не
  раскрывает внутренний ID.
- Account delete больше не собирает пути обложек.
- Import/scheduler больше не запрашивает remote collection cover; membership
  и recommendation signal остаются совместимыми.
- Search suggestions и related collection blocks используют тот же
  text-only view model и не выполняют poster fallback query.
- Права Premium, региональная доступность и title visibility продолжают
  применяться только существующими catalog query boundaries.

## Ошибки и конкурентность

- Неизвестный, архивный или несовместимый category UUID отклоняется русским
  validation message.
- Параллельное архивирование категории и сохранение подборки проверяется
  повторно внутри транзакции; неактивный узел не назначается.
- Параллельная перестановка узлов использует bounded sibling transaction и
  deterministic positions.
- Ошибка category counts скрывает фильтр fail-closed, но не должна ломать
  публичный список.
- Ошибка удаления одного cover-файла даёт ненулевой exit и сохраняет
  безопасный счётчик failed paths; schema columns не удаляются до нулевого
  остатка.
- Interrupted cleanup безопасно повторяется и не касается файлов вне
  allowlisted prefix.

## TDD и проверка

- Schema tests: таблицы, FK, unique/index contracts, depth/cycle rules,
  удалённые cover columns после финального этапа.
- Domain tests: create/update category validation, public/unlisted
  обязательность, private nullable, archive compatibility, permissions,
  source sync does not overwrite category.
- Query tests: category/root/subcategory/uncategorized filters, translation
  fallback, deterministic pagination, zero second-phase query on empty page,
  bounded query budget.
- UI tests: URL state, reset, dependent subcategory, empty states,
  text-only collection presentation во всех найденных consumers.
- Cover purge tests: dry-run, exact prefix, idempotency, partial failure,
  foreign uploads untouched, metadata zero.
- Compatibility tests: existing detail/profile/dashboard/API/search/sitemap,
  old cover route 404, API `cover_url=null`.
- Frontend: mobile/tablet/desktop browser QA, keyboard focus, 44px targets,
  no inner scroll, RU/EN.
- Verification: focused tests first, Pint, broad PHP tests, frontend build,
  Playwright QA, repository-wide legacy scan and query-plan evidence.

## Rollout и критерии готовности

1. Применить additive category DDL.
2. Создать reference taxonomy идемпотентной отдельной операцией.
3. Развернуть category-aware writes и text-only reads.
4. Проверить category query plans/counts и выборку текущей страницы.
5. Выполнить cover cleanup dry-run, затем explicit irreversible execute.
6. Подтвердить нулевой остаток файлов и metadata.
7. Применить destructive cover-column migration.
8. Проверить sitemap/API/SEO/cache, затем прогреть только документированным
   существующим механизмом.

Результат считается готовым, когда:

- ни один collection UI/API/SEO/import path не создаёт и не показывает
  изображение подборки;
- в allowlisted storage subtree нет файлов обложек и база не хранит их
  metadata;
- каждый пользователь может выбрать активную category/subcategory для своей
  подборки, но не изменить словарь;
- администратор управляет двухуровневым словарём в существующем catalog
  shell;
- `/discover/popular` фильтрует 500+ подборок по стабильному URL-state и
  показывает text-only results;
- стоимость detail-load ограничена текущей страницей;
- существующие публичные маршруты, права, title posters, membership и
  recommendation signals остаются совместимыми.

## Следующие улучшения после основного rollout

- curated category landing descriptions только после появления реального
  editorial owner и SEO-правил;
- измерение search latency и, при необходимости, отдельный SQLite FTS
  проект без изменения public URL;
- пользовательские вторичные метки только при подтверждённом сценарии, не
  как замена основной категории;
- сохранённые фильтры/подписки на категории после появления честной
  notification boundary;
- аналитика zero-result фильтров без хранения private search text;
- cursor pagination для API при подтверждённой необходимости, при сохранении
  HTML paginator contract.
