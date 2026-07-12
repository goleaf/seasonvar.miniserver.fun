# Каталог «Все сериалы»: фасеты, алфавит и честный поиск

Дата: 12.07.2026

## Цель

Перестроить `/titles` в компактный каталог всех сериалов по мотивам полезной части `https://seasonvar.ru/?mode=allSeasons`: сохранить быстрый алфавитный переход и полный список названий, но дополнить его проверенным поиском, комбинируемыми фасетами, доступностью серий/видео, сортировкой, управлением плотностью выдачи и адаптивным интерфейсом.

Страница остаётся server-rendered Blade, работает без JavaScript для поиска, фильтрации, сортировки и пагинации, использует только реальные данные и не добавляет production-зависимостей.

Пользователь прямо разрешил выбрать рекомендуемый вариант и реализовать его без уточняющих вопросов; эта спецификация фиксирует принятые решения до изменения кода.

## Исходное состояние и измерения

- В рабочей базе более 23 тысяч опубликованных тайтлов, более 87 тысяч актёров, около 189 тысяч связей актёров, более 540 тысяч серий и более 550 тысяч media-записей.
- Референс `allSeasons` выводит один глобальный поиск, русскую алфавитную полосу `# / А–Я` и полный список ссылок, сгруппированный по первой букве. Фасетов, сортировки и пагинации в основном блоке нет.
- Текущий `/titles` уже поддерживает поиск, точный год, десять справочников, шесть сортировок, context/global counts и пагинацию по 24 карточки.
- Текущий HTML достигает примерно 400–460 КБ из-за сотен развёрнутых ссылок фильтров.
- Пустой каталог выполняет около 20 SQL-запросов; неточный поиск может выполнять около 29 запросов и занимать до 14 секунд. Самый дорогой повторный facet-count запрос занимал около 8,4 секунды.
- Поиск уже нормализует Unicode, `ё/е`, пунктуацию и legacy-транслитерацию, распознаёт один год и требует совпадения всех значимых термов. Незавершённым остаётся удаление fallback, который после нулевого поиска показывает посторонний каталог.
- Общая визуальная система параллельно переводит карточку на один tab-stop, локальные assets, светлые Tailwind-токены и единый focus contract. Новая страница должна использовать эти общие поверхности, а не дублировать их.

## Рассмотренные подходы

### 1. Только повторить старый `allSeasons`

Алфавитная полоса и гигантский список просты, но не используют уже сохранённые жанры, страны, людей, переводы, статусы и media-состояние. На телефоне такой список неудобен, а фильтрация отсутствует.

### 2. Полный FTS5, autocomplete и importer-backfill в одном релизе

Это максимальная поисковая архитектура, но она требует новой индексной схемы, синхронизации с импортом и отдельного безопасного backfill. Смешивание её с UI/фасетами затруднит rollback и проверку причин ускорения.

### 3. Компактный faceted catalog с materialized search candidates — выбран

Сохраняется текущая цепочка Form Request → PageBuilder → Query services → ViewModel → Blade. Запрос пользователя нормализуется один раз; непустой legacy-поиск вычисляет набор candidate IDs один раз на request, после чего результаты и все фасеты переиспользуют этот набор. Фасеты считаются прямыми grouped pivot-запросами, а UI получает ограниченные option lists и всегда добавляет выбранные значения.

Подход даёт все доступные фильтры и заметное ускорение без изменения importer lifecycle. FTS5 и глобальный autocomplete остаются отдельным измеряемым rollout.

## Контракт URL и валидации

Интерактивные параметры используют повторяемые массивы:

```text
/titles?year[]=2023&year[]=2024&country[]=rossiya&country[]=kanada&actor[]=ivan-petrov
```

Правила:

