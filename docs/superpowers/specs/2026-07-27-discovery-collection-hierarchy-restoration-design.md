# Восстановление иерархии категорий подборок в discovery

Дата: 27.07.2026.

Статус: согласовано прямым запросом вернуть прежний список и делегированием
рекомендуемого решения без дополнительных вопросов.

## Контекст

`/discover/popular#collections` получает полный активный двухуровневый
справочник, но presenter оставляет только узлы с положительным public count.
В production ни одна из 1 403 подборок пока не классифицирована: 5 root и 31
child существуют, однако все имеют count `0`. Из-за этого посетитель видит
только пустой control «Все категории (0)» и не видит сам справочник.

## Рассмотренные варианты

1. Автоматически классифицировать production-подборки. Отклонено: существующий
   suggestion boundary уверенно предлагает категорию только для части данных,
   а произвольный fallback исказит редакционную классификацию и запишет БД.
2. Ослабить public eligibility и вернуть неклассифицированные подборки.
   Отклонено: запрос касается навигационного списка, а изменение visibility,
   quality и maximum-item policy расширило бы security/performance scope.
3. Всегда рендерить активный справочник как двухуровневый список, а нулевые
   узлы показывать неинтерактивно. Выбрано: восстанавливает требуемую
   иерархию без ложных результатов, записи БД и новых запросов.

## UX

- Категории и подкатегории выводятся одним семантическим вложенным списком на
  телефоне, планшете и desktop.
- «Все категории» остаётся реальным фильтром.
- Положительный root/child — кнопка минимум `44px`, сохраняющая существующее
  Livewire URL state и сбрасывающая только collection paginator.
- Нулевой root/child видим как текстовая строка со счётчиком `0`, но не
  является disabled/dead button.
- Выбранный bookmarked zero-count узел остаётся визуально отмеченным и может
  быть снят через «Все категории»/reset.
- Иерархия переносит длинные названия и не использует горизонтальную
  прокрутку, dropdown-only navigation или JavaScript вне Livewire.

## Data flow

`CatalogCollectionCategoryQuery::publicDirectoryTree()` остаётся владельцем
bounded tree и grouped counts. `CatalogCollectionExplorer` преобразует каждый
active root/child в scalar array с `count` и `is_filterable`; Blade только
рендерит готовую структуру. Для child action новый метод компонента принимает
недоверенные slugs, повторно нормализует их через canonical category query и
сбрасывает `collectionsPage`.

## Совместимость

Не меняются route names, query keys, pagination name, DB schema/data, public
eligibility, collection cards, translations, API, sitemap, canonical/noindex,
cache keys или invalidators. Другие discovery modes не получают collection
shell.

## Verification

- PHPUnit RED/GREEN на полный root/child list при нулевых counts и safe child
  selection.
- Existing collection directory, unified discovery, SEO и query-budget tests.
- Pint для изменённого PHP.
- Vite production build.
- Playwright desktop/mobile: HTTP `200`, hierarchy names, no horizontal
  overflow, console/page/first-party failures.
- Production curl после изменения подтверждает root и child labels в SSR.

## Rollback

Вернуть presenter positive-count filtering и прежний sidebar/select/pill
markup. Миграции, data rollback, cache flush и dependency rollback не нужны.
