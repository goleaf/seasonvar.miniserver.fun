# Frontend

Обновлено: 13.07.2026

## Стек

- Vite 8 и `laravel-vite-plugin` 3 собирают фронтенд.
- Tailwind CSS 4 подключается через `@tailwindcss/vite` и `resources/css/app.css`.
- FontAwesome, Plyr и HLS подключаются из локальных npm-пакетов, без CDN.
- Livewire 4 используется для интерактивного каталога `/titles`, одиннадцати directory hubs, полной динамической оболочки и playback-island карточки `/titles/{slug}`, личной страницы `/watching` и live-страницы `/stats`; styles/scripts подключаются layout один раз на всех routes и не дублируются в компонентах.
- Volt не установлен и не используется. Все Livewire-компоненты conventional class-based, а Blade остаётся presentation-only без PHP tags, database/cache/service calls.

## Граница текущего продукта

Локализованные записи контента и QoE telemetry отсутствуют как продуктовые возможности, а не являются незавершёнными frontend-задачами. Текущая locale переводит только UI/page metadata и не создаёт отдельные версии названий, описаний, аудио или субтитров; player показывает bounded локальные состояния без отправки пользовательской телеметрии качества воспроизведения. Добавление таких возможностей требует отдельных schema/privacy/retention contracts и измеримого rollout, а не скрытого JavaScript-сбора.

## Browser baseline 13.07.2026

Read-only проверка выполнена в Chromium на desktop `1440×1200`, tablet `768×1024` и mobile `390×844`. Для визуальных маршрутов проверялись HTTP status, итоговый URL, `h1`, landmarks, горизонтальное переполнение, изображения, alt-текст, duplicate IDs, доступные имена controls, console/page errors и полноэкранные снимки. Интерактивные сценарии повторно запускались на `https://seasonvar.miniserver.fun`, чтобы production secure-cookie и Livewire middleware совпадали с реальным окружением.

Покрытые поверхности:

- `/`, `/titles`, `/titles/year/{year}`, все действующие варианты `/titles/{type}/{taxonomy}` и `/titles/{slug}`;
- `/actors`, `/age-ratings`, `/countries`, `/directors`, `/genres`, `/networks`, `/statuses`, `/studios`, `/tags`, `/translations`, `/years` и их detail redirects;
- `/stats`, гостевые и авторизованные варианты `/watching`, `/admin/catalog`, `/admin/imports`;
- ожидаемые ошибки неизвестной taxonomy, неподписанного `/playback/{licensedMedia}`, неизвестного web path, API path, health и machine-readable endpoints.

Результат browser-проверки:

- все доступные публичные страницы вернули `200`, канонические directory redirects завершились рабочей выдачей, неизвестные taxonomy — `404`, а guest-only authorization probes — ожидаемым `403`;
- ни одна проверенная продуктовая страница не вышла за ширину viewport;
- production Livewire requests для сортировки/фильтра каталога, directory search, background refresh карточки и `wire:poll` статистики вернули `200` без console/page errors;
- единый блок «Точный подбор» раскрывается на desktop/mobile без внутренней прокрутки; применение реального checkbox изменило выдачу с 24 карточек до одной;
- временная изолированная SQLite-сессия подтвердила authorized render `/watching`, `/admin/catalog` и `/admin/imports` на desktop/mobile без обращения к рабочим данным;
- HTML `/stats` имеет размер около `950 778` байт; измеренная высота составила `82 101 px` на desktop, `100 114 px` на tablet и `144 558 px` на mobile. Это текущий главный frontend performance/IA risk;
- главная имеет измеренную высоту `6 753 / 11 972 / 14 040 px`, каталог `7 645 / 11 131 / 9 939 px`, карточка тайтла `7 730 / 10 909 / 11 662 px` для desktop/tablet/mobile. Эти значения являются диагностическим snapshot реальных данных, а не постоянным pixel contract.

Локальный HTTP-сервер с production hostname может давать `419` на Livewire из-за `Secure` session cookie. Это не следует маскировать в приложении: для достоверного runtime QA использовать реальный HTTPS или отдельный testing `APP_URL`/session config и временную SQLite базу. Внешнее видео во время автоматического route-аудита блокируется; проверяется shell плеера, signed boundary и существующие PHPUnit-контракты, а не скачивание media.

Детальная матрица и план улучшений: [`superpowers/plans/2026-07-13-seasonvar-ui-evolution.md`](superpowers/plans/2026-07-13-seasonvar-ui-evolution.md).

## Команды

```bash
npm install
npm run dev
npm run build
composer dev
```

- `npm install` нужен только после изменения `package.json` или `package-lock.json`.
- `npm run dev` запускает только Vite.
- `composer dev` запускает Laravel server, queue listener, logs и Vite вместе.
- `npm run build` обязателен после изменений `vite.config.js`, `resources/js`, `resources/css`, Blade asset usage или npm-зависимостей.

## Asset rules

