# Frontend

Обновлено: 16.07.2026

## Стек

- Vite 8 и `laravel-vite-plugin` 3 собирают фронтенд.
- Tailwind CSS 4 подключается через `@tailwindcss/vite` и `resources/css/app.css`.
- FontAwesome, Plyr и HLS подключаются из локальных npm-пакетов, без CDN.
- Livewire 4 используется для интерактивного каталога `/titles`, одиннадцати directory hubs, полной динамической оболочки и playback-island карточки `/titles/{slug}`, регистрации/входа, профиля, безопасности, личной библиотеки `/library/*` и live-страницы `/stats`; styles/scripts подключаются layout один раз на всех routes и не дублируются в компонентах.
- Volt не установлен и не используется. Все Livewire-компоненты conventional class-based, а Blade остаётся presentation-only без PHP tags, database/cache/service calls.

## Locale lifecycle и главная страница

- Публичный header, главная и доменные редакторы не содержат language switcher, RU/EN-кнопок или подписи текущего языка. Единственный интерфейс выбора locale находится в профиле, в разделе настроек внешнего вида `#settings-locale`; прежний POST `/interface-locale` удалён. Прямые `/{locale}` aliases и сохранённая account/session preference продолжают определять язык интерфейса, но не создают второй control.
- Главная SSR использует `home.*` для statistics, latest updates, new episodes/media, watchable titles, discovery navigation, countries/genres/years, empty/loading/error/end copy и accessibility labels. Recommendation/update/filter decisions остаются стабильными enum/code values; Blade переводит только labels.
- `CatalogTitle` не имеет translation relation, поэтому карточка показывает существующий `display_title`, optional original title и provider metadata без автоматического перевода. Featured collection summary загружает только active/fallback translation rows. Audio translation/studio name остаётся брендом/данными источника; quality/format и их accessible context разделены от interface language.
- Counts используют locale-aware `Number::format` и Laravel plural rules. Dates используют `AccountDateTimeFormatter`, active locale и account/default timezone; hardcoded `d.m.Y` на главной отсутствует. Layout допускает длинные labels через wrap/min-width rules; текущие `ru`/`en` LTR, полная RTL-поддержка не заявляется.
- Homepage sections не являются отдельными Livewire components. Если layout или дочерний component выполняет Livewire update, `ApplyAccountPreferences` повторно устанавливает validated session locale до hydration; locale не дублируется mutable public property в каждой секции.

Датированные доказательства и незакрытые gaps находятся в [`audits/frontend-report.md`](audits/frontend-report.md), [`audits/livewire-report.md`](audits/livewire-report.md) и [`audits/video-playback-report.md`](audits/video-playback-report.md). Текущий переходный gap: Blade не содержит PHP/query/service calls, но header/footer/layout всё ещё используют route-aware `request()` и один template читает `config()`; living plan переносит эти решения в prepared view state.

## Глобальный поиск в шапке

`x-layout.header-search` остаётся обычной GET-формой `/search`, а `resources/js/header-search.js` прогрессивно добавляет два независимых API-контура. Debounce — 160 мс; предыдущие запросы отменяются раздельно, stale sequence игнорируется, title cards рендерятся до завершения portal scope. DOM строится только через `createElement`/`textContent`; provider HTML не вставляется. Вкладка хранит не более 120 scope/query responses. Оба fetch передают allowlisted `Accept-Language` из текущего `<html lang>`; API формирует year/season/episode metadata через `trans_choice()`, поэтому карточка не смешивает locale браузера и SSR-интерфейса.

Dropdown ограничивает количество строк по доступной высоте и ширине: `2/3` title/portal при высоте меньше 720 px, `3/4` на телефоне, `4/6` на планшете и `5/8` на desktop/TV. Он не получает собственного scroll-container, остаётся внутри viewport, сохраняет touch targets не меньше 44 px и доступен через combobox/listbox, стрелки, Enter и Escape. Listbox содержит только доступные groups/options: визуальные заголовки групп имеют `aria-hidden`, `role="group"` получает собственный label, а live-status находится рядом с listbox, а не внутри него. Рамка `#site-search` остаётся нейтральной при hover/focus/input/loading/open согласно [`UI_STANDARDS.md`](UI_STANDARDS.md).

## Video delivery contract

`Page/API → entitlement → episode/media resolver → short-lived signed viewer grant → delivery reauthorization → allowlisted HTTPS redirect → provider/CDN → native video/Plyr/HLS.js → throttled progress service`.

Обычный playback не проксирует video bytes: `206`, `Accept-Ranges`, MIME, CORS и media cache принадлежат authorized provider/CDN. Отдельный authenticated attachment route является узким исключением для direct-file download и end-to-end single-range resume; он не меняет player redirect architecture, не раскрывает raw upstream URL и не сохраняет video body. Normal CI использует deterministic local/mocked media fixtures, а real-provider checks остаются optional operational checks.

