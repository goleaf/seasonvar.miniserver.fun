# Стандарты интерфейса

Обновлено: 16.07.2026

## Текущее визуальное направление

Seasonvar — не промо-лендинг, а плотный продуктовый каталог. Текущее направление называется «тихий технологичный каталог»: светлая slate-основа, белые непрозрачные поверхности, emerald как единственный основной action-цвет, локальные FontAwesome-иконки, системный кириллический шрифт, крупные постеры и спокойная типографическая иерархия. Интерфейс должен помогать найти тайтл, понять его состояние и начать просмотр, а не конкурировать с контентом декоративными эффектами.

Рабочие настройки визуальной системы:

- вариативность композиции — `4/10`: страницы узнаваемо связаны общей системой, но каталог, карточка тайтла, справочники и служебные экраны имеют разную информационную структуру;
- интенсивность движения — `2/10`: только короткая обратная связь hover/focus/loading и переход состояния, без GSAP, параллакса и декоративных entrance-анимаций;
- плотность — `7/10`: каталог и служебные данные остаются компактными, но основные действия, заголовки и touch-targets не уменьшаются ради количества;
- тема — только светлая; темный режим не считается незавершенной функцией;
- базовая стратегия — последовательная эволюция существующего Blade/Livewire-интерфейса, а не полная смена стиля или миграция на SPA.

Не переносить в продукт паттерны рекламных AIDA-страниц, glassmorphism, gradient text, крупные декоративные слоганы, псевдостатистику, вымышленные отзывы, иллюстрации без функции, бесконечные pill-контролы и анимацию ради демонстрации технологии. Brutalist, cinematic, luxury и dark-tech приемы допустимы только как источник отдельных идей контраста или ритма, но не как новый визуальный язык каталога.

## Проверенный baseline интерфейса

13.07.2026 выполнен read-only browser-аудит зарегистрированных route-поверхностей на `1440×1200`, `768×1024` и `390×844`, а интерактивные Livewire-сценарии дополнительно проверены на реальном HTTPS.

Подтверждено:

- публичные страницы, каталожные выборки, одиннадцать справочников, карточка тайтла, `/stats`, авторизованные `/watching`, `/admin/catalog` и `/admin/imports` отрисовываются без горизонтального переполнения;
- на основных продуктовых страницах есть один `h1`, один `main`, skip-link, русские подписи, alt-текст постеров и доступные имена controls;
- единый раскрывающийся блок фильтров доступен с клавиатуры на desktop/mobile; фильтр, сортировка, directory search, title refresh и stats poll получают успешные Livewire-ответы;
- неизвестные значения справочников дают `404`, directory detail routes ведут на канонические выборки, гостевые admin/playback boundaries дают ожидаемый `403`;
- header, footer, панели, карточки, пустые состояния и авторизованные экраны используют один светлый визуальный язык.

Подтвержденный долг, который нельзя считать причиной для тотального редизайна:

- `/stats` формирует примерно `951 КБ` HTML и полотно около `82 000 px` на desktop / `145 000 px` на mobile, поэтому ему нужна новая информационная архитектура и секционное получение данных;
- значимый текст с `text-slate-400` имеет недостаточный контраст на белом/slate-фоне; этот цвет должен остаться у декоративных иконок и действительно disabled-состояний;
- базовый цвет Plyr `#059669` и часть его controls требуют отдельной проверки контраста и эффективной зоны нажатия `44×44 px`;
- повторение одинаковых белых rounded-панелей ослабляет иерархию на длинных страницах; часть вложенных карточек нужно заменить разделителями, фоном секции и типографикой;
- пустой `favicon.ico` и generic error responses не завершают визуальную идентичность продукта.

Полный route-by-route аудит, оценка приоритетов и пошаговый план находятся в [`superpowers/plans/2026-07-13-seasonvar-ui-evolution.md`](superpowers/plans/2026-07-13-seasonvar-ui-evolution.md). Этот файл остается владельцем устойчивого UI-контракта; план не дублирует и не заменяет его.

