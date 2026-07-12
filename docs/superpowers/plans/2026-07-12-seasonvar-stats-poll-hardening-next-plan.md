# Seasonvar stats polling hardening and next improvements plan

**Дата:** 12.07.2026

**Режим этого прохода:** код сначала, затем проверка без создания test-файлов и без запуска PHPUnit/PHP test suite.

**Цель выполненной правки:** снизить риск повторяющихся Livewire browser errors на `/stats`, уменьшить нагрузку от polling, убрать падение `/titles` на scalar query-state, закрепить работу только в `main` и синхронизировать код с документацией проекта.

## Что уже сделано в этом проходе

- В `resources/views/livewire/stats-dashboard.blade.php` секундный polling заменен на `wire:poll.15s.visible`.
- В `app/Services/Catalog/CatalogStatsSnapshotCache.php` fresh TTL снимка статистики изменен с 1 секунды на 15 секунд.
- В `CatalogStatsSnapshotCache` добавлена единая константа сообщения свежего снимка, чтобы текст статуса не дублировался.
- В UI изменена подпись свежего снимка: `Данные обновляются примерно раз в 15 секунд.`
- В `config/cache.php` добавлен отдельный `cache.limiter` store через `CACHE_LIMITER_STORE=file`, чтобы throttle-счетчики публичных маршрутов не писались в SQLite `cache` table.
- В `.env.example` добавлен безопасный `CACHE_LIMITER_STORE=file`.
- В `app/View/ViewModels/CatalogTitlesViewModel.php` добавлен `listState()` для безопасного чтения query-state как списка строк.
- В `resources/views/catalog/titles.blade.php` raw-переборы `catalogQueryState` заменены на `listState()` для скрытых `exclude_country`, `exclude_genre`, `year` и чекбоксов `quality`.
- В `CatalogTitlesViewModel` добавлен `scalarState()` для безопасного чтения одиночных query-state значений.
- В `resources/views/catalog/titles.blade.php` scalar-поля расширенных фильтров переведены с прямого `$filterView->catalogQueryState` на `scalarState()`.
- `advancedFilterValue()` теперь форматирует массивные значения через `listState()`, чтобы chip-подписи не получали вложенные массивы или пустые элементы.
- `advancedFilterChips()` отбрасывает пустые display-значения, чтобы некорректные пустые query keys не создавали пустые активные chips.
- В `CatalogTitlesViewModel` добавлен `hasAdvancedFilters()`, а `<details>` расширенных фильтров открывается сразу при активных расширенных параметрах.
- На `/titles` добавлен `#catalog-filters`, мобильный переход «К фильтрам», desktop sticky-sidebar и более читаемый summary для расширенных фильтров.
- Добавлен `CatalogStatsPosterUrlGuard`, который отбрасывает не-HTTPS, неразрешимые, localhost, `.local`, private и reserved poster hosts.
- `CatalogStatsPageBuilder` использует `CatalogStatsPosterUrlGuard` и не рендерит `poster_src`, если poster proxy заведомо вернет `404`.
- Блок последних постеров `/stats` берет до 32 кандидатов и оставляет 8 proxyable строк, чтобы небезопасные poster URL не занимали видимые места с заглушками.
- `CatalogStatsPosterResponder` использует тот же guard перед внешним HTTP-запросом, чтобы safety-логика сборки snapshot и responder не расходилась.
- Git-правило `main` only зафиксировано в `AGENTS.md`, `README.md`, `docs/CODE_STANDARDS.md`, `docs/development.md` и этом плане.
- Временный stash, созданный только для переноса незакоммиченных правок на `main`, проверен и удален после успешного переноса.
- Документация синхронизирована с новым режимом polling:
  - `docs/UI_STANDARDS.md`
  - `docs/performance.md`
  - `docs/architecture.md`
  - `docs/MAINTENANCE_LOG.md`
  - планы в `docs/superpowers/plans`
- Выполнена проектная команда `php artisan project:docs-refresh`; управляемые блоки документации уже были актуальны.

## Почему эта правка выбрана первой