## Граница текущего продукта

Локализованные записи контента и QoE telemetry отсутствуют как продуктовые возможности, а не являются незавершёнными frontend-задачами. Текущая locale переводит только UI/page metadata и не создаёт отдельные версии названий, описаний, аудио или субтитров; player показывает bounded локальные состояния без отправки пользовательской телеметрии качества воспроизведения. Добавление таких возможностей требует отдельных schema/privacy/retention contracts и измеримого rollout, а не скрытого JavaScript-сбора.

Mobile-клиент создаёт playback session через API и использует выданный same-origin `playback_url` как opaque URL: он следует разрешённому redirect/HLS-потоку, не извлекает и не сохраняет provider URL и не добавляет Bearer token в query. Для progress клиент хранит отдельный `progress_session_token` только на время просмотра и отправляет возрастающий `event_sequence`; server response остаётся единственным каноническим состоянием позиции. Web Plyr/Livewire продолжает использовать существующий signed `/playback/{licensedMedia}` и этим mobile contract не заменяется.

## Пользовательский портал

- `/register`, `/login`, `/forgot-password` и `/reset-password/{token}` — гостевые full-page Livewire-компоненты с русской валидацией. После входа доступны `/email/verify`, `/confirm-password`, `/profile`, `/profile/security` и `/library/*`; layout показывает ссылки по фактическому session state и использует Livewire logout action.
- `/profile` изменяет имя/email и выводит сводку библиотеки. `/profile/security` меняет пароль, owner-scoped отзывает mobile devices, завершает остальные database sessions и удаляет аккаунт только после явного password confirmation; plaintext tokens и hash никогда не попадают в markup или Livewire snapshot.
- `/library/watchlist`, `/library/ratings`, `/library/continue-watching` и `/library/history` используют один `UserLibraryPage`. Раздел зафиксирован route parameter, а нормализованные `q`, `type`, `year`, `sort`, `direction` и отдельные `watchlistPage`, `ratingsPage`, `historyPage` сохраняются в URL. Модели и paginator не хранятся в public Livewire state.
- Личная библиотека читается unverified пользователем, но mutation controls отображаются только при `canInteract`; server-side policies всё равно повторяют verified/entitlement boundary. `/watching` сохраняется только как redirect на `/library/continue-watching`.
- Главная, каталог и рекомендации получают личные признаки карточек через bounded eager loader до Blade render: watchlist, rating, progress и primary action не выполняют запросы из `x-catalog.title-card` и не попадают в guest shared cache.

## Frontend lifecycle аутентификации

Auth pages остаются обычными SSR/Livewire страницами: component хранит только typed scalar draft, service выполняет credential/domain action, а Blade выводит prepared URLs/flags/translations. `wire:loading` отключает только active submit и объявляет localized status; validation связывает error с полем. Password очищается после каждого validation/credential outcome; reset token живёт только в scoped reset-form до broker action и не попадает в metadata/storage/log. Verification token, session ID, redirect payload и complete user model не сериализуются в public state или JavaScript.

Канонические RU routes и `/en/...` aliases используют те же components; locale переживает login/register/recovery/reset/verification через allowlisted server state, не через arbitrary callback query. Authentication pages задают `noindex,nofollow`, отключают social/JSON-LD/alternates и не помещают token в canonical. `AppLayoutData` готовит header login/register actions; registration link исчезает вместе с disabled route, а logout остаётся в той же верхней полосе, что и search.