## Главное правило доступности

В публичном интерфейсе нельзя создавать прокрутку внутри карточек, панелей, списков, dropdown, модальных блоков и других внутренних контейнеров. Это важнейшее правило: часть пользователей не понимает вложенные scroll-зоны и не сможет найти скрытый контент. Весь контент должен переноситься, раскрывать блок по высоте и прокручиваться только вместе со страницей. Классы `overflow-auto`, `overflow-scroll`, `overflow-x-auto`, `overflow-y-auto`, `overflow-x-scroll`, `overflow-y-scroll` и `overscroll-*` в Blade запрещены без отдельного архитектурного решения и теста.

## Тема

Интерфейс каталога только светлый.

Не использовать:

- `bg-black`
- `bg-zinc-900`, `bg-zinc-950`
- `bg-slate-900`, `bg-gray-900`, `bg-neutral-900`
- `text-white` для обычных блоков интерфейса
- `bg-white/[...]`, `border-white/...`, темные полупрозрачные панели
- темные фоны страниц

Использовать:

- фон страницы: `bg-slate-50`
- панели: `bg-white`, `border-slate-200`, `shadow-slate-200/60`
- приглушенные блоки: `bg-slate-50`
- основной акцент: `emerald-50`, `emerald-100`, `emerald-700`
- текст: `text-slate-700`, `text-slate-600`, `text-slate-500`

## Иконки

- Использовать FontAwesome из локального npm-пакета `@fortawesome/fontawesome-free`.
- Подключать CSS иконок через Vite из `resources/js/app.js`; CDN-ссылки не использовать.
- Все прямые FontAwesome-иконки в Blade выводить через `x-ui.icon`; сырой `<i class="fa-…">` допустим только внутри реализации этого компонента.
- Предпочитать параметры иконок в компонентах: `x-ui.panel icon="..."`, `x-ui.taxonomy-chip icon="..."`, `x-ui.section-title icon="..."` и `x-stat icon="..."`.
- Иконки по умолчанию декоративные и должны иметь `aria-hidden="true"`, при этом видимый текст должен оставаться на месте.
- Держать иконки в светлой палитре: `text-emerald-700`, `text-slate-400`, `text-slate-500` или семантические светлые цвета состояния.
- Размер иконки наследуется от текста, а стабильный box задаётся в `em`, поэтому не добавлять локальные фиксированные ширину, высоту или `margin-top` без необходимости.
- Для иконки у первой строки многострочного текста использовать `align="start"`; не подгонять вертикаль через `mt-0.5` или `mt-1`.
- Иконки добавлять к действиям, статусам, навигации, системным заголовкам и metadata-labels. Не дублировать ими каждое контентное название.

## Общие компоненты

Blade и Livewire Blade являются только presentation layer: запрещены `@php`/PHP tags, database/cache/service calls и Volt. Данные и вычисления готовят отдельные PHP-классы компонентов, page builders, query services или view models.

Перед повторной версткой использовать общие Blade-компоненты:

