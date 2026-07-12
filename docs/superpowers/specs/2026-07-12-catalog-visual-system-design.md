# Футуристичная дизайн-система каталога

Дата: 12.07.2026

## Цель

Обновить публичный Laravel-каталог как цельный светлый продукт: ускорить сканирование больших списков, сделать главные действия очевидными на телефоне, планшете и широком экране, повысить доступность и сохранить server-rendered Blade, SEO-контракт и реальные данные Seasonvar.

Текущий этап реализует фундамент и наиболее посещаемые поверхности без новых production-зависимостей. Полный долгосрочный маршрут описан отдельно в разделе «Максимальная программа развития».

## Исходное состояние

Аудит выполнен по реальному каталогу с более чем 23 тысячами тайтлов и по страницам `/`, `/titles` и `/titles/{slug}`.

Сильные стороны:

- интерфейс уже полностью светлый и русскоязычный;
- используются общие Blade-компоненты, локальные FontAwesome, Plyr и HLS;
- карточки получают eager-loaded связи и aggregate counts, запросов из Blade нет;
- основной контент не обрезается;
- desktop и mobile не имеют горизонтального переполнения;
- исходные UI/Blade-тесты проходят: 25 тестов и 222 проверки.

Подтвержденные проблемы:

- почти каждый уровень использует одинаковые маленькие рамки, поэтому важные действия и вторичные данные визуально равнозначны;
- главная начинается со счетчиков, хотя поиск и переходы должны быть первым пользовательским действием;
- header на телефоне занимает много места и не дает четкого active-state;
- один тайтл создает два одинаковых tab-stop через постер и заголовок;
- карточки и панели используют слабую шкалу радиусов, теней и плотности;
- длинные боковые справочники и фильтры превращают mobile/desktop страницы в чрезмерно высокие полотна;
- отсутствуют skip-link, единый сильный `focus-visible` contract и полноценный footer с техническими маршрутами;
- визуальная система почти не использует возможности CSS-first Tailwind 4: собственные OKLCH-токены, container-aware композицию и progressive rendering.
- глобальный `all.min.css` FontAwesome включает неиспользуемые brands и v4 compatibility fonts;
- текущий Instrument Sans bundle содержит только Latin/Latin Extended, поэтому русский интерфейс фактически использует системный fallback после лишнего preload;
- стандартный Plyr config указывает на внешний `cdn.plyr.io` для SVG sprite и blank video, хотя проект требует локальные assets.

Baseline-скриншоты находятся в `output/playwright/before-home-desktop.png`, `output/playwright/before-home-mobile.png`, `output/playwright/before-titles-desktop.png` и `output/playwright/before-titles-mobile.png`.

## Рассмотренные подходы

### 1. Livewire/SPA и motion-библиотеки

Плюсы: богатые переходы и интерактивные фильтры. Минусы: дополнительный клиентский runtime, более сложное восстановление состояния, риск для SEO и доступности, дублирование существующего read-only Blade-потока.

### 2. Готовый UI-kit

Плюсы: быстрый набор поверхностей. Минусы: внешний визуальный язык, лишние зависимости, конфликт с проектными компонентами и тяжелая кастомизация для плотного каталога.

### 3. Progressive enhancement на Blade и Tailwind 4

Плюсы: использует текущий стек, сохраняет HTML-first навигацию и быстрый первый рендер, дает единый визуальный язык через CSS-first tokens, native disclosure и минимальный JavaScript. Минус: интерактивные сценарии нужно проектировать точечно.

Выбран третий подход.

## Визуальное направление

Стиль — «тихий технологичный каталог», а не рекламный лендинг. Футуристичность создается качеством пространства и отклика:

- светлый slate-фон с очень мягкими emerald/cyan radial gradients;
- белые непрозрачные поверхности с тонкой slate-рамкой;
- два уровня радиуса: 16 px для элементов и 20–24 px для крупных панелей;
- мягкая цветная тень вместо серой тяжелой тени;
- emerald остается основным action-цветом, cyan/sky и amber используются только семантически;
- крупные заголовки имеют плотный tracking, обычный текст сохраняет комфортную высоту строки;
- анимации ограничены hover/focus lift и отключаются через `prefers-reduced-motion`.

Темная тема не добавляется. Полупрозрачные темные поверхности, fake glassmorphism, декоративный шум и маркетинговый текст запрещены.

## Дизайн-токены

`resources/css/app.css` остается единственным источником CSS-first конфигурации:

- `--color-aurora-*` задает дополнительный cyan/emerald спектр в OKLCH;
- `--radius-panel` и `--radius-control` создают соответствующие Tailwind utilities;
- `--shadow-panel` и `--shadow-panel-hover` задают системные уровни поверхности;
- global `focus-visible` использует контрастный emerald outline не тоньше 2 px;
- `::selection`, scrollbar accent, reduced-motion и `color-scheme: light` согласуются с палитрой;
- `.catalog-card` получает `content-visibility: auto` и безопасный intrinsic size для длинных каталогов.