Manual read-only Chromium smoke 16.07.2026 проверил login/register/recovery/reset на desktop `1440×1200` и mobile `390×844`: HTTP 200, RU/EN `<html lang>`/headings, noindex, labels/autocomplete, 44px submit target, отсутствие horizontal overflow, console/page/request errors. Снимки находятся в ignored `output/playwright/task15-auth/`; runtime accounts/forms не изменялись.

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
- Player создаёт одну guarded browser-session на точный `title:episode:media` source, восстанавливает позицию после metadata load и отправляет bounded progress только для verified authenticated markup после реального события `play`: обычное открытие страницы и lifecycle cleanup не могут сбросить сохранённую позицию в ноль. Первый `play` создаёт start event, 30-секундный heartbeat работает лишь во время воспроизведения, а pause, stable seek, hidden visibility, navigation, pagehide и ended принудительно фиксируют позицию уже начатой browser-session. Unverified пользователь видит предложение подтвердить email, а progress отправка для него отключена. Каждый разрешённый event несёт opaque server-issued progress token и возрастающий sequence; browser duration остаётся только sanity signal и не становится trusted duration. `AbortController` освобождает listeners/timers/Plyr/HLS, generation token отменяет stale async-init, а cleanup очищает и media node, восстановленный `Plyr.destroy()`.
- `CatalogPlayerCopy` формирует allowlisted JSON из semantic `catalog.player.runtime.*` и всех scalar Plyr `catalog.player.controls.*` ключей активной `ru`/`en` locale. Blade передаёт его через escaped `data-player-copy` внутрь того же `wire:ignore` island; JavaScript не выполняет translation lookup и не содержит языкового fallback-текста. Locale приходит из серверного render и поэтому сохраняется после Livewire hydration/navigation так же, как остальная страница. Provider URL, exception text, raw media errors и missing translation keys в status region не выводятся.
- Одна запись `WeakMap` на точный media shell владеет Plyr, HLS, listeners и timers. Source replacement, `livewire:navigating`, `pagehide` и удаление island вызывают единый cleanup; resize и повторный `livewire:navigated` не создают второй session. Fatal HLS network retry создаёт новый HLS instance, terminal/manual retry отменяют устаревший timer, а native HLS остаётся fallback только для браузера без HLS.js/MSE support. Ошибка существующего `<track>` показывает отдельное локализованное polite-предупреждение и не делает video fatal; production subtitle-track relation/editor по-прежнему отсутствуют.
- Поиск актёров и режиссёров выполняется read-only API combobox из `resources/js/app.js`: debounce 300 мс, `AbortController` для stale request, максимум 20 результатов, клавиши Arrow Up/Down, Enter и Escape. Результат добавляет slug в обычный URL, поэтому выбранное состояние и GET-фильтрация не зависят от публичных model IDs. Локальный поиск для остальных длинных групп остаётся progressive enhancement по уже загруженному ограниченному списку.
- Header search остаётся видимым на всех публичных routes, включая `/titles`, `/titles/year/{year}` и taxonomy listing pages. Первая полоса header содержит только бренд и гибкий progressive API-поиск, вторая — всю навигацию с `flex-wrap`; подписи ссылок визуально скрыты до `sm`, но доступные имена сохраняются. После двух символов и debounce 160 мс поиск параллельно запрашивает до пяти богатых карточек тайтлов и bounded public-only структуру портала без внутреннего scroll; `combobox`/`listbox`, Arrow Up/Down, Enter, Escape, click-outside и 44px targets работают на touch и клавиатуре. Обычная `GET /search`-форма сохраняется без JavaScript, а временная ошибка подсказок не блокирует submit. Модуль повторно инициализируется после `livewire:navigated` через `WeakSet` без дублирования listeners. Playwright проверяет выбор клавиатурой и геометрию двух полос на ширинах 375, 768, 1280 и 1920 px. Локальная поисковая форма каталога находится над результатами и имеет отдельное доступное имя `Поиск по каталогу` или `Искать в выбранной подборке`, поэтому на listing routes допустимы два разных search landmarks без дублирования input IDs. Один полноширинный `<details id="catalog-filters">` расположен между панелью управления и результатами; sidebar/dialog отсутствуют. Первый HTML содержит карточки, расширенные поля и нейтральный placeholder, после чего отдельный sibling deferred island автоматически подгружает годы и справочники без отправки сотен options в initial payload.
- Серверное состояние `/titles` ведёт `CatalogSeries`: вычисляемые `catalogPage` и `catalogFacets` разделяют быстрые результаты и contextual facets. Eager island результатов и deferred island фильтров имеют общее имя `catalog-live`, поэтому checkbox/select с `wire:model.live` атомарно обновляют выбранные состояния, счётчики и строки. Строка поиска и числовые диапазоны применяются по submit, сортировка, размер страницы, алфавит и пагинация — отдельными Livewire actions. Параметр и action `view` отсутствуют; legacy `view` не попадает в нормализованное состояние. Для форм сохранён обычный GET/`noscript` fallback; malformed и out-of-range `page` канонизируется redirect-ом, чтобы адресная строка не сохраняла stale границу.
- `CatalogDirectoryBrowser` хранит locked string directory и нормализованные URL scalars: `q` (NFKC/squish, максимум 80), `letter`, allowlisted `sort=name_asc|count_desc`, optional decade и Livewire paginator. Search использует `wire:model.live.debounce.400ms`, каждое изменение фильтра сбрасывает page, а `#[Url(history: true)]` восстанавливает refresh/back/forward. Render-local paginator и навигационные collections не входят в snapshot.
- «Точный подбор» объединяет `year`, публикацию, субтитры, справочники, `year_*`/`updated`, `seasons_*`/`episodes_*`, `rating_*`/`votes_min` и `video`/`quality` без изменения query keys. Общая форма исключает дублирование видимых query-параметров, summary считает все условия и раскрывается при любом активном фильтре; «Сбросить фильтры» использует существующий `resetAll`. Мобильная панель выдачи переиспользует `setPerPage` и те же query builders, что desktop, без отдельного client state представления.
- `CatalogTitlePlayer` использует scoped Livewire loading states для смены media-варианта: `selectMedia` подсвечивает только player island, варианты просмотра и список серий. Ссылки вариантов и серий сохраняют обычный `href` fallback и обновляют URL-профиль `variant`/`quality`/`format`.
- `CatalogTitleDetail` оставляет начальный SSR без queue side effects, запускает проверку свежести только через browser `wire:init="startRefresh"`, а во время активного targeted refresh обновляет всю видимую оболочку через `wire:poll.3s.visible="refreshCatalog"`. После `completed` или `failed` poll-атрибут исчезает. Каждый poll отправляет scoped событие вложенному `CatalogTitlePlayer`, который очищает только render-кэши и сохраняет валидные `season`/`episode`/`media`/profile URL-параметры.
- `/library/*` не сериализует Eloquent collections в публичное состояние: списки, Continue Watching и paginator истории строятся только внутри render. Удаление использует `wire:confirm`, полная очистка — `wire:confirm.prompt`, а каждый пагинируемый раздел имеет отдельный URL-параметр.
- `/admin/imports` опрашивает сервер через `wire:poll.5s.visible` только пока есть active run. После terminal state poll-attribute исчезает; run models и collections не хранятся в public snapshot, а rows имеют stable `wire:key`.
- Для HLS используется `hls.js/light`: HLS URL хранится только в `data-hls-src`, чтобы Chromium не начинал параллельную native-загрузку из `<source>`. Поддерживаемый HLS.js/MSE browser получает одну управляемую instance; native `video.src` применяется только как fallback, если HLS.js не поддерживается. Light bundle не тянет модули субтитров, DRM и расширенной аналитики, которые сейчас не используются интерфейсом.
- Layout подключает ассеты через `@vite('resources/js/app.js')`; не добавлять raw `<script>`/`<style>` для обычных assets.
- Layout также содержит `@livewireStyles` и `@livewireScripts`; не дублировать Livewire/Alpine через CDN или отдельный npm-bundle.
- Интерфейс использует системный стек шрифтов с поддержкой кириллицы; внешний font bundle и `Vite::fonts()` не подключаются.
- FontAwesome собирается из локальных `fontawesome.min.css`, `solid.min.css` и `regular.min.css`; brands/v4 font-файлы не входят в bundle.
- Blade-компонент `x-ui.icon` является единственной границей прямой FontAwesome-разметки: он добавляет декоративную семантику, стабильный responsive box в `em`, запрет flex-сжатия и вариант `align="start"` для первой строки многострочного текста. Архитектурный тест не допускает возврат сырых `<i>` в шаблоны.
- Plyr получает локальный Vite URL из `resources/images/plyr.svg`, а HLS-код загружается только когда браузеру действительно нужен `hls.js/light`.
- Проектная pagination переопределена в `resources/views/vendor/pagination/tailwind.blade.php`: весь текст русский, тема только светлая, элементы управления имеют высоту не менее 44 пикселей.
- Общая Livewire pagination переопределена в `resources/views/vendor/livewire/tailwind.blade.php`: русские подписи, стабильные `wire:key`, светлая тема и элементы управления не менее 44 пикселей. Активные элементы остаются реальными ссылками на paginator URL и усиливаются через `wire:click.prevent`, поэтому GET fallback сохраняет фильтры при недоступном JavaScript. Каждый caller передаёт собственный `scrollTo`; после успешного root `morphed` или island `island.morphed` общий handler плавно возвращает к `[data-catalog-results]`, `[data-directory-results]`, `[data-viewing-history-results]` или `[data-admin-catalog-results]`, учитывает sticky header и отключает анимацию при `prefers-reduced-motion`.
- Все каталожные изображения выводит `x-ui.poster-frame` с явным `fit`. Контентные строки используют `2:3`, `object-contain` и отключённый overscan, поэтому весь постер виден на телефоне, планшете и desktop; cover остаётся только у главного постера и технических миниатюр, где он запрошен явно. `x-ui.poster-card` поддерживает `list`, `compact`, `recommendation`, `stats`; обычные коллекции `CatalogTitle` используют query-free `x-catalog.title-card`.
- `list` использует одну DOM-строку с портретной колонкой шириной `4rem` / `5rem` / `6rem` на base / `sm` / `md` и `minmax(0,1fr)` для полностью переносимого текста. Главная, `/titles`, directory hubs, рекомендации и `/library/*` не создают многоколоночных content grids.
- Страница сериала вызывает `x-ui.poster-frame` напрямую для SEO-критичного eager-постера. Video player сохраняет нативный `poster`-атрибут; защищённые миниатюры `/stats` получают только уже проверенный same-origin proxy URL.
- Основная зона видео занимает всю ширину playback-панели; блок «Ваш сериал» расположен следующей адаптивной строкой под плеером.
- Новые пользовательские строки Blade/Livewire добавляются в `lang/{locale}/catalog.php`; plural counts выводятся через `trans_choice()`. Интерфейсная locale управляет только UI и page metadata и не подменяет перевод, язык аудио или субтитров.
- Essential title metadata остаётся в первом server-rendered HTML через `CatalogTitleDetail`. `CatalogShowViewModel` передаёт presentation-safe plain text; canonical и Open Graph по-прежнему готовит controller SEO shell, а вложенный Livewire player не становится их источником.
- Ссылки сортировки, размера страницы, алфавита, фильтров и быстрого доступа используют плоские состояния без декоративной border/ring-обводки и сохраняют touch-target не менее 44 пикселей. Рамки остаются у форм, alert, player/media frames и структурных контейнеров списков.
- Общий `focus-visible` определен в `resources/css/app.css`; составной `x-form.search-field` и локальные поиски фильтров используют `data-focus-frame`, чтобы клавиатурный focus охватывал весь control без двойного контура.
- Глобальный cursor affordance также задан в `resources/css/app.css`: ссылки, кнопки, summary, связанные label, select/input controls, `[role="button"]` и `[data-catalog-history]` получают pointer, disabled/aria-disabled controls — not-allowed, scoped loading nodes — wait.
- Проверка responsive player должна подтверждать, что смена viewport не заменяет существующий `video.js-catalog-player`: player island остается под `wire:ignore`, а lifecycle-cleanup срабатывает только при смене выпуска или навигации.
- Blade не должен содержать `@php`/`@endphp` или asset-логику на PHP; используйте Laravel/Vite helpers и конфигурацию Vite.
- Livewire cached/persisted computed, Session properties, lazy/defer/isolate/Islands, async, Teleport и stream не включаются ради демонстрации API. Текущие SEO-critical оболочки должны быть в первом HTML, mutations синхронны с UI, а domain cache и polling имеют собственную точную invalidation/visibility политику.