- `x-ui.panel` для всех секций с рамкой и боковых блоков.
- `x-ui.taxonomy-chip` для каждой ссылки или плашки справочника.
- `x-ui.section-title` для заголовков секций, когда полный заголовок панели не нужен.
- `x-ui.poster-frame` — единственная граница, которая выводит `<img>` постера каталога или его русскую заглушку. Она получает только готовые `src`/`alt`, не проверяет URL и не обращается к модели, базе, cache или service container.
- `x-ui.poster-frame` явно различает `cover` и `contain`. Главный постер и технические миниатюры могут использовать `cover` с overscan, но все строки контентных списков используют точный frame `2:3`, `object-contain`, центрирование и `overscan=false`, поэтому постер не обрезается на телефоне и планшете. У изображения нет собственной ring/border/shadow/padding/rounded-рамки.
- `x-ui.poster-card` задаёт каркасы `list`, `compact`, `recommendation` и технический `stats`. Контентные варианты являются безрамочными строками внутри одного родительского `divide-y` списка; только `stats` сохраняет отдельную вертикальную техническую карточку.
- `x-catalog.title-card` — единый query-free вход для списков, поиска и рекомендаций `CatalogTitle`; обычный и fallback layout всегда `list`, а счётчики и справочники берутся только из агрегатов или уже загруженных связей.
- Блок «Советуем посмотреть» всегда использует один ordered list: одна строка на rank, портретный frame `2:3` без crop, до четырёх причин и один stretched title link. Нельзя возвращать featured/grid смесь, вложенную панель «Ближайшие совпадения» или отдельные genre/year колонки.
- Главный постер страницы сериала использует `x-ui.poster-frame` напрямую и загружается eager; HTML-атрибут `poster` у video player не является карточным изображением и этим контрактом не заменяется.
- `x-stat` для счетчиков панели состояния.
- `x-layout.site-footer` завершает публичные страницы брендовой областью, основной и служебной навигацией; на телефоне используется одна колонка, на широком экране — три.
- `x-layout.site-header` всегда состоит из двух полос: бренд и глобальный поиск находятся в первой, вся основная навигация — во второй. На телефоне подписи пунктов визуально скрыты при сохранённых доступных именах, с `sm` становятся видимыми, а длинное меню переносится через `flex-wrap` без внутренней прокрутки.
- Страница карточки показывает summaries всех доступных сезонов, но серии и media загружает только для одного активного сезона через Livewire.
- Во время фонового обновления карточка показывает компактный русский статус «Обновляем данные» в toolbar, затем «Данные обновлены» или «Не удалось обновить». Активное состояние может опрашиваться только в видимой вкладке; после terminal state polling прекращается, а сохранённые данные страницы остаются доступны.
- Воспроизведение видео использует локальный пакет Plyr/HLS, который инициализируется из `resources/js/app.js`.
- Служебная статистика `/stats` строится на Livewire 4, обновляется через `wire:poll.15s.visible` и может показывать миниатюры постеров только через внутренний proxy-маршрут, не выводя исходные внешние URL в HTML или Livewire payload.
- Admin importer использует те же светлые panel/control/status паттерны. Loading и empty state обязательны, cancel/retry требуют confirmation, provider URLs и raw error details не выводятся.
- Если poster URL не проходит `CatalogStatsPosterUrlGuard`, `/stats` передаёт в `x-ui.poster-frame` значение `null` и показывает светлую заглушку, не создавая 404-запрос к proxy. Компонент не ослабляет server-side guard.

## Читаемость

- У каждого крупного блока должен быть короткий видимый заголовок.
- Ссылки связей должны выглядеть кликабельными и оставаться читаемыми на мобильных экранах.
- Текстовые ссылки, taxonomy/status chips, пункты меню, фильтры, сортировки, алфавит, пагинация и SEO-query links не используют декоративные `ring-*`, `border-*` или похожую обводку вокруг текста. Для состояния использовать фон (`bg-slate-50`, `bg-emerald-50`), цвет текста, жирность, иконку и hover-фон.
- Обводка допустима только у структурных контейнеров: панелей, карточек, форм, media/poster frames, таблиц, ошибок валидации и layout-разделителей. Нельзя возвращать outlined-плашки для простых ссылок.
- Длинные описания используют нормальную высоту строки и slate-текст, а не мелкий малоконтрастный текст.
- Значимый обычный текст на `bg-white` и `bg-slate-50` использует минимум `text-slate-500`; `text-slate-400` разрешен для декоративных иконок, необязательной графики и явно disabled-состояний, но не для labels, metadata, placeholder или инструкции.
- Белый текст основного действия располагается на `bg-emerald-700` или более темном фоне. Hover не должен переходить на `emerald-600`, если из-за этого контраст текста становится ниже WCAG AA.
- Эффективная зона нажатия основного control должна быть не меньше `44×44 px`, включая controls Plyr. Видимый glyph может быть меньше, но hit-area, focus-ring и расстояние до соседнего действия остаются достаточными.
- Видимый текст интерфейса не обрезается Tailwind-утилитами `line-clamp-*`, `truncate`, `text-ellipsis` или `overflow-ellipsis`; длинные названия и описания должны переноситься и показываться полностью.
- Плотные метаданные должны выводиться как label/value или плашки, а не сырой строкой через запятые.
- Публичные заголовки, SEO-описания, related links и chips выбранных фильтров не используют машинный формат `Тип: значение` для справочников каталога. Для actor/director/genre/country/status/etc. писать естественные фразы: «Сериалы с актёром …», «в жанре …», «по стране производства …», «с озвучкой …».
- Мини-блоки статистики, статуса, вариантов и серий с фиксированной минимальной высотой должны вертикально центрировать содержимое через `grid`/`content-center` или эквивалентный flex-pattern. Нельзя оставлять текст прижатым к верхнему краю с пустотой снизу.
- Кликабельные ссылки, кнопки, summary, label-controls, role-button элементы и Livewire history controls получают `cursor: pointer` из глобального CSS. Локальные `cursor-*` классы добавлять только для исключений.
- Заглушка плеера должна оставаться светлой даже без подключенного медиа.
- Пустые состояния для посетителей должны быть написаны простым русским языком и не должны использовать технические слова вроде «парсер», «импорт», «команда» или «синхронизация».