## Layout и навигация

### Header

- Добавляется видимый при фокусе skip-link к `#main-content`.
- Header становится компактной цельной поверхностью; sticky-поведение включается только на `lg`, чтобы не закрывать телефон.
- Логотип, глобальный поиск и навигация сохраняются, но получают разные уровни визуальной важности.
- Текущий маршрут отмечается `aria-current="page"` и визуальным active-state.
- На телефоне поиск занимает отдельную строку, а навигация остается компактной и не вызывает overflow.

### Main

- Основной контейнер сохраняет широкую рабочую область, но получает стабильные responsive gutters.
- Breadcrumb становится компактной trail-поверхностью и не конкурирует с hero.
- Все section anchors учитывают высоту desktop header.

### Footer

- Добавляется небольшой footer с реальными ссылками на каталог, sitemap и RSS.
- Footer не содержит рекламного или вымышленного текста.

## Компоненты

### `x-ui.panel`

Компонент остается общей границей секций. Он получает крупный радиус, новую тень, более выразительный header, icon tile и адаптивный padding. Слот и текущий API не меняются.

### `x-stat`

Счетчики превращаются в компактные metric surfaces с сильным числом, спокойной подписью, icon tile и hover только там, где контейнер является ссылкой. Числовой формат сохраняется.

### `x-title-card`

- Постер перестает быть отдельным tab-stop.
- Единственная ссылка заголовка покрывает карточку через overlay, а relation chips остаются отдельными доступными ссылками поверх overlay.
- Карточка получает `content-visibility`, более четкую иерархию метаданных и спокойный hover lift.
- Постер по-прежнему использует `object-contain`; внешние изображения не подменяются вымышленными.
- Пустой постер показывает только нейтральную системную заглушку.

### `x-title-list-row`, chips и status pills

Компоненты получают общий радиус, focus contract и более крупные interactive targets там, где они кликабельны. Текст остается полностью видимым и переносимым.

## Страницы

### Главная `/`

Порядок становится пользовательским:

1. Hero с H1, большой формой поиска и быстрыми реальными переходами.
2. Сводные счетчики.
3. Постерные обновления.
4. Новые серии и доступное видео.
5. Длинная лента обновлений.
6. Навигационные справочники.

На mobile основной контент остается раньше боковой навигации. Длинный список стран делится на приоритетную часть и native disclosure «Показать все страны», сохраняя доступ ко всем записям.

### Каталог `/titles`

Прямую перестройку страницы выполняет отдельная спецификация `2026-07-12-catalog-search-overhaul-design.md`, потому что она одновременно меняет поисковый контракт и mobile dialog. Текущий визуальный этап не редактирует конфликтующие участки `titles.blade.php`; страница сразу получает новый header, панели, карточки, focus и токены через общие компоненты.

### Тайтл `/titles/{slug}`

- Верхняя панель становится title hero: постер, название, ключевые показатели и главный переход к просмотру.
- Дублирующие счетчики объединяются в одну компактную metric grid.
- Описание остается полностью видимым.
- Player, сезоны, рекомендации и FAQ сохраняют текущий порядок и URL-state.
- На телефоне poster, CTA и метаданные образуют одну колонку; на tablet и desktop включается двухколоночная композиция.
- Боковые связи остаются после основного контента на mobile и компактной боковой панелью на desktop.

### Статистика `/stats`

Livewire-контракт, poll interval и payload не меняются. Страница получает новый визуальный фундамент через layout и общие панели. Глубокая информационная архитектура dashboard относится к следующему этапу, чтобы не смешивать публичный каталог и служебную аналитику.

## Данные и архитектура

- Контроллеры, Eloquent queries, importer и схема базы не меняются в визуальном этапе.
- Blade не выполняет database queries и не использует `@php`.
- Все внешние URL продолжают проходить через текущие модели/view models.
- API, player query parameters, season anchors и recommendation ranking не меняются.
- Вся видимая копия остается русской и описывает только реальные данные.

## Accessibility contract

- один понятный H1 на страницу;
- skip-link является первым focusable элементом;
- active navigation использует `aria-current="page"`;
- все icon-only элементы либо имеют текст, либо доступное имя;
- одинаковые poster/title links объединяются в один tab-stop на карточку;
- `summary`, кнопки, поля и основные ссылки имеют явный focus-visible;
- минимальная высота основных action controls — 44 px;
- цвет не является единственным признаком состояния;
- интерфейс работоспособен без анимации и без JavaScript, кроме видео/HLS enhancement.

## Responsive contract

Проверяемые ширины:

- 320×720: одна колонка, без горизонтального overflow, hero search и главное действие видны до длинных списков;
- 390×844: карточки и списки используют доступную ширину, навигация не перекрывает контент;
- 768×1024: статистика и карточки переходят в 2–3 колонки без преждевременной плотности;
- 1440×1200: sidebar и main используют `minmax(0, 1fr)`, сетка остается читаемой;
- 1920×1080: контент не растягивается до нечитабельных строк, сохраняется максимальная ширина.