## Frontend lifecycle коллекций

Directory, dashboard, editor, public/private/unlisted page, owner profile, title membership и admin queue используют Livewire 4 без Volt. Public properties — normalized scalar URL state, bounded draft UUIDs и locked identities/version; Eloquent graphs существуют только внутри render. Search/filter/sort/pagination используют browser history. Редакторская подборка всегда открывает и сохраняет только русскую translation row; языкового состояния и переключателя в редакторе нет, существующие English rows не удаляются.

`resources/js/collections.js` подключён через общий Vite entry и после Livewire navigation/morph повторно инициализирует только dialog/share affordances. Native dialog/sheet сохраняет trigger focus, поддерживает Escape/cancel и не записывает staged membership. Share предпочитает Web Share API, затем clipboard, никогда не включает private URL или user-specific query. Up/down buttons — keyboard/touch reordering baseline; hover и drag-and-drop не обязательны. Create/apply/upload/delete/report/moderation controls используют адресные `wire:loading` labels, spinner и disabled-state; небольшая mutation не скрывает остальные данные страницы. Layout использует существующие light panels, list title cards, 44px controls, wrapping text и responsive grid только для structural forms/cards.

## Frontend lifecycle обсуждений

`CommentDiscussion` — class-based Livewire 4 component без Volt. Public mutable state ограничен body/form/URL scalars; target identity, locale, submission tokens, edit version и prepared reveal/expand IDs locked. Render каждый раз повторно разрешает target и получает paginator/DTO из `CommentDiscussionQuery`; Eloquent graph не сериализуется. `discussion_scope`, `discussion_sort`, `comments_page`, `thread`, `comment` поддерживают refresh/back/forward и являются единственными query keys, которые locale-link enhancement переносит между `ru/en` collection routes.

