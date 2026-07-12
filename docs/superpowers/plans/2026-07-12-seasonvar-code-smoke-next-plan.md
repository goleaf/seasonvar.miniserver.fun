# Seasonvar: план следующих улучшений после кодовой smoke-итерации

Дата: 12.07.2026  
Ветка: `seasonvar-design-code-polish`  
Режим проверки этой итерации: без запуска `php artisan test` и без создания test-файлов.  
Фактическая проверка этой итерации: Pint, Vite build, HTTP smoke, Laravel Boost logs, Browser logs через Laravel Boost.

## 1. Что было сделано в этой итерации

### 1.1 Улучшение `/titles`

- Добавлен якорь `#catalog-filters` на блок фильтров.
- На мобильной раскладке добавлена кнопка `К фильтрам`, которая ведет к блоку фильтров без JavaScript.
- На desktop sidebar фильтров стал sticky, получил ограничение по высоте viewport и внутреннюю прокрутку.
- Расширенные фильтры получили более явный `<summary>`: иконку, минимальную высоту touch target, chevron-индикатор раскрытия и перенос длинного текста.
- Сохранен мобильный порядок каталога: результаты и поиск остаются выше фильтров.

### 1.2 Исправление scalar/array edge case

- В `CatalogTitlesViewModel` добавлен метод `listState(string $key): array`.
- `x-catalog.advanced-filters` больше не делает `foreach` по raw query-state.
- `exclude_country`, `exclude_genre`, `year` и `quality` теперь безопасно обрабатываются, если query пришел scalar-значением.
- Исправлен runtime edge case `foreach() argument must be of type array|object, string given`.
- Scalar `quality=1080p` корректно отмечает checkbox `1080p`.
- Array `quality[]=1080p` продолжает работать.

### 1.3 Проверка без PHP-тестов

- `./vendor/bin/pint --dirty --format agent` прошел.
- `npm run build` прошел.
- HTTP smoke вернул `200` для `/`.
- HTTP smoke вернул `200` для `/stats`.
- HTTP smoke вернул `200` для `/titles`.
- HTTP smoke вернул `200` для `/titles?view=list`.
- HTTP smoke вернул `200` для `/titles?q=драма`.
- HTTP smoke вернул `200` для `/titles?letter=А`.
- HTTP smoke вернул `200` для `/titles?year=2025`.
- HTTP smoke вернул `200` для `/titles?year[]=2024&year[]=2025`.
- HTTP smoke вернул `200` для `/titles?video=available&quality=1080p`.
- HTTP smoke вернул `200` для `/titles?video=available&quality[]=1080p`.
- HTTP smoke вернул `200` для `/titles?exclude_country=strana-1`.
- HTTP smoke вернул `200` для `/titles?year_from=2020&year_to=2026&view=list`.
- HTML smoke подтвердил наличие `id="catalog-filters"`.
- HTML smoke подтвердил наличие текста `К фильтрам`.
- HTML smoke подтвердил наличие текста `Расширенные фильтры`.
- HTML smoke подтвердил checked-состояние для scalar `quality=1080p`.
- Поиск `@php` и `@endphp` в Blade не нашел нарушений.
- Поиск публично запрещенной терминологии в catalog Blade-компонентах не нашел нарушений.

### 1.4 Ограничения проверки

- Browser smoke через Playwright CLI не выполнен, потому что в среде отсутствует Chrome binary по пути `/opt/google/chrome/chrome`.
- Новые браузерные зависимости не устанавливались.
- PHP test suite не запускался по прямому ограничению пользователя.
- Test-файлы не создавались.

## 2. Следующий приоритет A: browser QA без test-файлов

### A1. Подготовить браузерную проверку без test specs

