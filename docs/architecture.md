# Архитектура приложения

Обновлено: 12.07.2026

## Контроллеры

- Контроллеры остаются тонкими: принимают route/request зависимости, выбирают view или responder и не собирают сложные запросы, SEO-массивы или view state.
- Страницы каталога используют page-builder сервисы в `App\Services\Catalog`:
  - `CatalogHomePageBuilder` готовит данные главной страницы.
  - `CatalogTitlesPageBuilder` готовит выдачу каталога, фильтры, счетчики и SEO для списка.
  - `CatalogTitlePageBuilder` готовит страницу тайтла, выбранную серию, медиа, рекомендации и SEO.
- Sitemap, feed, OpenSearch и `llms.txt` обслуживает отдельный `CatalogSitemapController`, который делегирует XML/text-ответы в `CatalogSitemapResponder`.
- JSON API обслуживает `App\Http\Controllers\Api\CatalogTitleController`: контроллер только принимает Form Request/model binding и возвращает API Resources, а выбор публичных связей выполняет `CatalogApiTitleQuery`.
- `/stats` обслуживается тонким controller-view слоем: `CatalogController::stats()` отдает SEO и Livewire-обертку, live-данные рендерит `App\Livewire\StatsDashboard`, а постеры статистики отдает `CatalogStatsPosterResponder` через внутренний proxy-маршрут.

## Actions и сервисы

- Дискретные бизнес-операции оформляются как небольшие сервисы или action-классы с constructor/method injection; контроллеры и команды не должны держать тяжелую логику внутри `handle()` или action-методов.
- Сервисы возвращают типизированный результат или готовые данные для вызывающего слоя, а вывод сообщений, HTTP-ответы и консольные коды остаются в контроллере или команде.
- Не добавлять repository-классы для простых Eloquent-связей; reusable запросы остаются в query-сервисах, scopes или page-builder сервисах.
- `project:docs-refresh` делегирует обновление управляемых блоков документации в `App\Services\ProjectDocumentation\ProjectDocumentationRefresher`, а команда только печатает результат и возвращает код выхода.
- Статистика `/stats` собирается через `CatalogStatsSnapshotBuilder`, очищается `CatalogStatsSnapshotSanitizer` и кешируется `CatalogStatsSnapshotCache`; Livewire-компонент не хранит полный stats-массив в публичном состоянии.
- `CatalogStatsPosterUrlGuard` проверяет, можно ли безопасно проксировать внешний poster URL; `CatalogStatsPageBuilder` не рендерит `poster_src` для URL, которые guard отвергнет, а `CatalogStatsPosterResponder` повторно применяет тот же guard перед HTTP-запросом.

## Запросы и валидация

- Входные параметры списка каталога нормализует и проверяет `CatalogTitlesRequest`.
- `CatalogTitlesPageBuilder` один раз разбирает нормализованный `q` через `CatalogSearchQueryParser` и передает один неизменяемый `CatalogSearchQuery` в выдачу, контекстные счетчики связей и счетчики годов.
- Multi-select фильтры каталога передаются как повторяемые query-параметры: годы остаются набором допустимых годов, а relation-фильтры резолвятся пакетно по slug в `CatalogTitlesPageBuilder`.
- Query-параметры выбранной серии и видео на странице карточки проверяет `CatalogShowRequest`.
- Поддерживаемые типы фильтров перечислены в `App\Enums\CatalogFilterType`, а slug-значения проверяет `App\Rules\CatalogFilterSlug`.
- Сложные запросы и агрегированные счетчики каталога находятся в `CatalogTitleQuery`: сервис ограничивает выдачу опубликованными тайтлами, применяет распознанный в `q` год как обязательное условие, требует совпадения каждого значимого терма и для нескольких значений одного relation-фильтра использует grouped pivot-подзапрос с `count(distinct ...)`.
- Описание поддерживаемых фильтров, моделей связей и eager-load наборов находится в `CatalogTaxonomyRegistry`.

## Publication boundary

- `CatalogStatus` остаётся production metadata источника; публичную видимость определяют `PublicationStatus`, audience, availability window и soft-delete scope.
- Общие условия находятся в `HasPublicationAvailability`, а публичные page builders/API queries ограничивают сезоны, серии и media parents до eager loading и `withCount()`.
- `ReleaseKind` и составные unique keys отделяют specials от обычной нумерации. Relationship-модели отвечают за единый порядок, поэтому контроллеры и Blade не сортируют выпуски самостоятельно.
- Доступ `authenticated` пока означает только наличие `User`; entitlement/territory policy не вводится до появления реальной лицензионной модели.

## Поиск каталога

- Полная значимая фраза сначала проверяется на точное совпадение с основным или оригинальным названием и хэшами алиасов. При найденных ID дальнейший широкий текстовый поиск не выполняется.
- Если точного имени нет, каждый значимый терм образует отдельную `AND`-группу. Внутри группы варианты названия, описания, slug, внешнего ID, алиасов и имен связанных справочников объединяются через `OR`.
- Один распознанный год из `q` является жестким ограничением. Несовместимые годы из `q` и параметра `year` дают нулевую выдачу и не переходят к временному fallback.
- `CatalogTitleQuery` для запроса только из стоп-слов дает нулевое условие без `title` context, но сохраняет существующий `whereKey()` для title-scoped страниц. `CatalogTitlesPageBuilder` использует единственный paginator и не заменяет нулевой результат полным каталогом.
- Все варианты сортировки завершаются `catalog_titles.id DESC`, поэтому строки с одинаковыми годом, названием, счетчиками или `indexed_at` имеют устойчивый порядок.

## Авторизация

- Основные страницы каталога остаются публичными read-only страницами.
- `CatalogTitle::resolveRouteBindingQuery()` ограничивает все текущие публичные implicit bindings опубликованными тайтлами. Карточка, API show и `stats.poster` поэтому используют одну publication boundary; query-сервисы дополнительно сохраняют явный `published()` как защиту для вызовов вне HTTP binding.
- Служебная страница `/stats` тоже доступна как публичная read-only сводка, но остается под rate limiter и не раскрывает raw source URLs, приватные media URLs или stack traces.
- Livewire update endpoint дополнительно использует `throttle:catalog-stats`, а страница `/stats` использует щадящий `wire:poll.15s.visible`, чтобы не держать polling в скрытой вкладке и не перегружать общий лимит.
- Счетчики rate limiter используют `CACHE_LIMITER_STORE=file`, отдельно от основного `CACHE_STORE=database`, чтобы публичный throttle не усиливал SQLite write contention.
- Новые write/admin/import-control endpoints должны получать отдельный gate или policy до регистрации маршрута.

## Представление и SEO

- Blade получает готовые переменные и не использует `@php`/`@endphp`.
- Переменные для layout SEO готовит `AppLayoutData`.
- View state для фильтров и страницы тайтла находится в `App\View\ViewModels`.
- SEO, JSON-LD, breadcrumbs, поисковые фразы и related links готовит `CatalogSeoBuilder`.

## API Resources

- Публичные JSON-ответы используют ресурсы в `app/Http/Resources`, а не массивы в контроллерах.
- Ресурсы не раскрывают source URL, HTML-снимки, внутреннее состояние импортера, raw media URLs, ключи медиа или stack traces.
- Связи и счетчики в ресурсах добавляются только через `whenLoaded()` и `whenCounted()`; query-сервисы заранее загружают нужные отношения.