## Производительность

- новые runtime-пакеты и CDN отсутствуют;
- card rendering использует `content-visibility` как progressive enhancement;
- poster loading остается lazy/async;
- CSS gradients не используют bitmap assets;
- динамический import player сохраняется;
- Vite entry point остается один;
- визуальные переходы используют только transform/opacity и отключаются при reduced motion.

## Локальные assets

- FontAwesome импортируется через core + solid + regular entrypoints; brands и v4 compatibility не попадают в build;
- неиспользуемый Latin-only Instrument Sans preload удаляется, базовый стек переключается на системный `ui-sans-serif` с кириллицей;
- `plyr/dist/plyr.svg` проходит через Vite как локальный asset и передается в `iconUrl`;
- текущий lifecycle не вызывает `player.destroy()` и не меняет `player.source`, поэтому default `blankVideo` не запрашивается; перед добавлением этих сценариев потребуется валидный локальный blank MP4;
- после build manifest и emitted assets проверяются на отсутствие `fa-brands`, `fa-v4compatibility` и font preload, а browser network log подтверждает отсутствие запросов к Plyr CDN.

## Проверки

### PHPUnit

- layout содержит skip-link и active navigation state;
- главная выводит hero search раньше счетчиков;
- title card имеет одну ссылку на страницу тайтла и доступные relation links;
- panel API, player state, season anchors и рекомендации не регрессируют;
- Blade по-прежнему не использует inline PHP и truncation utilities.
- frontend source contract не импортирует FontAwesome `all.min.css`, не настраивает Bunny Latin-only font и явно переопределяет remote defaults Plyr.

### Build

- `./vendor/bin/pint --dirty --format agent` после PHP-тестов;
- focused UI/Blade tests;
- `npm run build` после CSS/Blade изменений.

### Browser QA

Проверяются `/`, `/titles`, один тайтл с poster/media и один тайтл без poster/media на четырех viewport. Собираются status, H1, section headings, horizontal overflow, console errors, page errors, failed local assets и screenshots.

Поскольку сейчас работает длительный `seasonvar:import`, browser QA и широкий PHPUnit suite запускаются только на отдельной временной SQLite базе либо после завершения importer. Рабочая база не мигрируется и не очищается.

## Зависимости

Текущие Tailwind 4.3, Vite 8, FontAwesome 7, Plyr 3, hls.js 1.6 и Livewire 4 покрывают реализацию. Новые production packages не добавляются.

`package-lock.json` сейчас закреплен на стороннем npm mirror. В текущем этапе hostname переводится на официальный npm registry без изменения версий или integrity hashes, `.npmrc` явно фиксирует официальный registry, после чего выполняются audit и signature checks.

Возможные будущие dev-only инструменты оцениваются отдельно:

- Playwright для воспроизводимого visual regression;
- axe-core для автоматизированной части accessibility-аудита;
- bundle analyzer только при появлении проблемы размера bundle.

Их установка требует отдельного подтверждения и не является условием текущего редизайна.

## Максимальная программа развития

### Этап 1. Визуальный фундамент

Tokens, focus/reduced-motion, layout, header/footer, panels, metrics, title cards, главная и title hero. Этот этап реализуется сейчас.

### Этап 2. Поиск и каталог

FTS5-контракт, честные состояния, relevance, mobile filter dialog, compact result rows, combobox людей и typo suggestions по существующей search-спецификации.

### Этап 3. Playback workspace

Крупный TV-friendly выбор сезона/серии, sticky now-playing summary на широких экранах, сохранение выбранного перевода и качества в URL, keyboard QA и медиасостояния без скачивания файлов.

### Этап 4. Recommendations explorer

Объяснимые reason chips, более плотная адаптивная сетка, fallback quality metrics и визуальная проверка отсутствия дублей/current title.

### Этап 5. Service dashboard

Перекомпоновка `/stats` вокруг health summary, issues first, disclosure для длинных технических секций и responsive data cards вместо широких таблиц.

### Этап 6. Media delivery

Безопасный poster proxy для публичных карточек, responsive `srcset`, размеры изображений, negative cache и наблюдение за ошибками внешних источников. Видео по-прежнему не скачивается.

### Этап 7. Navigation intelligence

Реальные recently viewed titles в локальном browser storage, продолжение просмотра по URL-state и быстрые переходы. Функция остается локальной, без аккаунтов и без fake recommendations.

### Этап 8. Quality gates

Playwright matrix, accessibility snapshots, visual baselines, performance budgets, bundle report и CI checks для Blade/CSS regression.

### Этап 9. PWA-readiness

Manifest, installable shell и offline-safe navigation chrome без кэширования внешнего видео. Реализация только после измерения пользы и отдельного security/cache design.

## Не входит в текущий этап

- изменение поиска, importer, API или схемы базы;
- установка production packages;
- темная тема;
- аккаунты, отзывы или write endpoints;
- скачивание poster/video assets;
- fake content, рекламные секции и декоративные изображения;
- миграция публичного каталога на SPA.
