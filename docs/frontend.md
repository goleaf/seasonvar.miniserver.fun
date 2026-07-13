# Frontend

Обновлено: 12.07.2026

## Стек

- Vite 8 и `laravel-vite-plugin` 3 собирают фронтенд.
- Tailwind CSS 4 подключается через `@tailwindcss/vite` и `resources/css/app.css`.
- FontAwesome, Plyr и HLS подключаются из локальных npm-пакетов, без CDN.
- Livewire 4 используется для интерактивного каталога `/titles`, playback-island карточки `/titles/{slug}`, личной страницы `/watching` и live-страницы `/stats`; ассеты Livewire подключаются явно через layout.

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
- Поиск актеров и режиссеров выполняется Livewire на сервере с debounce 350 мс и лимитом 24 результата, поэтому полный справочник не попадает в браузер. Локальный поиск для остальных длинных групп остается progressive enhancement из `resources/js/app.js`; GET-форма работает без JavaScript.
- Серверное состояние `/titles` ведёт `CatalogSeries`: строка поиска обновляется с debounce 650 мс, checkbox/расширенные поля применяются по submit, а сортировка, вид, размер страницы, алфавит и пагинация обновляются отдельными Livewire actions. Для всех форм сохранён обычный GET fallback; malformed и out-of-range `page` канонизируется redirect-ом, чтобы адресная строка не сохраняла stale границу.
- `/watching` не сериализует Eloquent collections в публичное состояние: Continue Watching и paginator истории строятся только внутри render. Удаление использует `wire:confirm`, полная очистка — `wire:confirm.prompt`, а `historyPage` остаётся отдельным URL-параметром Livewire pagination.
- `/admin/imports` опрашивает сервер через `wire:poll.5s.visible` только пока есть active run. После terminal state poll-attribute исчезает; run models и collections не хранятся в public snapshot, а rows имеют stable `wire:key`.
- Для HLS используется `hls.js/light`: он сохраняет воспроизведение HLS-плейлистов и не тянет модули субтитров, DRM и расширенной аналитики, которые сейчас не используются интерфейсом.
- Layout подключает ассеты через `@vite('resources/js/app.js')`; не добавлять raw `<script>`/`<style>` для обычных assets.
- Layout также содержит `@livewireStyles` и `@livewireScripts`; не дублировать Livewire/Alpine через CDN или отдельный npm-bundle.
- Интерфейс использует системный стек шрифтов с поддержкой кириллицы; внешний font bundle и `Vite::fonts()` не подключаются.
- FontAwesome собирается из локальных `fontawesome.min.css`, `solid.min.css` и `regular.min.css`; brands/v4 font-файлы не входят в bundle.
- Plyr получает локальный Vite URL из `resources/images/plyr.svg`, а HLS-код загружается только когда браузеру действительно нужен `hls.js/light`.
- Проектная pagination переопределена в `resources/views/vendor/pagination/tailwind.blade.php`: весь текст русский, тема только светлая, элементы управления имеют высоту не менее 44 пикселей.
- Livewire pagination каталога переопределена в `resources/views/vendor/livewire/tailwind.blade.php`: русские подписи, стабильные `wire:key`, светлая тема и элементы управления не менее 44 пикселей.
- Новые пользовательские строки Blade/Livewire добавляются в `lang/{locale}/catalog.php`; plural counts выводятся через `trans_choice()`. Интерфейсная locale управляет только UI и page metadata и не подменяет перевод, язык аудио или субтитров.
- Essential title metadata остаётся в статическом Blade response. `CatalogShowViewModel` передаёт presentation-safe plain text, а Livewire player не становится источником `<h1>`, description, canonical или Open Graph.
- Ссылки сортировки, вида, размера страницы, алфавита, фильтров и быстрого доступа используют плоские состояния без декоративной border/ring-обводки и сохраняют touch-target не менее 44 пикселей. Рамки остаются у форм, alert, player/media frames и структурных карточек.
- Общий `focus-visible` определен в `resources/css/app.css`; составной `x-form.search-field` и локальные поиски фильтров используют `data-focus-frame`, чтобы клавиатурный focus охватывал весь control без двойного контура.
- Проверка responsive player должна подтверждать, что смена viewport не заменяет существующий `video.js-catalog-player`: player island остается под `wire:ignore`, а lifecycle-cleanup срабатывает только при смене выпуска или навигации.
- Blade не должен содержать `@php`/`@endphp` или asset-логику на PHP; используйте Laravel/Vite helpers и конфигурацию Vite.