## Правила раскладки

- Использовать `gap`-утилиты для расстояний между соседними элементами.
- CSS Grid допустим для структуры страниц, форм, навигации, счётчиков, player/admin controls и технической статистики. Контентные коллекции тайтлов, обновлений, справочников, рекомендаций и личной библиотеки выводятся только одним вертикальным списком или таблицей без переключателя вида.
- Не дублировать классы заголовков панелей внутри страниц; использовать `x-ui.panel`.
- До широких сеток мобильная раскладка должна оставаться в одну колонку.
- Основные списки каталога показывают рядом с названием полный постер `2:3` шириной `4rem`, `5rem`, `6rem` на base, `sm`, `md`; отсутствие постера занимает тот же frame.
- Использовать `minmax(0, 1fr)` в многоколоночных сетках страниц, чтобы избежать горизонтального переполнения.
- Планшетные раскладки не должны принудительно включать три плотные колонки до `xl`.
- Списки серий должны оставаться читаемыми на мобильных экранах и могут использовать две или три колонки только начиная со средних широких экранов.
- Главная страница начинается со счётчиков каталога; «Последние обновления» выводит сгруппированный по датам список только тех тайтлов, где добавилась доступная серия или опубликованный видеовариант. В «Новых сериях» один сериал занимает одну одноколоночную карточку: внутри перечисляются все добавленные за его последнюю дату обновления серии и все их видеоварианты с метаданными. «Сейчас можно смотреть» использует такие же одноколоночные строки, а дублирующая лента обновлений не создаётся.
- Страница сериала должна поднимать блок просмотра выше справочных SEO-секций; выбор сезона и серии должен оставаться крупным и удобным для телефона, планшета и ТВ.
- Primary action карточки должен явно показывать continue/next/start/unavailable state; проигрыватель занимает полную ширину панели, authenticated watchlist/rating controls идут отдельной адаптивной строкой «Ваш сериал» под видео, а прогресс не раскрывается в URL или публичном Livewire state.
- Если `title` заканчивается на `/original_title`, в основном публичном заголовке выводится только часть до разделителя, а оригинальное название — отдельной вторичной строкой. Несовпадающие названия со слешем сохраняются полностью; исходные данные базы не переписываются.
- Смена варианта просмотра на странице сериала выполняется через Livewire `selectMedia` с обычной ссылкой fallback: во время запроса spinner показывается только у плеера, списка серий и выбранного варианта, без постоянных глобальных индикаторов.
- В списке «Сезоны и серии» карточка серии показывает выбранный playback-профиль серии, если для неё найден подходящий вариант медиа; текст берётся из ViewModel, а не вычисляется в Blade.
- `/watching` показывает одну Continue Watching карточку на сериал, сохраняет длинные названия без обрезки, использует отдельные empty/loading states и заменяет недоступную историю нейтральной строкой без ссылки или скрытых metadata.
- Левая панель быстрого доступа на странице сериала должна оставаться плоской: ссылки, текущий выбор и счетчики используют фон, цвет, жирность и иконки без декоративной обводки вокруг пунктов.
- Активный пункт быстрого доступа использует узкий цветной маркер и спокойный `emerald-50`, а не сплошную контрастную кнопку. Все пункты сохраняют высоту не менее 44 пикселей и видимый клавиатурный focus.
- На странице сериала запрещена отдельная панель «Связи каталога» или её переименованный аналог со сводным повтором таксономий. Жанры, страны, актёры и другие связи выводятся только в существующих контекстных блоках страницы.
- Каталог `/titles` сохраняет единый порядок на всех экранах: поиск и управление выдачей, раскрывающийся «Точный подбор», затем один список результатов; сортировка выводится русскими плашками с локальными FontAwesome-иконками. URL-state `view` и переключатель сетка/список отсутствуют, legacy-параметр `view` игнорируется.
- Сводные карточки «Найдено / На странице / Сортировка» над выдачей не выводятся. Пагинация плавно возвращает к началу собственного обновлённого списка только после Livewire morph, учитывает sticky header и отключает анимацию при `prefers-reduced-motion`; без JavaScript те же элементы работают как обычные GET-ссылки.
- На `/titles` один полноширинный блок `id="catalog-filters"` находится внутри основной колонки между управлением выдачей и карточками. Отдельные desktop sidebar, mobile dialog и кнопка открытия фильтров не используются.
- Все группы автоматически загружаются в «Точный подбор» через отдельный sibling deferred Livewire island между панелью управления и выдачей. Одноимённые `catalog-live` islands нельзя вкладывать друг в друга. Checkbox/select обновляют выдачу сразу через `wire:model.live`, числовые поля применяются общей GET/Livewire-формой; строки выбора остаются без декоративной обводки вокруг текста.
- Выбранные годы и значения справочников в едином фильтре поднимаются в начало своей группы; активная группа может иметь плоскую текстовую ссылку «Сбросить» без border/ring-обводки.
- На scoped routes каталога route-страна, route-жанр, другой route-справочник или route-год должны быть не только подсвечены, но и действительно отмечены checkbox. Дополнительные значения той же группы остаются отмеченными вместе с route-значением; значения разных групп одновременно отражают составной фильтр.
- Устаревшие или несуществующие slug-значения справочников в query string `/titles` игнорируются без публичных счетчиков ошибок, warning-чипов и текста вроде «Ошибочных фильтров».
- Актеры и режиссеры в фильтрах `/titles` используют доступный API-combobox с debounce, отменой stale request, клавиатурным управлением и максимум 20 публичными вариантами; полный справочник и внутренние ID не попадают в браузер. Остальные длинные группы используют локальный progressive-enhancement поиск по уже загруженному ограниченному списку.
- Header search должен оставаться видимым на всех публичных страницах, включая catalog listing routes, и делить верхнюю полосу только с брендом. На `/titles` локальная поисковая форма каталога может сосуществовать с header search, но обе формы должны иметь разные доступные имена и разные `id` полей. Активная сортировка объявляется через `aria-current="true"`.
- Directory hubs используют один responsive Livewire список с разделителями на телефоне, планшете и desktop; годовой справочник создаёт отдельный такой список внутри каждого десятилетия. Search control, alphabet/decade buttons и pagination имеют touch target не меньше 44 px; loading status объявляется через `role=status`, пустое состояние остаётся в SSR. Алфавит разделяет доступные буквы на подписанные строки «Кириллица» и «Латиница», символы держит отдельно, естественно переносит controls и не создаёт внутренний horizontal scroll. Plain navigation links не получают decorative border, а keyboard focus остаётся видимым через согласованный focus ring.
- Карточка directory value выводит только подготовленные `name`, canonical detail URL и `published_titles_count`; Blade не выполняет query и использует стабильный `wire:key`. Длинные имена переносятся, counts не исчезают, внутренний scroll-container не создаётся.
- «Точный подбор» раскрывается сразу, если URL содержит любое активное условие: год, справочник, тип публикации, субтитры, расширенный параметр, качество или букву.
- Блок сохраняет четыре группы «Период», «Объём», «Рейтинг» и «Видео», а ниже содержит годы, тип публикации, субтитры и все справочники каталога. Расширенные группы идут на desktop в две колонки, facet-группы — в адаптивные колонки без внутренней прокрутки; на телефоне всё остаётся в одну колонку.
- Summary «Точного подбора» показывает общее число активных условий. Действие «Сбросить фильтры» очищает весь набор фильтров через существующий `resetAll`.
- Мобильная панель выдачи `/titles` даёт доступ к сортировке, размеру страницы и тем же отдельным группам кириллицы/латиницы без дублирования state или JavaScript. Латинские буквы выводятся индивидуально от `A` до `Z`, а не общей кнопкой `A–Z`.
- Pagination каталога использует только светлые панели, русские подписи `Назад`/`Вперед`, видимую сводку диапазона и элементы управления высотой не менее 44 пикселей; на телефоне элементы могут переноситься на следующую строку без горизонтального скролла.
- Сортировка, размер страницы, алфавит, групповой сброс и выбранные filter chips также используют touch-target не менее 44 пикселей. Счетчики не сжимаются, а длинные названия переносятся без обрезки.
- Общий `focus-visible` использует двухпиксельный emerald-контур со светлой внешней зоной; составные поисковые поля подсвечивают всю рамку через `data-focus-frame`, не дублируя контур внутри input.
- Поисковая выдача не показывает посторонние карточки при нулевом совпадении. Для поиска, фильтров и полного каталога используются отдельные понятные действия сброса: «Очистить поиск», «Убрать фильтры» и «Показать весь каталог».
- Livewire-dashboard `/stats` должен оставаться в одну колонку на телефонах, переходить к двум колонкам на планшетах и использовать плотные сетки только на `xl`/шире; широкие таблицы заменяются адаптивными строками или карточками.

