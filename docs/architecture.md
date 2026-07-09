# Архитектура приложения

Обновлено: 09.07.2026

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
- `CatalogStatsPosterResponder` не передает исходные внешние URL постеров в Blade или Livewire payload и отвечает только за безопасную выдачу миниатюр для статистики.

## Запросы и валидация

- Входные параметры списка каталога нормализует и проверяет `CatalogTitlesRequest`.
- Query-параметры выбранной серии и видео на странице карточки проверяет `CatalogShowRequest`.
- Поддерживаемые типы фильтров перечислены в `App\Enums\CatalogFilterType`, а slug-значения проверяет `App\Rules\CatalogFilterSlug`.
- Сложные запросы и агрегированные счетчики каталога находятся в `CatalogTitleQuery`.
- Описание поддерживаемых фильтров, моделей связей и eager-load наборов находится в `CatalogTaxonomyRegistry`.

## Авторизация

- Основные страницы каталога остаются публичными read-only страницами.
- Служебная страница `/stats` тоже доступна как публичная read-only сводка, но остается под rate limiter и не раскрывает raw source URLs, приватные media URLs или stack traces.
- Livewire update endpoint дополнительно использует `throttle:catalog-stats`, а сам лимит рассчитан на нормальный `wire:poll.1s`.
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