- scalar legacy URL автоматически становится одноэлементным списком;
- существующие `/titles/year/{year}` и `/titles/{type}/{taxonomy}` продолжают работать;
- максимум 20 уникальных значений на измерение;
- OR внутри одного измерения, AND между измерениями;
- `year[]` принимает 1900…следующий календарный год;
- каждый slug проходит существующий `CatalogFilterSlug`;
- неизвестный корректный slug и неизвестный/неопубликованный `title` context дают ноль результатов и removable state, а не полный каталог;
- inferred year из `q` является жёстким ограничением: если выбранный year-set его не содержит, результат пуст;
- `video=available|missing` и `episodes=available|missing` используют существующие связи;
- `subtitles=available|missing` и повторяемый `quality[]` используют только опубликованные media; публичный набор quality ограничен реально встречающимися `2160p`, `1080p`, `720p`, `480p` и `360p`;
- `year_from/year_to`, `seasons_min/seasons_max` и `episodes_min/episodes_max` являются inclusive ranges и проверяют согласованность границ;
- `rating_source=any|kinopoisk|imdb`, `rating_min` от 0 до 10 и `votes_min` используют `catalog_title_ratings`;
- `updated=day|week|month|year` ограничивает `indexed_at`;
- `exclude_country[]` и `exclude_genre[]` дают понятный аналог reference-оператора `≠`; один и тот же slug нельзя одновременно включить и исключить;
- `letter` принимает `#`, `latin` или одну русскую букву; `Е` включает названия на `Е` и `Ё`;
- `view=grid|list`, `per_page=24|48|96`; default `grid` и `24` не включаются в чистый canonical;
- существующие sort values сохраняются и дополняются `seasons_desc`, `kinopoisk_desc`, `imdb_desc`, `popularity_desc` и `title_desc`; каждая сортировка заканчивается `catalog_titles.id DESC`.

Malformed arrays, nested values, превышение лимита и неподдерживаемые enum-значения возвращают русские сообщения Form Request.

## Поиск и выдача

- `CatalogSearchQueryParser` вызывается один раз.
- Для непустого ready-поиска `CatalogTitleQuery::searchCandidateIds()` один раз вычисляет опубликованные candidate IDs. Точный title/alias остаётся приоритетным; широкий поиск требует совпадения каждого значимого терма с одним тайтлом.
- Candidate IDs переиспользуются paginator, relation facets и year facets. Пустой поиск не материализует весь каталог в PHP.
- Legacy fallback удаляется полностью. Zero и insufficient являются честными состояниями.
- Title context, годы, relation groups, буква и availability применяются поверх candidate IDs.
- Range/count/rating/media filters строятся индексируемыми `whereIn` subqueries с grouped `HAVING`, без загрузки больших relations в PHP.
- Карточки выбирают только нужные поля, eager-load card relations и используют aggregate counts без N+1.
- Для карточек текущей страницы добавляются агрегаты рейтингов, чтобы rating sort и видимые значения не выполняли запросы из Blade.
- `view=list` использует существующий `x-title-list-row`; `view=grid` — `x-title-card`.

## Фасеты

Доступны все существующие измерения:

- год;
- жанр;
- страна;
- актёр;
- режиссёр;
- возрастной рейтинг;
- перевод;
- статус;
- канал;
- студия;
- тег;
- наличие опубликованного видео;
- наличие серий;
- наличие субтитров и доступность воспроизведения;
- качество media;
- диапазоны числа сезонов/серий;
- минимальный рейтинг/голоса и источник рейтинга;
- период обновления;
- исключение стран и жанров.

`CatalogFacetQuery` для каждого relation type строит context query без текущего измерения, присоединяет pivot, группирует по related ID и сортирует по контекстному count. Actor/director limits меньше genre/country limits. Выбранные значения добавляются даже вне top limit и даже с нулевым context count.

UI показывает только контекстный count. Прежний второй global count удаляется из request path: он перегружает интерфейс и заставляет пересчитывать большие справочники, не помогая выбрать следующий фильтр. `docs/DATA_RELATIONS.md` обновляется вместе с реализацией.

Годы считаются grouped published query с исключённым year-set. Алфавит не требует отдельного count-запроса.

## Интерфейс

Порядок на странице:

1. H1 и единственная основная форма поиска.
2. Алфавитная полоса `Все / # / А–Я / A–Z`.
3. Toolbar: число результатов, `Фильтры · N`, сортировка, вид и размер страницы.
4. Selected chips с удалением одного значения.
5. Результаты.
6. Пагинация с сохранением нормализованного query string.