## Коллекции

- Collection directory/cards, owner dashboard, editor и public page используют существующие `x-ui.panel`, `x-ui.poster-frame`, `x-catalog.title-card`, form/status/pagination components и светлую палитру; отдельный visual system не вводится.
- Long user names/descriptions переносятся, cover сохраняет `16:9`, missing cover использует безопасный fallback, а structural grids переходят в один столбец на узком экране без horizontal overflow.
- Все action targets не меньше 44px. Visibility radio, selector checkboxes, report dialog, delete confirmation, locale links и reorder up/down доступны keyboard; drag/hover/color не являются единственным способом действия.
- Loading/success/error содержат localized live/status regions, destructive controls отделены цветом и текстом, а unavailable item не раскрывает internal removal reason.

## Обсуждения

- Discussion region, scope selector, composer, sorting, comment list, replies, report dialog и moderation page переиспользуют светлые panels, status pills, buttons, textarea и pagination проекта; произвольная тема/радиусы/тени/inline CSS не добавляются.
- На телефоне reply indentation остаётся одним компактным border/padding level; long username/body/link wrap-ятся через `break-words`/`overflow-wrap:anywhere`, reactions/actions естественно переносятся и не создают horizontal scroll. Все controls имеют минимум 44px touch target и не требуют hover.
- Spoiler скрывается семантически: unrevealed body отсутствует, warning и reveal/hide button имеют translated text/ARIA, stable live region объявляет изменение, а Vite-модуль возвращает keyboard focus на replacement toggle после Livewire morph. Long body использует доступные show more/less controls. Deleted, blocked/muted и unavailable parent показывают нейтральный tombstone без color-only смысла или утечки причины.
- Focused direct comment получает programmatic focus/highlight и `scroll-margin`; dialog/reply/edit восстанавливают focus. Smooth scroll отключается при `prefers-reduced-motion`. Live regions объявляют loading/success/error, controls имеют `aria-pressed`, time `datetime`, pagination label и keyboard path без traps.
- Empty/disabled/guest/unverified/restricted/query-failure/no-replies/end-of-replies states имеют локализованное объяснение и только допустимые действия. Composer text сохраняется при recoverable error; мелкое действие не очищает весь discussion. Desktop/tablet/phone/200% zoom должны сохранять heading order, readable line length и отделение destructive controls.