- Установить или подключить доступный Chrome/Chromium binary только после отдельного разрешения пользователя.
- Не создавать Playwright test-файлы.
- Использовать CLI/snapshot/screenshot режим.
- Проверить, что `playwright-cli open` открывает `/titles`.
- Проверить, что `playwright-cli snapshot` видит `К фильтрам`.
- Проверить, что `playwright-cli snapshot` видит `Фильтры каталога`.
- Проверить, что `playwright-cli snapshot` видит `Расширенные фильтры`.
- Проверить, что browser console не содержит ошибок на `/titles`.
- Проверить, что browser console не содержит ошибок на `/stats`.

### A2. Проверить responsive состояния `/titles`

- 360px ширина: header не создает горизонтальный scroll.
- 360px ширина: кнопка `К фильтрам` видна до списка.
- 360px ширина: переход по `#catalog-filters` попадает к фильтрам.
- 390px ширина: search form не выходит за viewport.
- 390px ширина: toolbar chips переносятся.
- 430px ширина: расширенные фильтры раскрываются без переполнения.
- 768px ширина: grid показывает две колонки без сжатия текста.
- 1024px ширина: sidebar становится sticky.
- 1440px ширина: grid не становится слишком плотным.
- 1760px ширина: max-width layout остается читаемым.

### A3. Проверить interactive controls

- Клик `К фильтрам` работает без JavaScript.
- Раскрытие `Расширенные фильтры` работает мышью.
- Раскрытие `Расширенные фильтры` работает клавиатурой.
- Sort chips сохраняют текущий query context.
- View switch сохраняет текущий query context.
- Per-page switch сохраняет текущий query context.
- Alphabet links сохраняют совместимый query context.
- Active filter chips удаляют только выбранный фильтр.
- `Сбросить все` ведет на `/titles`.

## 3. Следующий приоритет B: стабилизировать query-state контракты

### B1. Централизовать list-like state

- Использовать `CatalogTitlesViewModel::listState()` во всех Blade-местах, где query-state может быть scalar или array.
- Проверить `year`.
- Проверить `quality`.
- Проверить `exclude_country`.
- Проверить `exclude_genre`.
- Проверить каждый taxonomy filter из `CatalogFilterType::values()`.
- Исключить прямые `foreach` по `$filterView->catalogQueryState` из Blade.
- Исключить прямые `in_array` по `$filterView->catalogQueryState` из Blade.

### B2. Добавить scalar-safe helpers

- Добавить `scalarState(string $key): string` для input/select value.
- Перевести advanced filter inputs на `scalarState()`.
- Не читать raw query-state напрямую в Blade, если значение участвует в HTML attribute.
- Сохранить существующий `catalogQueryState` как низкоуровневый state bag.
- Использовать typed helper methods для публичной разметки.

### B3. Проверить query canonicalization

- `/titles?quality=1080p` должен работать.
- `/titles?quality[]=1080p` должен работать.
- `/titles?year=2025` должен работать.
- `/titles?year[]=2024&year[]=2025` должен работать.
- `/titles?exclude_country=strana-1` должен работать.
- `/titles?exclude_country[]=strana-1` должен работать.
- `/titles?genre=drama` должен работать.
- `/titles?genre[]=drama` должен работать.
- Пустые значения не должны сохраняться в generated links.
- Single-value query в generated links должен быть scalar там, где это читабельно.
- Multi-value query в generated links должен оставаться array там, где выбрано больше одного значения.

## 4. Следующий приоритет C: Laravel logs hygiene

### C1. Разделить старые и новые ошибки

- Зафиксировать timestamp перед smoke-проверкой.
- После smoke читать только log entries после timestamp.
- Не считать старые entries текущим регрессом.
- Для `Vite manifest not found` проверять, не совпал ли запрос по времени с `npm run build`.
- Не запускать HTTP smoke параллельно с `npm run build` на live URL, чтобы не ловить временное отсутствие manifest.

### C2. Убрать известные источники шума