- Основная точка входа Vite одна: `resources/js/app.js`.
- `resources/js/app.js` импортирует `resources/css/app.css` и глобальные стили FontAwesome.
- Player-код для Plyr/HLS находится в `resources/js/player.js` и загружается dynamic import только на страницах с `video.js-catalog-player`.
- Player создаёт одну guarded browser-session на точный `title:episode:media` source, восстанавливает позицию после metadata load и отправляет bounded progress только для authenticated markup: первый `play` создаёт start event, 30-секундный heartbeat работает лишь во время воспроизведения, а pause, stable seek, hidden visibility, navigation, pagehide и ended принудительно фиксируют позицию. Каждый event несёт opaque server-issued progress token и возрастающий sequence; browser duration остаётся только sanity signal и не становится trusted duration. `AbortController` освобождает listeners/timers/Plyr/HLS, generation token отменяет stale async-init, а cleanup очищает и media node, восстановленный `Plyr.destroy()`.
- Состояния loading, buffering, automatic retry, expired/unavailable source и fatal error отображаются фиксированным русским текстом рядом с `wire:ignore` player island. Provider URL, exception text и raw media errors в status region не выводятся.
- Поиск актёров и режиссёров выполняется read-only API combobox из `resources/js/app.js`: debounce 300 мс, `AbortController` для stale request, максимум 20 результатов, клавиши Arrow Up/Down, Enter и Escape. Результат добавляет slug в обычный URL, поэтому выбранное состояние и GET-фильтрация не зависят от публичных model IDs. Локальный поиск для остальных длинных групп остаётся progressive enhancement по уже загруженному ограниченному списку.
- Header search остаётся видимым на всех публичных routes, включая `/titles`, `/titles/year/{year}` и taxonomy listing pages. Локальная поисковая форма каталога находится над результатами и имеет отдельное доступное имя `Поиск по каталогу` или `Искать в выбранной подборке`, поэтому на listing routes допустимы два разных search landmarks без дублирования input IDs. Один полноширинный `<details id="catalog-filters">` расположен между панелью управления и результатами; sidebar/dialog отсутствуют. Первый HTML содержит карточки, расширенные поля и нейтральный placeholder, после чего отдельный sibling deferred island автоматически подгружает годы и справочники без отправки сотен options в initial payload.
- Серверное состояние `/titles` ведёт `CatalogSeries`: вычисляемые `catalogPage` и `catalogFacets` разделяют быстрые результаты и contextual facets. Eager island результатов и deferred island фильтров имеют общее имя `catalog-live`, поэтому checkbox/select с `wire:model.live` атомарно обновляют выбранные состояния, счётчики и карточки. Строка поиска и числовые диапазоны применяются по submit, сортировка, вид, размер страницы, алфавит и пагинация — отдельными Livewire actions. Для форм сохранён обычный GET/`noscript` fallback; malformed и out-of-range `page` канонизируется redirect-ом, чтобы адресная строка не сохраняла stale границу.
- `CatalogDirectoryBrowser` хранит locked string directory и нормализованные URL scalars: `q` (NFKC/squish, максимум 80), `letter`, allowlisted `sort=name_asc|count_desc`, optional decade и Livewire paginator. Search использует `wire:model.live.debounce.400ms`, каждое изменение фильтра сбрасывает page, а `#[Url(history: true)]` восстанавливает refresh/back/forward. Render-local paginator и навигационные collections не входят в snapshot.
- «Точный подбор» объединяет `year`, публикацию, субтитры, справочники, `year_*`/`updated`, `seasons_*`/`episodes_*`, `rating_*`/`votes_min` и `video`/`quality` без изменения query keys. Общая форма исключает дублирование видимых query-параметров, summary считает все условия и раскрывается при любом активном фильтре; «Сбросить фильтры» использует существующий `resetAll`. Мобильная панель выдачи переиспользует `setView`/`setPerPage` и те же query builders, что desktop, без нового client state.
- `CatalogTitlePlayer` использует scoped Livewire loading states для смены media-варианта: `selectMedia` подсвечивает только player island, варианты просмотра и список серий. Ссылки вариантов и серий сохраняют обычный `href` fallback и обновляют URL-профиль `variant`/`quality`/`format`.
- `CatalogTitleDetail` оставляет начальный SSR без queue side effects, запускает проверку свежести только через browser `wire:init="startRefresh"`, а во время активного targeted refresh обновляет всю видимую оболочку через `wire:poll.3s.visible="refreshCatalog"`. После `completed` или `failed` poll-атрибут исчезает. Каждый poll отправляет scoped событие вложенному `CatalogTitlePlayer`, который очищает только render-кэши и сохраняет валидные `season`/`episode`/`media`/profile URL-параметры.
- `/watching` не сериализует Eloquent collections в публичное состояние: Continue Watching и paginator истории строятся только внутри render. Удаление использует `wire:confirm`, полная очистка — `wire:confirm.prompt`, а `historyPage` остаётся отдельным URL-параметром Livewire pagination.
- `/admin/imports` опрашивает сервер через `wire:poll.5s.visible` только пока есть active run. После terminal state poll-attribute исчезает; run models и collections не хранятся в public snapshot, а rows имеют stable `wire:key`.
- Для HLS используется `hls.js/light`: он сохраняет воспроизведение HLS-плейлистов и не тянет модули субтитров, DRM и расширенной аналитики, которые сейчас не используются интерфейсом.
- Layout подключает ассеты через `@vite('resources/js/app.js')`; не добавлять raw `<script>`/`<style>` для обычных assets.
- Layout также содержит `@livewireStyles` и `@livewireScripts`; не дублировать Livewire/Alpine через CDN или отдельный npm-bundle.
- Интерфейс использует системный стек шрифтов с поддержкой кириллицы; внешний font bundle и `Vite::fonts()` не подключаются.
- FontAwesome собирается из локальных `fontawesome.min.css`, `solid.min.css` и `regular.min.css`; brands/v4 font-файлы не входят в bundle.
- Blade-компонент `x-ui.icon` является единственной границей прямой FontAwesome-разметки: он добавляет декоративную семантику, стабильный responsive box в `em`, запрет flex-сжатия и вариант `align="start"` для первой строки многострочного текста. Архитектурный тест не допускает возврат сырых `<i>` в шаблоны.
- Plyr получает локальный Vite URL из `resources/images/plyr.svg`, а HLS-код загружается только когда браузеру действительно нужен `hls.js/light`.
- Проектная pagination переопределена в `resources/views/vendor/pagination/tailwind.blade.php`: весь текст русский, тема только светлая, элементы управления имеют высоту не менее 44 пикселей.
- Livewire pagination каталога переопределена в `resources/views/vendor/livewire/tailwind.blade.php`: русские подписи, стабильные `wire:key`, светлая тема и элементы управления не менее 44 пикселей. Нажатие активной кнопки плавно возвращает к `data-catalog-results` с учетом sticky header и `prefers-reduced-motion`.
- Все каталожные изображения выводит `x-ui.poster-frame`: абсолютный `object-cover` постер с 2% overscan заполняет frame без верхней полосы, а заглушка сохраняет те же размеры. `x-ui.poster-card` владеет единственным внешним border/rounding/clipping и поддерживает только `grid`, `horizontal`, `compact`; обычные коллекции `CatalogTitle` используют query-free `x-catalog.title-card`.
- Grid-карточка на телефоне остаётся горизонтальной для быстрого сканирования и становится вертикальной с постером 2:3 начиная с `sm`; horizontal/compact используют явную портретную колонку и `minmax(0,1fr)` для полностью переносимого текста.
- Страница сериала вызывает `x-ui.poster-frame` напрямую для SEO-критичного eager-постера. Video player сохраняет нативный `poster`-атрибут; защищённые миниатюры `/stats` получают только уже проверенный same-origin proxy URL.
- Основная зона видео занимает всю ширину playback-панели; блок «Ваш сериал» расположен следующей адаптивной строкой под плеером.
- Новые пользовательские строки Blade/Livewire добавляются в `lang/{locale}/catalog.php`; plural counts выводятся через `trans_choice()`. Интерфейсная locale управляет только UI и page metadata и не подменяет перевод, язык аудио или субтитров.
- Essential title metadata остаётся в первом server-rendered HTML через `CatalogTitleDetail`. `CatalogShowViewModel` передаёт presentation-safe plain text; canonical и Open Graph по-прежнему готовит controller SEO shell, а вложенный Livewire player не становится их источником.
- Ссылки сортировки, вида, размера страницы, алфавита, фильтров и быстрого доступа используют плоские состояния без декоративной border/ring-обводки и сохраняют touch-target не менее 44 пикселей. Рамки остаются у форм, alert, player/media frames и структурных карточек.
- Общий `focus-visible` определен в `resources/css/app.css`; составной `x-form.search-field` и локальные поиски фильтров используют `data-focus-frame`, чтобы клавиатурный focus охватывал весь control без двойного контура.
- Глобальный cursor affordance также задан в `resources/css/app.css`: ссылки, кнопки, summary, связанные label, select/input controls, `[role="button"]` и `[data-catalog-history]` получают pointer, disabled/aria-disabled controls — not-allowed, scoped loading nodes — wait.
- Проверка responsive player должна подтверждать, что смена viewport не заменяет существующий `video.js-catalog-player`: player island остается под `wire:ignore`, а lifecycle-cleanup срабатывает только при смене выпуска или навигации.
- Blade не должен содержать `@php`/`@endphp` или asset-логику на PHP; используйте Laravel/Vite helpers и конфигурацию Vite.
- Livewire cached/persisted computed, Session properties, lazy/defer/isolate/Islands, async, Teleport и stream не включаются ради демонстрации API. Текущие SEO-critical оболочки должны быть в первом HTML, mutations синхронны с UI, а domain cache и polling имеют собственную точную invalidation/visibility политику.