## Отзывы

- Review region, complete-serial scope label, composer, sort/filter, cards, spoiler warning, report dialog, self history and admin queue reuse existing light panels/forms/status/buttons/pagination; no dark marketing theme, arbitrary gradient/radius/shadow or inline style is introduced.
- Long Unicode title/body/name/plain URL wrap without horizontal overflow. Composer/filter/action rows collapse to one column on narrow phones; every button/select/radio has at least 44px touch target, visible label/focus and no hover/color-only meaning. Rating remains a keyboard/touch usable labelled 1–10 select with selected value after validation failure.
- Whole-review spoiler title and body are semantically absent until reveal. Reveal/hide exposes `aria-expanded`/`aria-controls`; verified, edited, rating, status and dates have readable text. Direct review receives focus/highlight with scroll margin and reduced-motion-safe behavior; pagination/sort/filter preserve browser URL state.
- Create/edit/delete/restore/vote/report/moderation use localized live regions, loading locks, confirmation and recoverable failure without blanking the whole list. Empty/filtered-empty/disabled/guest/unverified/restricted/pending/rejected/deleted/unavailable/end-of-results states show only authorized actions. Session draft clears only after successful create/update.
- Manual responsive inspection covers 320px phone, landscape, tablet, desktop and 200% zoom with long translations, spoiler, missing avatar, large vote counts and moderation messages. Focus order follows heading→composer/filter→list; dialogs have no keyboard trap and destructive controls remain separated.