Replies загружаются одним chronological batch только для раскрытого root, затем `load more` увеличивает controlled limit; poll/recursive nested components отсутствуют. Spoiler/long body reveal выполняются server-side. Composer/reply/edit/report сохраняют text на recoverable error и очищаются только после success; scoped `wire:loading` не blank-ит discussion.

`resources/js/comments.js` подключён через `resources/js/app.js` и отвечает только за native dialog open/cancel/focus return, editor focus, focused-anchor scroll/highlight, locale URL state, локальное отображение Unicode-длины textarea и `prefers-reduced-motion`. В нём нет body parsing, authorization, aggregate count, moderation, reaction или write logic и нет console logging. Без JavaScript SSR list, ordinary paginator/direct links и textarea content остаются читаемыми; Livewire-only mutations честно требуют рабочий transport.

## Frontend lifecycle отзывов

`resources/js/reviews.js` owns only session draft persistence, direct `#review-{id}` focus/highlight and reduced-motion-safe scroll after Livewire morph. Draft payload is scoped by an opaque account token plus target/review identity, bounded and expires after 24 hours; a later account in the same browser session cannot inherit it. It contains only title/body/rating/spoiler and no provider URL, watch evidence, report or moderation data. Storage exceptions fail silently back to server form state.

Review business state remains in Livewire/actions/DTO: viewer vote, permission, restriction, rating, verified snapshot and spoiler reveal are never decided in JavaScript. Stable `wire:key` prevents row identity loss; URL-backed sort/filter/page works with browser navigation. Vite imports the module from `resources/js/app.js`; no inline application script/CSS, external package, polling or full Eloquent serialization is added.

