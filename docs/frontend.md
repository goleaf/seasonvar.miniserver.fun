# Frontend

Обновлено: 12.07.2026

## Стек

- Vite 8 и `laravel-vite-plugin` 3 собирают фронтенд.
- Tailwind CSS 4 подключается через `@tailwindcss/vite` и `resources/css/app.css`.
- FontAwesome, Plyr и HLS подключаются из локальных npm-пакетов, без CDN.
- Livewire 4 используется для интерактивного каталога `/titles`, playback-island карточки `/titles/{slug}` и live-страницы `/stats`; ассеты Livewire подключаются явно через layout.

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
- Player создаёт одну guarded browser-session на точный `title:episode:media` source, восстанавливает позицию после metadata load и отправляет bounded progress только для authenticated markup: 30-секундный heartbeat работает лишь во время воспроизведения, а pause, stable seek, hidden visibility, navigation, pagehide и ended принудительно фиксируют позицию. `AbortController` вместе освобождает listeners, timers, Plyr и HLS при Livewire morph/navigation; generation token отменяет устаревшую async-инициализацию, а событие от старого session key не достигает Livewire. Cleanup удаляет lifecycle-маркеры как с текущего элемента, так и с исходного media node, который Plyr возвращает в DOM при `destroy()`, поэтому `livewire:navigated`, Back/Forward и bfcache не создают второй instance.
- Состояния loading, buffering, automatic retry, expired/unavailable source и fatal error отображаются фиксированным русским текстом рядом с `wire:ignore` player island. Provider URL, exception text и raw media errors в status region не выводятся.
- Поиск актеров и режиссеров выполняется Livewire на сервере с debounce 350 мс и лимитом 24 результата, поэтому полный справочник не попадает в браузер. Локальный поиск для остальных длинных групп остается progressive enhancement из `resources/js/app.js`; GET-форма работает без JavaScript.
- Серверное состояние `/titles` ведёт `CatalogSeries`: строка поиска обновляется с debounce 650 мс, checkbox/расширенные поля применяются по submit, а сортировка, вид, размер страницы, алфавит и пагинация обновляются отдельными Livewire actions. Для всех форм сохранён обычный GET fallback; malformed и out-of-range `page` канонизируется redirect-ом, чтобы адресная строка не сохраняла stale границу.
- Для HLS используется `hls.js/light`: он сохраняет воспроизведение HLS-плейлистов и не тянет модули субтитров, DRM и расширенной аналитики, которые сейчас не используются интерфейсом.
- Layout подключает ассеты через `@vite('resources/js/app.js')`; не добавлять raw `<script>`/`<style>` для обычных assets.
- Layout также содержит `@livewireStyles` и `@livewireScripts`; не дублировать Livewire/Alpine через CDN или отдельный npm-bundle.
- Интерфейс использует системный стек шрифтов с поддержкой кириллицы; внешний font bundle и `Vite::fonts()` не подключаются.
- FontAwesome собирается из локальных `fontawesome.min.css`, `solid.min.css` и `regular.min.css`; brands/v4 font-файлы не входят в bundle.
- Plyr получает локальный Vite URL из `resources/images/plyr.svg`, а HLS-код загружается только когда браузеру действительно нужен `hls.js/light`.
- Проектная pagination переопределена в `resources/views/vendor/pagination/tailwind.blade.php`: весь текст русский, тема только светлая, элементы управления имеют высоту не менее 44 пикселей.
- Livewire pagination каталога переопределена в `resources/views/vendor/livewire/tailwind.blade.php`: русские подписи, стабильные `wire:key`, светлая тема и элементы управления не менее 44 пикселей.
- Blade не должен содержать `@php`/`@endphp` или asset-логику на PHP; используйте Laravel/Vite helpers и конфигурацию Vite.