## Теги

- Public/global tags reuse `x-ui.taxonomy-chip`, catalog title cards, panels, status pills, filters and pagination. Personal badges include explicit translated «личный тег», global badge has accessible «публичный тег» label; type/privacy meaning never relies only on emerald/violet color.
- Title badges wrap with `max-w-full`, `break-words` and existing overflow rules. Public tags link through route helper to canonical page; private personal tag is text/button only and never receives a public href. Long Unicode labels and large tag sets must not cause horizontal scroll at 320px or 200% zoom.
- `/library/tags/manage` and title selector provide labelled inputs, validation text, 44px actions, explicit edit/delete/restore confirmation, `role=status` announcements and localized loading/empty/failure states. Multi-select uses combobox/listbox semantics, debounced `wire:model.live`, stable `wire:key`, keyboard/touch checkboxes and Apply/Cancel; Cancel never persists draft.
- Public tag page keeps one H1 through catalog page, summary/description/aliases/related navigation in logical heading order, visible serial count, accessible pagination/filter/sort and plain escaped text. Long description uses native details/summary without hidden unsafe HTML or JavaScript business logic.
- `/admin/tags` reuses existing admin panels/forms and text confirmations for archive/merge impact; controls have per-action loading locks and do not rely on drag/hover. Private personal tags are not listed there. Mobile form grids collapse naturally and destructive merge/archive controls remain separated.
- All tag UI labels/ARIA/status/errors live in parity-matched `lang/ru/tags.php` and `lang/en/tags.php`. Original personal content is never translated on locale switch. No Volt, `@php`, inline CSS, raw highlighting, Blade model/service query or large inline business JavaScript belongs in tag templates.
- Manual responsive acceptance covers phone/landscape/tablet/desktop, 200% zoom, very long Cyrillic/Latin labels, many selected tags, empty/error/restorable states, keyboard focus after Livewire updates, loading locks and browser back/forward for public filters/pagination and private library tag filter.

## Настройки аккаунта