## Frontend lifecycle профилей пользователей

- `PublicProfilePage` is one class-based Livewire page with locked canonical username, allowlisted URL-backed tab and one selected paginator. Public sections are not all loaded initially; browser history, pagination names and server-normalized invalid tabs remain stable.
- Owner editing extends the existing `/profile` page with independent username, biography, avatar, cover and privacy forms. Complex privacy is staged until its Apply submit; upload/username actions disable only relevant controls, clear password/file state after outcome and use native accessible inputs/confirmations.
- Public/owner layouts reuse current panels, cards, typography, buttons, focus and responsive rules. Navigation wraps instead of introducing an internal horizontal scrollbar; long username/biography/URL text wraps, 390px desktop/mobile browser smoke has no document overflow.
- User text remains original language and escaped. Russian/English labels, errors, loading, empty/private/moderated states and ARIA descriptions live only in the existing PHP translation catalogs. No inline CSS, business JavaScript, Volt or Blade query was added.

## Frontend lifecycle настроек аккаунта

`resources/js/settings.js` обслуживает только безопасную browser boundary: versioned local preferences, backward-compatible read существующего `plyr` state, volume preview, explicit browser-timezone suggestion, dirty-form warning и one-shot authenticated merge. Он не решает authorization, entitlement, privacy, notification delivery, profile write или source access и не содержит console logging.

`AccountSettingsPage` загружает только выбранный URL section, хранит scalar typed draft, применяет один Apply/Cancel pattern per staged group и dispatch-ит device sync после подтверждённого server save. Navigation использует обычные localized links с `wire:navigate`, поэтому back/forward и no-JavaScript route fallback сохраняются. Local volume/mute применяются немедленно и записываются debounced/pause/destroy, не отправляя server request на каждое движение slider.

`CatalogTitlePlayer` сохраняет URL selection как highest explicit precedence, затем использует сохранённый stable quality/variant и safe fallback. `player.js` применяет autoplay, remember-volume, mute, allowed speed, captions и focused keyboard shortcut к существующему Plyr/HLS instance; второй player/style/source resolver не создан. Reduced-motion account override добавляет body class и переиспользует те же motion-safe rules, что media query.

## Frontend lifecycle заявок на материалы

Page-level Livewire components обслуживают public directory/detail/create, private My Requests и gated administration. URL-bound search/filter/sort/page state ограничен stable enum values; aggregate передаётся как locked numeric ID, public form — только scalar/list draft с UUID submission token. Debounced autocomplete имеет minimum length, bounded server results, listbox/combobox semantics, touch-sized links/buttons, loading/no-result/fallback announcements; authoritative existence/duplicate logic остаётся PHP action.

Blade получает DTO/options/paginators и prepared per-request administration capabilities; он не вызывает models/services/config/database, не рассчитывает duplicate/priority/transition/permissions и не сериализует Eloquent graph. Generic status select получает только допустимые non-dedicated transitions, а clarification/completion/merge/import controls выводятся только для совместимого persisted status. Все mutations имеют targeted `wire:loading`, disabled retry protection, status/alert live regions и explicit confirmation для withdrawal/merge/import. Layout использует существующие light-theme panels/forms/badges/buttons/focus classes; grids collapse на phone, long title/user text/URL uses break rules, actions wrap without hover-only access. Нового Vite module не потребовалось: request business logic и trusted validation не перенесены в JavaScript.

## Frontend lifecycle рекомендаций

`CatalogDiscoveryPage` хранит только validated scalar URL state; `type`, refresh seed и last undo ID locked, current user/history остаются server-side. Filter changes reset page/errors, pagination deterministic, refresh remembers current IDs before changing seed. Incremental loading keeps the page shell/results visible, disables duplicate actions and announces loading/notice/error/empty state through translated live regions.

Recommendation rows reuse `x-catalog.title-card` recommendation layout. Type navigation is touch-scrollable with standard link fallback; result list is a responsive grid/list, not autoplay carousel. There is no inline CSS, `@php`, business JavaScript, client ranking, exposed candidate graph or polling. Keyboard focus, 44px controls, visible focus, long-label wrapping, missing poster alt, zoom/reduced-motion and phone/tablet/desktop layout follow `UI_STANDARDS.md`. No new Vite module was required.

Confidence, source-title IDs, evidence weights and negative profile remain server-side. Видимая причина «Новое для вас» появляется только у bounded exploration row, прошедшего тот же availability/relevance boundary; cold/low/medium fallback использует фактический public display type и не обещает несуществующую глубину персонализации.

## Frontend lifecycle рейтингов Top 100