- Проверить старую ошибку `Undefined variable $years`.
- Проверить старую ошибку typed eager-load closure на главной.
- Проверить старую ошибку `foreach() argument must be of type array|object, string given`.
- Проверить старую ошибку Livewire dialog `showModal`.
- Для каждой актуальной ошибки сделать минимальную правку.
- Для каждой неактуальной ошибки записать причину, почему текущий код ее уже не воспроизводит.

### C3. Stats browser log

- Разобрать `HTMLDialogElement.showModal` на `/stats`.
- Проверить, появляется ли ошибка сейчас или это исторический browser log.
- Если актуально, найти источник Livewire error response.
- Проверить network response для Livewire poll.
- Исправить server-side ошибку, если dialog открывается из-за failed Livewire request.
- Не патчить vendor Livewire.

## 5. Следующий приоритет D: `/stats` performance и reliability

### D1. HTTP smoke

- Проверить `/stats` после build, не параллельно build.
- Проверить размер HTML.
- Проверить время ответа.
- Проверить, что HTML не содержит raw source URLs.
- Проверить, что HTML не содержит raw playback URLs.
- Проверить, что HTML не содержит route table internals.
- Проверить, что HTML не содержит stack traces.

### D2. Livewire poll

- Проверить частоту `wire:poll.1s`.
- Оценить нагрузку для текущего размера базы.
- Рассмотреть увеличение poll interval до 3s или 5s, если страница тяжелая.
- Рассмотреть server-side cache для тяжелых stats blocks.
- Не менять poll interval без проверки UX: stats page может быть предназначена для live-monitoring.

### D3. Poster proxy

- Проверить `/stats/poster/{slug}` для тайтла с постером.
- Проверить HTTPS-only behavior.
- Проверить blocked private host behavior через безопасную ручную проверку без внешних test-файлов.
- Проверить image content type handling.
- Проверить empty Content-Length behavior.
- Проверить oversized body behavior через controlled fake возможно только в тесте; без тестов оставить ручной план.

## 6. Следующий приоритет E: UI compactness и readability

### E1. Sidebar filters

- Измерить высоту sidebar на desktop.
- Определить, какие filter groups слишком длинные.
- Для длинных groups добавить native disclosure.
- По умолчанию раскрыть активные groups.
- По умолчанию свернуть неактивные длинные groups.
- Сохранить видимость `Годы`.
- Сохранить видимость активных taxonomy groups.
- Не добавлять JavaScript для базового раскрытия.

### E2. Toolbar

- Свести `Найдено`, `На странице`, `Сортировка` к компактной строке на mobile, если текущие cards занимают слишком много высоты.
- Проверить, не выглядят ли три metric-блока как декоративные cards.
- Если выглядят тяжело, заменить на compact summary list.
- Сохранить readable labels.
- Сохранить icon semantics.

### E3. Active filters

- Уменьшить повторение текста `убрать`, если chips становятся слишком длинными.
- Рассмотреть `aria-label` для action meaning.
- Сохранить видимый русский текст.
- Не использовать только иконки для удаления.
- Проверить перенос chips на 360px.

## 7. Следующий приоритет F: search behavior

### F1. Search form intent

- Решить, должен ли search form сохранять текущие фильтры.
- Если search должен искать внутри текущего фильтра, добавить hidden fields из active filter state.
- Если search должен начинать новый общий поиск, оставить текущий reset behavior.
- Сделать выбранное поведение явным в UX copy.
- Проверить, что `Очистить поиск` не сбрасывает фильтры.
- Проверить, что `Сбросить фильтры` не обязательно сбрасывает поиск, если пользователь выбрал именно фильтры.

### F2. Stop-word behavior

- Проверить `q=и`.
- Проверить `q=на`.
- Проверить `q=сериал`.
- Проверить, что пустая выдача не подставляет чужие результаты.
- Проверить, что сообщение написано без технических терминов.

### F3. Search candidate performance

- Измерить response time для точного title match.
- Измерить response time для broad term.
- Измерить response time для taxonomy-name term.
- Измерить response time для no-match term.
- Проверить, что candidate cache внутри request реально переиспользуется в context counts.