- Browser logs показывали повторяющиеся Livewire errors на `/stats`, связанные с модальным error dialog.
- `/stats` использует Livewire update endpoint под `throttle:catalog-stats`.
- Та же страница загружает poster proxy requests под тем же rate limiter.
- Polling каждую секунду создавал постоянный поток update-запросов даже тогда, когда пользователь не смотрит на страницу.
- Cache TTL 1 секунда означал, что тяжелая сборка stats snapshot могла запускаться почти на каждом poll.
- Backend logs показывали SQLite `database is locked` внутри `RateLimiter`, поэтому limiter counters отделены от общего database cache store.
- Backend logs показывали `foreach() argument must be of type array|object, string given` на `/titles`; root cause — Blade перебирал raw query-state, хотя URL может передать scalar вместо массива.
- Livewire 4 поддерживает `.visible` и интервалы в секундах, поэтому `wire:poll.15s.visible` уменьшает нагрузку без добавления зависимостей и без изменения публичной архитектуры.

## Проверки, которые выполнены в этом проходе

- `php -l app/Services/Catalog/CatalogStatsSnapshotCache.php`
- `php -l app/View/ViewModels/CatalogTitlesViewModel.php`
- `php -l config/cache.php`
- `./vendor/bin/pint --dirty --format agent`
- `php artisan project:docs-refresh`
- `npm run build`
- Laravel config smoke: `cache.limiter` возвращает `file`
- HTTP smoke:
  - `/`
  - `/titles`
  - `/titles?quality=1080p`
  - `/titles?exclude_country=rossiya`
  - `/stats`
  - `/feed.xml`
  - `/opensearch.xml`
- HTML smoke для `/titles`:
  - проверено наличие `id="catalog-filters"`
  - проверено наличие текста `К фильтрам`
  - проверено наличие текста `Расширенные фильтры`
  - проверено, что scalar `quality=1080p` отмечает чекбокс качества
  - проверено, что scalar `exclude_country=rossiya` сохраняется скрытым полем
- HTML smoke для `/stats`:
  - проверено наличие `wire:poll.15s.visible`
  - проверено наличие текста `Данные обновляются примерно раз в 15 секунд.`
  - проверено наличие `data-livewire-stats-dashboard`
- Browser QA через Chromium:
  - `/titles?quality=1080p&exclude_country=rossiya&video=available&rating_source=imdb&updated=week` проверен на desktop `1440x1200` и mobile `390x844`
  - `/stats` проверен на desktop `1440x1200` и mobile `390x844`
  - проверены HTTP status `200`, отсутствие horizontal overflow, отсутствие console/page errors, отсутствие failed requests и local `4xx/5xx`
  - первоначально найден root cause: `/stats` рендерил proxy image URLs для poster hosts, которые responder потом отвергал как `404`
  - после `CatalogStatsPosterUrlGuard` повторная browser QA завершилась без failures

## Ограничения этого прохода

- Новые test-файлы не создавались.
- PHPUnit и `php artisan test` не запускались.
- Playwright browser QA выполнена через уже доступный Chromium/playwright-core окружения; package.json, package-lock.json и test-файлы не изменялись.
- Production dependencies не добавлялись.
- `.env` не редактировался.

## План дальнейшего улучшения

### 1. Укрепить `/stats` против rate limit конфликтов

- Разделить лимиты для трех разных типов запросов:
  - страница `/stats`
  - poster proxy `/stats/poster/{catalogTitle:slug}`
  - Livewire update endpoint
- Оставить общий лимит `catalog-stats` только если разные типы запросов не мешают друг другу.
- Для Livewire update route в `AppServiceProvider` добавить отдельный limiter, например `catalog-stats-livewire`.
- Для poster proxy добавить limiter, рассчитанный на burst при первой загрузке страницы.
- Для самой страницы `/stats` оставить консервативный limiter, потому что HTML большой и публичный.
- Учитывать, что базовое отделение limiter counters в `CACHE_LIMITER_STORE=file` уже сделано; разделение named limiters нужно только если после этого останутся реальные `429` или lock errors.
- После разделения лимитов проверить HTTP status:
  - обычная загрузка `/stats`
  - загрузка нескольких poster proxy URLs
  - Livewire update request
- В документации зафиксировать назначение каждого limiter.

### 2. Сделать polling управляемым через config

- Добавить config value для stats polling interval в `config/catalog.php` или существующий project config, если он уже используется для каталога.
- В PHP хранить интервал snapshot TTL в одном месте.
- В Blade не вычислять config напрямую, если это усложняет шаблон; лучше передать готовую строку или число через Livewire component.
- В `StatsDashboard` передавать в view:
  - `pollIntervalSeconds`
  - `freshMessage`
- В Blade использовать готовый modifier нельзя собрать динамически обычной строкой без риска нечитаемой разметки, поэтому нужно выбрать один из двух вариантов:
  - оставить фиксированный `wire:poll.15s.visible`, если интервал меняется редко;
  - вынести root-разметку в несколько явных веток, если нужен runtime interval.