Страницы `/top/{category}` полностью серверные и не добавляют JavaScript lifecycle. Общий hero объясняет источник мест, четыре обычные ссылки переключают категорию с `aria-current`, первые три позиции образуют визуальную витрину, а позиции 4–100 используют тот же `x-catalog.title-card`. Семантический ordered list и видимый номер сохраняют порядок без зависимости от CSS или клиента.

Mobile-first layout остаётся одной колонкой на телефоне, podium расширяется до трёх колонок на больших экранах, основной список — до двух колонок на широком экране. Длинные названия переносятся, controls имеют видимый `focus-visible`, отсутствующий постер использует существующий fallback, а пустая категория показывает честное состояние и переход в каталог. Проверяемая viewport-матрица: 390, 768, 1440 и 1920 пикселей без горизонтального переполнения.

Под категорийной навигацией находится одна пассивная GET-форма с native number inputs и select для страны и жанра: на телефоне поля идут одной колонкой, на среднем экране — двумя, на широком — одной строкой. Submit работает без JavaScript, выбранные значения сохраняются в URL и category links, reset появляется только при активных условиях, validation errors связаны с controls через `aria-describedby`, все элементы управления имеют сенсорную высоту не менее 44 пикселей, а filtered empty state предлагает сброс текущей категории.

## File-size и download UI плеера

`CatalogTitlePlayer` использует уже загруженный selected `LicensedMedia`: `CatalogTitlePlaybackQuery` выбирает file-size metadata, общий formatter строит B/KB/MB/GB/TB label, а `CatalogShowViewModel` готовит deterministic direct-file/download/login/reason state. Render не выполняет HEAD/Range/DNS и не принимает remote URL. Binary response не проходит через Livewire: обычная named-route ссылка открывает отдельный controller.

Под player details direct media показывает pill с local `fa-hard-drive`; `null` выводится как переведённый «размер неизвестен», не `0 B`. Authenticated user получает emerald `fa-file-arrow-down` attachment link с форматом/размером, normal navigation, visible focus, 44px touch target и mobile full-width wrapping. Guest видит login CTA с локальным lock icon; HLS/playlist — неактивное stream-only объяснение. Raw/signed upstream query отсутствует в HTML, `wire:click`, duplicate player, Volt, `@php`, inline PHP/CSS/JS и Blade service/query calls не добавлены.

Technical issue UI использует те же light Tailwind/Blade/Livewire conventions. Player передаёт report form только encrypted expiring context и numeric approximate position; `resources/js/issues.js` собирает optional allowlisted diagnostics и не читает source URL, cookies/storage или progress. Create/detail/list/admin layouts mobile-first, long-text safe, keyboard/touch accessible и имеют scoped loading/live-region states. Полный frontend/privacy contract: [`technical-issues.md`](technical-issues.md).
## Task 02: глобальный поиск

Header использует один progressive `x-layout.header-search`: SSR-форма остаётся рабочим `GET` без JavaScript, а `resources/js/header-search.js` добавляет только presentation/autocomplete behavior. Один символ запрашивает bounded exact-title scope, со второго после debounce 160 мс параллельно запускаются title и portal scopes. Locale входит в in-tab cache identity и передаётся как проверяемый `Accept-Language`; `AbortController` плюс sequence number защищают от stale response после нового ввода, очистки или перехода.

Dropdown реализует `combobox`/`listbox`, `aria-expanded`, `aria-activedescendant`, translated live status, Arrow Up/Down, Home/End, Enter, Escape, отдельные clear/close controls, outside-click и возврат фокуса. DOM создаётся через `textContent`, URL допускаются только same-origin, поэтому query и внешние metadata не интерпретируются как HTML. Responsive limits зависят от viewport height/width, панель ограничена viewport и не требует hover. Search page, catalog controls и actor/tag directory UI используют существующие Tailwind tokens, min-height 44 px, локализованные loading/empty/error/count states и не содержат inline CSS/Blade JavaScript.

Полная выдача `/titles?q=` остаётся Livewire 4 owner для filters/sort/page/history; `/search` — SSR discovery preview с exact count и ссылкой на неё. Это исключает две расходящиеся реализации фильтров и сохраняет обычные links/form submission при отключённом JavaScript.

## Calendar frontend lifecycle

Public/personal calendar рендерится full-page Livewire и остаётся содержательным без JavaScript. `resources/js/release-calendar.js` отвечает только за presentation countdown из trusted server ISO timestamp: minute-level interval, stop at zero, `livewire:navigating` cleanup и reinitialization после navigation. Он не определяет release status, timezone, visibility или notification eligibility. Month table скрывается в пользу server-rendered agenda на телефоне; loading/empty/error/live status и keyboard focus принадлежат Blade/Livewire. Полный contract: [`release-calendar.md`](release-calendar.md).

## Mobile runtime и capability enhancement Task 23

