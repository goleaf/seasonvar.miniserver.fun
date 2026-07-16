# Общепроектный аудит Eloquent eager loading — план реализации

**Цель:** применить к Seasonvar правило из статьи Vivek Mistry «Your Laravel with() Queries May Be Loading Too Much Data»: во всех read-boundaries загружать у связей только используемые столбцы с обязательными primary/foreign keys, не возвращая `select *` в публичных списках, API, пагинации, экспортах и больших пакетах.

**Архитектура:** проекция принадлежит query/service boundary, который знает форму ответа. Для reusable catalog-card связей используются централизованные projection helpers; для доменных detail/export запросов — локальные явные списки. Mutation/import merge boundaries могут загружать полный изменяемый aggregate только там, где последующая логика действительно использует произвольные поля модели; такие исключения не переносятся в read-only код.

**Стек:** Laravel 13, PHP 8.5, Eloquent, SQLite, PHPUnit 12, Laravel Pint.

## 1. Инвентаризация

- [x] Прочитать исходную статью и закрепить обязательное включение ключей связи.
- [x] Получить AST-инвентарь `with()`, `load()` и `loadMissing()` в `app/`.
- [x] Разделить public/API/list/export/batch reads и mutation/import aggregate loads.
- [x] Сверить существующие централизованные проекции каталога и не дублировать их.

## 2. Публичный каталог и поиск

- [x] Проверить title cards, taxonomy summaries, title page, API, sitemap и playback на constrained projections.
- [x] Ограничить autocomplete полями карточки и отдельными bounded aggregates сезонов/серий.
- [x] Не eager-load-ить сезоны/серии ради счётчиков.
- [x] Закрепить query budget и SQL-форму тестом autocomplete.

## 3. Теги, подборки и публичные API

- [x] Ограничить локализованные подписи и aliases полями, которые реально читает presenter/resource.
- [x] Ограничить account export переводами, items и ключами вложенного тайтла.
- [x] Ограничить административный detail тегов переводами, aliases, synonym keys, provider mappings и legacy slugs.
- [x] Добавить runtime regression test, запрещающий `select *` для этих eager-loaded relations.

## 4. Обращения, отзывы, профили и технические ошибки

- [x] Проверить paginated/card/detail queries на явные related columns.
- [x] Ограничить diagnostics и одиночные authorization/notification relations минимальными ключами и полями решения.
- [x] Сохранить foreign keys во всех nested projections.
- [x] Выполнить focused regressions соответствующих доменов.

## 5. Импорт и пакетные операции

- [x] Проверить lazy/chunk queries импортера: snapshot, title, season, episode и media projections.
- [x] Для read-only planner/backfill/job queries добавить явные related columns.
- [x] Не урезать merge/manifest aggregate, если он намеренно переносит или сравнивает полный набор полей.
- [x] Задокументировать оставшиеся mutation-boundary исключения и причину.

## 6. Постоянный контракт

- [x] Обновить `docs/performance.md` правилами projections, ключей и исключений.
- [x] Обновить `docs/CODE_STANDARDS.md`, `docs/architecture.md` и карту документации без дублирования владельца темы.
- [x] Обновить `README.md` и посетительскую историю только измеримым результатом.
- [x] Добавить техническую запись в `CHANGELOG.md`.
- [x] Обновить управляемые блоки через `php artisan project:docs-refresh` и проверить `--check`.

## 7. Проверка

- [x] Запустить Pint после PHP-изменений.
- [x] Запустить focused projection/search/domain tests.
- [ ] Запустить полный `php artisan test`.
- [x] Запустить `npm run build` и responsive Playwright после frontend-изменений.
- [x] Проверить `git diff --check`, route contract, README и отсутствие случайного удаления чужих изменений.

Состояние 16.07.2026: AST/runtime projection gate повторно проходит после появления новых recommendation, HDRezka и Top-100 классов. Полный `php artisan test` остаётся открытым из-за активного параллельного writer-процесса в общей `main`: за последовательные 15- и 30-минутные condition-based monitor он не завершился и продолжал менять PHP/тесты/миграции. Это внешний blocker согласованного full-suite snapshot, а не разрешение считать частичный прогон полным.

Responsive Playwright-проверка общего поискового consumer повторно прошла `6/6` после изоляции tiered caches в browser runtime; она подтверждает, что constrained title projection доходит до UI как постер, год и доступные счётчики без cross-origin/stale HTML и без возврата к relation `select *`.

Финальный объединённый projection/search gate 16.07.2026 прошёл `24/24` теста и `166` утверждений; production build, docs freshness, PHP/JavaScript syntax, search routes, OpenAPI JSON и `git diff --check` также прошли. Полный suite по-прежнему не отмечен: в общей `main` одновременно выполняются другие тестовые группы и продолжаются записи в PHP/Blade/документацию.