- Для текущего проекта фиксированный 15-секундный interval выглядит проще и безопаснее.

### 3. Уменьшить размер HTML `/stats`

- Текущий `/stats` HTML большой, потому что страница выводит много блоков сразу.
- Найти самые тяжелые секции по размеру HTML:
  - route rows
  - database table rows
  - taxonomy rows
  - recent import runs
  - poster rows
- Для каждой тяжелой секции оценить, нужна ли она на первом экране.
- Секции ниже первого экрана можно перевести на раскрываемые `<details>` без потери доступности.
- Для длинных таблиц использовать лимит видимых строк и ссылку-якорь на полный блок, если полный блок остается на той же странице.
- Не скрывать важные health indicators: верхние cards и проблемные строки должны остаться видимыми без раскрытия.
- Сохранить русские подписи и светлую тему.

### 4. Поддерживать полностью безопасный query-state контракт `/titles`

- Не возвращать прямые чтения ключей массива `$filterView->catalogQueryState` в `resources/views/catalog/titles.blade.php`.
- Для scalar inputs использовать только `CatalogTitlesViewModel::scalarState()`.
- Для list inputs использовать только `CatalogTitlesViewModel::listState()`.
- Для будущих multi-select фильтров добавлять helper в ViewModel, а не делать нормализацию в Blade.
- Сохранять правило: если расширенные фильтры активны, блок `<details>` остается открытым через `CatalogTitlesViewModel::hasAdvancedFilters()`.
- Проверять URL-формы:
  - `?quality=1080p`
  - `?quality[]=1080p`
  - `?exclude_country=rossiya`
  - `?exclude_country[]=rossiya`
  - `?year=2025`
  - `?year[]=2025`
- Сохранять мобильный порядок: результаты выше фильтров, фильтры ниже выдачи.
- Не добавлять JavaScript для базовой работы фильтров; GET-форма должна оставаться рабочей без JS.

### 5. Укрепить Livewire snapshot payload

- Проверить, что Livewire payload не содержит:
  - raw source URLs
  - private playback URLs
  - HTML snapshots source pages
  - stack traces
  - tokens
  - cookies
- Повторно пройти `CatalogStatsSnapshotSanitizer`.
- Добавить в sanitizer явные правила для новых типов данных, если stats page начнет показывать новые поля.
- Если данные не предназначены для UI, не передавать их в stats snapshot.
- Для poster thumbnails продолжить использовать только `stats.poster` internal proxy route.
- Поддерживать единый `CatalogStatsPosterUrlGuard` для snapshot builder и responder, чтобы `/stats` не создавал browser requests к proxy URLs, которые responder сам отвергнет.
- Если появится отдельное хранилище trusted poster hosts, добавлять его только в guard, а не в Blade.

### 6. Оптимизировать сборку stats snapshot

- Измерить время `CatalogStatsSnapshotBuilder::build()` через локальный timing wrapper в ручной диагностике.
- Найти самые дорогие методы внутри `CatalogStatsPageBuilder`.
- Для каждого дорогого блока решить:
  - нужен ли блок на каждом refresh;
  - можно ли кешировать блок отдельно;
  - можно ли считать его через grouped query;
  - можно ли уменьшить набор колонок.
- Приоритетные кандидаты:
  - database index introspection
  - route table summaries
  - large taxonomy summaries
  - media quality progress
  - source page windows
- Не добавлять долгоживущий cache для данных, которые должны меняться сразу после `seasonvar:import`.
- Сохранить вызов refresh snapshot после завершения `seasonvar:import`.

### 7. Стабилизировать `/stats` UI на мобильных экранах

- Проверить первый экран на ширине 360 px.
- Убедиться, что badges `Показано` и `Снимок` переносятся без горизонтального скролла.
- Проверить длинные русские названия сериалов в блоках:
  - последние обновленные сериалы
  - требуют внимания
  - recent import runs
  - taxonomy rows
- Убрать любые `truncate`, `text-ellipsis`, `line-clamp` для видимого пользовательского текста.
- Для плотных metric rows использовать label/value структуру.
- Не добавлять темную тему и темные панели.

### 8. Улучшить fallback состояния `/stats`

- Сейчас stale snapshot показывает сохраненные данные и статусное сообщение.
- Нужно отдельно различать состояния:
  - fresh snapshot
  - rebuild in progress
  - stale snapshot after builder exception
  - no previous successful snapshot
