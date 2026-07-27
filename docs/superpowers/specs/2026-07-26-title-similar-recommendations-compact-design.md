# Компактные похожие сериалы на странице тайтла

## Цель

Сделать блок «Похожие сериалы» быстрым для просмотра: сначала шесть строк,
следующие шесть по явному раскрытию, а главное содержание каждой строки —
правдивое объяснение сходства.

## Контракт данных

- `CatalogRecommendationService` сохраняет существующий bounded contract и
  возвращает не более 12 computed recommendations.
- `CatalogTitlePageBuilder` готовит полную совместимую коллекцию
  `recommendationItems`, а также две presentation-коллекции: первые шесть и
  продолжение не более шести.
- Blade не выполняет query, сортировку, slicing или вычисление причин.
- Для compact title-page feedback subject options не загружаются: прямое
  действие `not_similar` уже валидируется enum, visibility query и service.
- Public routes, Livewire method names, DTO shape и stored feedback values не
  меняются.

## Presentation

- Одна безрамочная строка содержит poster `2:3`, русское и оригинальное
  название, `год · сезоны · IMDb/КиноПоиск`, до трёх broad-причин, максимум
  двухстрочный plain-text excerpt и ссылку «Подробнее».
- Первые шесть строк видимы сразу. Следующие не более шести находятся в
  native `<details>` с `<summary>` «Показать ещё 6» и доступны без JavaScript.
- Полное описание, genre pills, rank, score и высокий nested feedback menu не
  выводятся.
- Авторизованный пользователь видит «Больше похожего» и «Не похоже»; controls
  имеют visible focus, loading/disabled и touch target 44 px.

## Совместимость и безопасность

- Admin-only correction controls из Task 105 не возвращаются.
- Blade продолжает экранировать пользовательский текст; excerpt и причины
  дополнительно очищаются и ограничиваются server-side.
- Feedback title повторно выбирается через `CatalogTitleQuery::visibleTo()`;
  client ID/reason не считаются доверенными.
- Миграции, индексы, routes, cache keys, packages и JavaScript не требуются.

## Проверка

- Feature/component tests фиксируют 6+6, отсутствие 13-й строки, три причины,
  IMDb-first metadata, bounded excerpt и компактный feedback.
- Query log подтверждает отсутствие шести feedback-option queries.
- Livewire budget, translation parity, focused suite, Pint, build и browser
  checks подтверждают регрессии и доступность.