- Settings shell использует существующую светлую palette, panels, buttons и form controls. Mobile navigation остаётся keyboard-доступным горизонтальным списком, active section имеет `aria-current`, desktop layout — bounded sidebar/content grid без загрузки всех sections.
- Каждый toggle остаётся native labelled checkbox с checked/focus state и touch target; timezone использует labelled input+datalist и явную кнопку browser suggestion, volume — labelled range с output. Notification matrix на узком экране превращается в читаемые stacked rows без horizontal table overflow.
- Save/Cancel/reset/revoke/delete имеют scoped loading, disabled, localized live status/error и confirmation; destructive actions визуально и структурно отделены. Recoverable failure сохраняет draft/focus, successful Livewire morph повторно инициализирует только display/device helpers.
- Account reduced-motion override и OS `prefers-reduced-motion` отключают transitions/animations/scroll behavior на application level. Manual acceptance покрывает 320px, landscape, tablet, desktop, 200% zoom, long ru/en timezone labels, keyboard navigation, validation/empty/failure/session/destructive states.

## Заявки на материалы

- Directory, request card/detail/create, My Requests и moderation queue переиспользуют светлые panels/forms/badges/buttons/pagination, 44px controls и одну responsive колонку на телефоне. Long original/translated title, explanation, URL, evidence list и timeline используют wrapping без horizontal scroll; destructive withdrawal/merge отделены и подтверждаются текстом.
- Autocomplete имеет visible label, combobox/listbox roles, 300 ms debounce, stable item keys, loading/no-result/fallback states и keyboard/touch actions. Server-side existing-content/duplicate validation остаётся authoritative; Blade не вычисляет identity, duplicate, priority, transition, permission или importer eligibility.
- Vote/follow используют `aria-pressed`, status descriptions доступны не только цветом, timeline сохраняет heading/list semantics, Livewire actions имеют scoped disabled/loading и localized live regions. `ru/en`, long labels, 320px/landscape/tablet/desktop/200% zoom, browser back/forward, keyboard focus и reduced motion входят в manual acceptance.

<!-- project-docs:start -->
## Документация интерфейса

- Автоматическое обновление документации не должно добавлять видимые тексты на публичные страницы каталога.
- Изменения sitemap, robots и hook не меняют светлую тему, Blade-компоненты и русскоязычные правила интерфейса.
- Если будущая правка меняет видимые блоки, этот файл нужно обновить вручную и затем запустить `php artisan project:docs-refresh`.
<!-- project-docs:end -->

## Recommendation/discovery UI

- Type navigation remains usable by touch and keyboard; horizontal overflow is contained inside the navigation, never the page. No autoplay carousel is used.
- Filters use existing responsive form controls, stable labels and one collapsible advanced taxonomy group. Long translated labels/select values wrap or truncate inside their control without overlapping feedback actions.
- Recommendation list owns heading hierarchy, ordered rank, poster alternative text, broad visible reason and one meaningful title link. Internal score is not a percentage/rating and is never rendered.
- Feedback menu buttons are at least 44px, server-authorized, disabled during request and followed by accessible localized notice/undo. Loading, cold-start, empty and failure states are live-region safe and do not blank unrelated sections.
- Phone, landscape phone, tablet, desktop and zoom layouts use existing light-theme tokens/components; no inline CSS, arbitrary colour/shadow/radius, hover-only information or focus trap was introduced.

## Размер и скачивание выбранного видео

- Размер direct-file показывается рядом с existing quality/format через reusable status pill и локальный `fa-hard-drive`. Unknown отображается текстом, HLS manifest size не показывается как размер видео.
- Primary download action использует local `fa-file-arrow-down`, emerald/slate language, `min-h-11`, visible `focus-visible` ring, полный width на phone и content width начиная с `sm`. Format/size — вторичная строка, не отдельная кликабельная цель.
- Guest получает компактную login action с lock icon и ясным сообщением о регистрации; HLS/unsupported получает non-interactive stream-only state. Скрытие active link не заменяет endpoint policy.
- Attachment открывается обычным `<a>` на named route, не `wire:click`; raw provider URL, inline styles, layout shift и binary transfer через Livewire запрещены.
