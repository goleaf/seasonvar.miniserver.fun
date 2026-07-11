# Исправление строк проблем статистики

Дата: 11.07.2026

## Цель

Закрыть регрессию в сборке данных для страницы статистики: `CatalogStatsPageBuilder::statsIssueRows()` должен возвращать строки из всех категорий проблем каталога, даже если первая категория пуста.

## Контекст

В рабочем дереве уже есть изменение `CatalogStatsPageBuilder`, которое начинает merge со свежей `Collection`, а не с результата первого Eloquent-запроса. Это сохраняет текущую архитектуру тонкой сборки данных в сервисе и не меняет Blade, маршруты, миграции или публичный импорт.

## Подход

Оставить исправление локальным для `statsIssueRows()`:

- собрать buckets `withoutPublishedMedia`, `withoutPoster` и `withoutDescription` как раньше;
- объединять готовые массивы строк через `collect()->merge(...)`;
- сохранить `unique('id')`, сортировку и лимитирование результата;
- не добавлять новый сервис и не менять формат данных, который потребляет `/stats`.

## Тестирование

Добавить или сохранить feature regression test в `CatalogPageTest`, который создает два тайтла из разных категорий проблем: без постера и без описания. Тест должен вызывать `CatalogStatsPageBuilder::data()` и проверять, что оба названия попали в `statsIssueRows`.

Проверки для этого изменения:

- `php artisan test --filter=test_stats_issue_rows_merge_multiple_issue_categories`;
- `php artisan test --filter=CatalogPageTest`;
- `./vendor/bin/pint --dirty --format agent` после PHP-правок.

## Не входит в задачу

Не пересобирать рекомендации, не запускать destructive DB-команды, не менять importer lifecycle и не расширять Playwright matrix в рамках этого узкого исправления. Эти шаги остаются в отдельном QA-плане рекомендаций.