- Для каждого состояния держать русскую подпись без технических слов для публичного UI.
- Логировать исключения через `report()` уже правильно; UI не должен показывать stack trace.
- Если нет успешного snapshot, показывать компактное пустое состояние с понятным текстом.

### 9. Улучшить безопасность poster proxy

- Проверить, что `CatalogStatsPosterResponder` не раскрывает исходный URL.
- Проверить, что responder ограничивает host и scheme.
- Проверить redirect behavior.
- Проверить `Content-Type`.
- Проверить максимальный размер ответа.
- Проверить timeout и connect timeout.
- Добавить cache headers для безопасных успешных изображений.
- Для ошибок poster proxy возвращать безопасный status без подробностей внешнего источника.

### 10. Привести docs к единому описанию `/stats`

- Держать один источник правды для:
  - Livewire polling interval
  - snapshot fresh TTL
  - stale TTL
  - limiter strategy
  - poster proxy safety
- После каждой правки `/stats` обновлять:
  - `docs/UI_STANDARDS.md`
  - `docs/performance.md`
  - `docs/architecture.md`
  - `docs/MAINTENANCE_LOG.md`
- После изменения управляемых блоков запускать `php artisan project:docs-refresh`.
- Не заменять проектные документы общим Laravel template text.

### 11. Проверять функциональность без PHP test-файлов в этом режиме

- Использовать `php -l` для измененных PHP-файлов.
- Использовать Pint после PHP-правок.
- Использовать `npm run build` после Blade/Tailwind/asset правок.
- Использовать HTTP smoke через `curl`:
  - `/`
  - `/titles`
  - `/stats`
  - `/feed.xml`
  - `/opensearch.xml`
- Использовать HTML marker checks через `rg`.
- Использовать Laravel Boost для чтения backend/browser logs.
- Не запускать `php artisan test`.
- Не запускать `./vendor/bin/phpunit`.
- Не создавать файлы в `tests/`.

### 12. Проверить текущие старые ошибки логов

- Backend logs:
  - проверить, повторяется ли `Undefined variable $years` после текущих коммитов;
  - проверить, повторяется ли `Vite manifest not found` вне окна `npm run build`;
  - проверить, повторяются ли ошибки stats page после уменьшения polling.
- Browser logs:
  - проверить, появились ли новые `showModal` ошибки после изменения на `wire:poll.15s.visible`;
  - если ошибки останутся, собрать статус Livewire update response;
  - если статус `429`, разделить limiter;
  - если статус `500`, смотреть последнюю backend exception;
  - если статус network error, проверить nginx/php-fpm timeout.

### 13. Следующий кодовый проход

- Начать с чтения свежих backend/browser logs через Laravel Boost.
- Если `/stats` errors больше не появляются, перейти к HTML size reduction.
- Если `/stats` errors повторяются, сначала разделить rate limiters.
- Если `/stats` отдаёт 200, но browser logs продолжают `showModal`, проверить Livewire update route отдельно.
- После каждой небольшой правки запускать:
  - `php -l` для измененного PHP-файла;
  - `./vendor/bin/pint --dirty --format agent`;
  - `npm run build`, если менялись Blade/Tailwind/JS/CSS;
  - HTTP smoke без PHPUnit.

### 14. Git workflow

- Работать только в существующей ветке `main`.
- Не создавать feature branches, worktree-ветки, временные ветки, PR-ветки или дополнительные `main`-подобные ветки без прямого нового указания пользователя.
- Если checkout оказался не на `main`, сначала безопасно перенести незакоммиченные изменения на `main`, затем продолжать работу только там.
- Перед commit проверить `git diff --check`.
- Перед commit проверить `git status --short --branch` и убедиться, что текущая ветка — `main`.
- Не коммитить изменения в ветках, отличных от `main`.
- В staged scope не включать test-файлы.
- Commit message должен описывать пользовательский эффект, например:
  - `Reduce stats dashboard polling load`
  - `Separate stats dashboard rate limits`
  - `Compact stats dashboard heavy sections`
- Push делать только если это явно требуется в текущей команде пользователя или уже является частью согласованного workflow.

## Критерии готовности следующего прохода

- `/stats` открывается с HTTP 200.
- HTML содержит актуальный Livewire poll modifier.
- Fresh snapshot message соответствует фактическому interval.
- `npm run build` завершается с exit code 0.
- Pint завершается с exit code 0 после PHP-правок.
- В рабочем дереве нет случайных test-файлов.
- Документация не содержит противоречащего старого режима polling.
- Финальный ответ явно говорит, какие проверки не запускались из-за запрета на PHP tests.