Фильтры находятся перед результатами в DOM, но на телефоне закрыты компактной кнопкой/панелью, поэтому карточки не оказываются после многотысячного полотна. Без JavaScript native disclosure остаётся рабочим. JavaScript только улучшает мобильную панель, локально фильтрует уже полученные options, закрывает её по Escape и возвращает фокус.

Расширенные числовые и media-фильтры подписаны словами: `Год от/до`, `Сезонов от/до`, `Серий от/до`, `Рейтинг не ниже`, `Голосов не меньше`, `Качество`, `С субтитрами`, `Доступно для просмотра`. Неочевидные reference-операторы `=`, `>`, `<` и icon-only controls не копируются.

На desktop sidebar sticky и ограничен высотой viewport с внутренней прокруткой. На mobile карточка — компактная строка с постером, на `sm+` — сетка. Текст не обрезается. Title card имеет одну stretched title link; relation links остаются независимыми и лежат выше overlay.

Пустые состояния:

- zero: `По запросу «…» ничего не найдено.` и `Проверьте написание или измените фильтры.`;
- insufficient: `Запрос «…» слишком общий.` и `Добавьте название, имя актера, режиссера или жанр.`;
- обычная пустая комбинация: `По выбранным условиям ничего не найдено.`;
- действия: `Очистить поиск`, `Сбросить фильтры`, `Показать весь каталог` с разной семантикой сохранения query state.

Весь текст русский, светлая тема обязательна, touch targets не меньше 44 px, один `main`, один H1, visible focus, `aria-current` для active sort/view/letter.

## SEO и безопасность

- Один year или одна taxonomy landing могут оставаться indexable.
- Поиск, multi-value комбинации, availability, presentation parameters и invalid states получают `noindex,follow`.
- Canonical исключает sort/view/per_page и сортирует нормализованные массивы; clean landing routes сохраняются.
- Fallback-формулировки о «ближайших результатах» удаляются из SEO builder и документации.
- CollectionPage, ItemList, breadcrumbs и безопасный JSON-LD остаются на реальных тайтлах текущей страницы.
- Публичный HTML не выводит source URLs, raw media URLs, snapshots, importer state, hashes и stack traces.

## Индексы и производительность

- Добавляется обратимый composite index `(is_published, title, id)` для алфавита/title sort.
- Добавляются измеренные media indexes для published availability, quality и subtitles; ratings сохраняют существующие `(provider, rating)` и `(catalog_title_id, provider)` indexes.
- Существующие reverse pivot indexes и `(is_published, indexed_at, id)` используются без изменения старых миграций.
- Availability queries используют индексированные subqueries по `licensed_media` и `episodes/seasons`.
- Никакого постоянного result cache до измерения; materialized IDs живут только в одном request.
- Option lists строго ограничены; сотни всегда развёрнутых ссылок не рендерятся.
- Цель: существенно сократить 400+ КБ HTML и убрать многократное выполнение широкого поиска. Точные цифры фиксируются read-only before/after измерением.

## Проверки

TDD покрывает:

- scalar/array normalization, duplicate removal, limit 20, nested/malformed values и русские ошибки;
- два года OR, две страны OR, страна+актёр AND, все десять relation types;
- availability, alphabet, search hard year, invalid slugs/title context, published-only;
- exclusion, ranges, ratings, quality, subtitles, updated period и противоречивые комбинации;
- single materialization поиска, bounded facet query count и выбранные zero-count options;
- честные zero/insufficient states и три reset semantics;
- sort/view/per_page/pagination query preservation;
- один title link, независимые relation links, отсутствие nested anchors;
- canonical/noindex и отсутствие private/raw URL;
- no inline PHP, no truncation utilities.

После PHP запускаются focused tests и Pint, затем full suite. После Blade/JS/CSS — Vite build. Playwright проверяет 320×720, 390×844, 768×1024, 1440×1200 и 1920×1080: normal/search/zero/insufficient/multi-filter, mobile panel, keyboard, history, overflow, console/network/local assets и screenshots.

## Вне текущего rollout

- SQLite FTS5 и relevance ranking;
- async global autocomplete людей;
- typo suggestions;
- importer metadata versioning/backfill;
- перестройка title page и parser;
- новые production packages, аккаунты, write endpoints, fake content и скачивание видео.

Эти пункты являются отдельными подсистемами и не нужны для полноценной реализации страницы «Все сериалы».