## 8. Следующий приоритет G: home page

### G1. Latest titles

- Проверить, что последние тайтлы показываются, даже если нет постеров.
- Проверить, что страны и жанры в title row не обрезаются случайно.
- Проверить, что `cardRelations` не скрывает важные страны при малом наборе данных.
- Проверить date grouping.
- Проверить empty states.

### G2. Latest media

- Проверить, что latest media не показывает медиа без опубликованного тайтла.
- Проверить, что title relation eager loaded без runtime type errors.
- Проверить, что качество и перевод видны.
- Проверить, что raw URLs не выводятся.

### G3. Sidebar

- Проверить страны.
- Проверить жанры.
- Проверить disclosure для стран больше 12.
- Проверить counts по опубликованным тайтлам.
- Проверить мобильный порядок.

## 9. Следующий приоритет H: title page

### H1. Смотреть блок

- Проверить, что блок просмотра находится выше справочных SEO-секций.
- Проверить выбранный сезон.
- Проверить выбранную серию.
- Проверить selected media.
- Проверить HLS fallback.
- Проверить player placeholder.

### H2. Сезоны и серии

- Проверить accordion keyboard behavior.
- Проверить mobile touch targets.
- Проверить отсутствие горизонтального scroll.
- Проверить, что все сезоны остаются внутри одного `CatalogTitle`.
- Проверить, что route query с invalid episode не ломает страницу.

### H3. Recommendations

- Проверить, что recommendation links ведут только на опубликованные тайтлы.
- Проверить, что reason badges короткие.
- Проверить, что пустой block не занимает лишнюю высоту.
- Проверить отсутствие запрещенной публичной терминологии.

## 10. Следующий приоритет I: API и feeds smoke без test-файлов

### I1. API

- `GET /api/titles` должен вернуть 200.
- `GET /api/titles?per_page=1` должен вернуть 200.
- `GET /api/titles/{existing-slug}` должен вернуть 200.
- API response не должен содержать source HTML.
- API response не должен содержать stack traces.
- API pagination meta должен быть явным.

### I2. Sitemap и feeds

- `/sitemap.xml` должен вернуть 200.
- `/sitemap-index.xml` должен вернуть 200.
- `/feed.xml` должен вернуть 200.
- `/opensearch.xml` должен вернуть 200.
- `/llms.txt` должен вернуть 200.
- Streamed responses проверять через HTTP status и первые bytes.
- Не запускать destructive cache commands.

## 11. Следующий приоритет J: безопасный Git workflow

### J1. Перед commit

- Проверить `git status --short`.
- Проверить `git diff --stat`.
- Проверить `git diff --check`.
- Проверить, что нет новых файлов в `tests/`.
- Проверить, что нет `.env`.
- Проверить, что нет secrets.
- Проверить, что нет скачанных видео-файлов.
- Проверить, что нет временных `/tmp` файлов внутри repo.

### J2. Проверки без PHP tests

- Запустить Pint.
- Запустить `npm run build`.
- Запустить route smoke.
- Запустить HTTP smoke.
- Запустить HTML marker smoke.
- Проверить Laravel Boost logs.
- Проверить Laravel Boost browser logs.
- Не запускать `php artisan test`.
- Не запускать `./vendor/bin/phpunit`.
- Не создавать test-файлы.

### J3. Commit и push

- Stage только файлы текущей итерации.
- Commit message должен описывать результат.
- Push текущей ветки.
- Если remote rejected, не force-push без отдельного разрешения.
- Если auth unavailable, сообщить точную команду для ручного push.

## 12. Stop conditions

- Нужна новая production dependency.
- Нужно установить browser binary.
- Нужно создать test-файл.
- Нужно запускать PHP test suite вопреки текущему ограничению.
- Нужно менять `.env`.
- Нужно запускать destructive command.
- Нужно менять import public command contract.
- Нужно делать force push.
- Нужно раскрывать secrets, cookies, tokens или private logs.