`resources/js/app.js` оставляет eager только header search и малый `mobile-runtime`; collections, comments, reviews, technical issues, help, settings, release calendar и player bridge импортируются по DOM selector. Player загружает Plyr/HLS только при наличии `[data-catalog-player]`, HLS — только для HLS source. Module initialization использует `WeakSet`/abort cleanup и повторяется безопасно после Livewire morph/navigation; `livewire:navigating` закрывает menu, уничтожает player/listeners/timers и сохраняет meaningful progress.

`mobile-runtime.js` владеет только presentation/capability concerns: native details navigation, explicit public share with Web Share or write-only clipboard fallback, visual viewport CSS variable, truthful connection banner, route announcement/focus, deferred mobile filter presentation и private bfcache revalidation. Он не читает clipboard, camera, microphone, location, sensors, contacts, cookies, auth tokens, media URL или private history; не запрашивает permissions; не определяет premium/region/download access.

Player по-прежнему использует один `CatalogTitlePlayer`. Progress отправляется bounded 30-second heartbeat с minimum delta, а также на pause, meaningful seek, visibility hidden, `pagehide`, Livewire navigation и destroy; orientation не создаёт новый instance или отдельную запись. `beforeunload` для player removed: тяжёлая blocking unload boundary не используется. Media Session получает только public title/episode/season/poster metadata и capability-gated play/pause/seek/previous/next actions; raw media URL/grant/token не передаются. Position state обновляется bounded interval и очищается вместе с action handlers. Plyr управляет standards fullscreen/PiP/captions/speed/quality controls и скрывает unsupported controls.

`navigator.connection.saveData` — необязательная подсказка только при `auto`/browser-managed выборе: autoplay выключается, video preload становится `none`, HLS start откладывается до play и buffer уменьшается. Явный quality/source choice не переписывается. `navigator.onLine === false` позволяет показать known-offline player error, но timeout/provider failure не называется offline; retry остаётся user-initiated и bounded.

Page restoration с persisted bfcache перезагружает только route, помеченный `PrivateAccountResponse`, чтобы history/settings/admin/private state не показывался после logout/account switch. Public pages сохраняют browser scroll/restoration. Standalone/PWA-specific lifecycle отсутствует: web portal не зависит от service worker на first load.

Web Share используется только на public title canonical URL, без query token/private state; explicit copy fallback не читает clipboard. Deep links — обычные canonical localized web routes и работают без установки. Нет fake voice search, biometric login, install/push/download control или gesture-only player command.

## Premium frontend lifecycle

`PremiumPricingPage`, settings section, payment return, notification panel и `PremiumAdministrationManager` — full-page/embedded Livewire components с typed scalar state и prepared DTO/arrays. Они не сериализуют gateway/provider objects, tokens или customer/payment IDs. Checkout revalidates plan server-side, использует `wire:loading`/disabled state и external HTTPS redirect; return отображает только local reconciled state. Blade не содержит `@php`, model/service calls, inline CSS или billing JavaScript.

Пока provider и public plans отсутствуют, pricing показывает локализованный unavailable/empty state без dead checkout. Responsive cards/tables имеют visible focus, ARIA live status, touch targets и accessible overflow. Реальные features и prices приходят только из registry/query. Полный UI, accessibility и locale contract — [`premium.md`](premium.md).

## Help-center frontend lifecycle

Help home/category/article/search/admin — full-page Livewire со scalar locked/validated state и prepared DTO. Article/FAQ/TOC server-rendered и полезен без JavaScript. `help-center.js` владеет только 250 ms autocomplete, AbortController/sequence, keyboard combobox/listbox и admin unsaved navigation/locale guard; visibility/ranking/fallback/publication/escalation остаются PHP.

Module подключается Vite только при соответствующем DOM target, очищает stale response и повторно инициализируется после Livewire navigation. Loading/empty/error/fallback/feedback/report states локализованы; current content сохраняется во время secondary action. Полный contract: [`help-center.md`](help-center.md).

## Playback frontend lifecycle Task 07

Native `<video>` + один Plyr + один optional HLS.js light остаются единственным player stack. Vite-managed `player.js` инициализируется один раз, применяет RU/EN prepared dictionary, feature-detect fullscreen/PiP/native HLS, ограничивает HLS/progressive recovery, ведёт local heartbeat и уничтожает HLS, timers, listeners, dialog, Media Session и detached playback при Livewire navigation.

Portal controls добавляют server-resolved previous/next, autoplay countdown/cancel/play-now, restart, grouped authorized source options и shortcut help. Anonymous progress (`seasonvar.playback-progress.v1`) хранит только bounded episode position; signed/source URLs и tokens не попадают в storage. Phone/coarse pointer/safe-area/captions/reduced-motion/data-saver contracts и known browser limitations описаны в [`audits/video-playback-report.md`](audits/video-playback-report.md).
