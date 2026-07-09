# Архитектура приложения

Обновлено: 09.07.2026

## Контроллеры

- Контроллеры остаются тонкими: принимают route/request зависимости, выбирают view или responder и не собирают сложные запросы, SEO-массивы или view state.
- Страницы каталога используют page-builder сервисы в `App\Services\Catalog`:
  - `CatalogHomePageBuilder` готовит данные главной страницы.
  - `CatalogTitlesPageBuilder` готовит выдачу каталога, фильтры, счетчики и SEO для списка.
  - `CatalogTitlePageBuilder` готовит страницу тайтла, выбранную серию, медиа, рекомендации и SEO.
- Sitemap, feed, OpenSearch и `llms.txt` обслуживает отдельный `CatalogSitemapController`, который делегирует XML/text-ответы в `CatalogSitemapResponder`.

## Запросы и валидация

- Входные параметры списка каталога нормализует `CatalogTitlesRequest`.
- Сложные запросы и агрегированные счетчики каталога находятся в `CatalogTitleQuery`.
- Описание поддерживаемых фильтров, моделей связей и eager-load наборов находится в `CatalogTaxonomyRegistry`.

## Представление и SEO

- Blade получает готовые переменные и не использует `@php`/`@endphp`.
- Переменные для layout SEO готовит `AppLayoutData`.
- View state для фильтров и страницы тайтла находится в `App\View\ViewModels`.
- SEO, JSON-LD, breadcrumbs, поисковые фразы и related links готовит `CatalogSeoBuilder`.
