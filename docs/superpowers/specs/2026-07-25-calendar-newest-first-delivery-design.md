# Новые даты календаря первыми и надёжная доставка — design

Дата: 25.07.2026  
Статус: `implemented_verified_live_delivery_unresolved`

## Пользовательский результат

Каноническая страница `/calendar` без параметра сортировки показывает группы
недавних релизов от новых дат к старым. Если в одном окне присутствуют
`28 мая 2026 г.` и `29 мая 2026 г.`, группа `29 мая` выводится раньше группы
`28 мая`.

Пользовательский выбор сохраняется:

- чистый `/calendar` использует «Сначала поздние»;
- `/calendar?sort=earliest` использует «Сначала ближайшие» и показывает
  `28 мая → 29 мая`;
- явная сортировка по названию продолжает использовать существующий порядок;
- upcoming/day/week/month/personal сохраняют свои прежние defaults и не
  наследуют newest-first только из-за изменения Recent.

## Подтверждённое состояние

Запрос уже покрыт Task 38 общего master-plan, а его реализация находится в
незакоммиченном общем рабочем дереве:

- `ReleaseCalendarPage` хранит пустое URL-state как route-specific default;
- Recent разрешает пустое значение как `ReleaseCalendarSort::Latest`;
- остальные views разрешают его как `ReleaseCalendarSort::Earliest`;
- `ReleaseCalendarQuery` сортирует записи до пагинации и добавляет
  детерминированный `id` tie-break;
- Blade выводит подготовленную коллекцию и не переворачивает даты.

Текущий `HEAD` всё ещё содержит прежний статический default `earliest`.
Следовательно, работающий результат пока зависит от незакоммиченного состояния
сервера и может исчезнуть при восстановлении checkout из Git. Новая Task 47 не
создаёт вторую реализацию Task 38: она закрепляет её тестами, документацией,
отдельной доставкой из `main` и проверкой после доставки.

Проверка живого сайта 25.07.2026 подтвердила:

- чистый `/calendar` выбирает `latest`;
- динамически найденная страница с нужным диапазоном выводит
  `1 июня → 31 → 30 → 29 → 28 мая 2026 г.`;
- явный `?sort=earliest` выводит
  `26 → 27 → 28 → 29 → 30 мая 2026 г.`;
- focused `ReleaseCalendarDefaultViewTest` проходит: 8 тестов,
  33 утверждения;
- Desktop/Mobile Playwright проходят 2 сценария, включая переход на
  `calendarPage=2`, сброс page при выборе `earliest`, возврат к clean
  `latest`, монотонность timestamps и отсутствие overflow/page errors;
- Vite собирает 24 модуля без нового календарного bundle.
- Финальный `git diff --check` проходит, а route inventory сохраняет 17
  calendar/localized/admin/legacy routes.
- `project:docs-refresh --check` и docs CI остаются
  `unresolved_shared_worktree`, потому что generated migration inventory
  требует чужую untracked migration Task 48; Task 47 её не поглощает.

Номер production-пагинации не является постоянным acceptance contract:
импорт добавляет записи и сдвигает страницы. Автоматические проверки используют
детерминированные fixtures, а production smoke находит и сравнивает фактически
присутствующие соседние date groups.

## Рассмотренные варианты

### 1. Route-specific default в Livewire и сортировка в query — выбран

`ReleaseCalendarPage` разрешает пустое URL-state относительно текущего view,
а `ReleaseCalendarQuery` применяет направление до `paginate()`. Это сохраняет
обычный чистый URL, стабильную пагинацию, явные пользовательские сортировки и
существующую доменную границу.

### 2. Всегда принудительно использовать `latest`

Вариант проще, но ломает `?sort=earliest`, пользовательский select и
хронологию upcoming/day/week/month/personal. Он отклонён как несовместимый.

### 3. Переворачивать готовые группы в Blade

Вариант визуально меняет одну страницу, но оставляет SQL и пагинацию в
противоположном порядке. Даты на границах страниц становятся непоследовательны,
а Blade начинает владеть бизнес-сортировкой. Вариант отклонён.

## Архитектура и поток данных

### Livewire URL-state

`ReleaseCalendarPage::$sort` хранит только явное отклонение от default:

- `#[Url(history: true, except: '')]`;
- пустая строка означает route-specific default;
- `defaultSort()` возвращает `Latest` только для `Recent`;
- `resolvedSort()` принимает allowlisted enum либо default;
- `changeSort()` сохраняет пустую строку, если выбран route default, и явный
  enum code в остальных случаях;
- `clearFilters()` возвращает сортировку к default текущего view;
- изменение сортировки сбрасывает только именованный paginator
  `calendarPage`.

Такой контракт соответствует установленному Livewire 4.3.3: `#[Url]`
поддерживает `except` и `history`, а именованный paginator сбрасывается через
`resetPage(pageName: 'calendarPage')`.

### Query и пагинация

`ReleaseCalendarQuery::entries()` остаётся единственной boundary чтения.
Он получает уже разрешённый `ReleaseCalendarSort` и применяет:

1. даты с известным значением раньше unknown;
2. `COALESCE(starts_at, date_value)` в направлении `desc` для `Latest` либо
   `asc` для `Earliest`;
3. частичные `release_year` и `release_month` в том же направлении;
4. стабильный `id` tie-break в том же направлении;
5. только затем `paginate(..., pageName: 'calendarPage')`.

Сортировка по названию сохраняет существующий title order и `id` tie-break.
Новый query object, cache service или projection не создаются.

### Presentation

Component передаёт Blade:

- `effectiveSort` для выбранного option;
- paginator DTO;
- `entryGroups`, полученные через сохраняющий порядок `groupBy()` над уже
  отсортированной page collection.

Blade только выводит группы в полученном порядке. В нём не появляются
`reverse()`, database query, `@php`, inline JavaScript или самостоятельное
сравнение дат.

## Неверный ввод, ошибки и fallback

- Неизвестный `sort` не становится SQL column или direction: он разрешается в
  route default.
- Явный `earliest|latest|title` проходит существующий enum allowlist.
- Смена сортировки сбрасывает страницу, поэтому старый высокий
  `calendarPage` не создаёт ложное пустое состояние.
- При ошибке query сохраняется существующий честный error state; интерфейс не
  подменяет его отсортированными фиктивными карточками.
- При отключённом JavaScript первоначальный серверный HTML уже имеет
  правильный default-порядок. Select остаётся progressive Livewire control.
- Rolling deployment до готовности схемы сохраняет существующий
  `ReleaseCalendarSchema` fallback и не затрагивается задачей.

## Совместимость и cross-feature impact

Изменяются только:

- route-specific default сортировки Recent;
- отображаемое выбранное значение sort-select;
- feature/browser regression coverage;
- календарный owner-документ, `README.md` при фактической visitor delivery,
  русский `CHANGELOG.md`, current plan и общий master-plan.

Сохраняются:

- все имена и URI calendar/localized/legacy routes;
- `ReleaseCalendarSort::{Earliest,Latest,Title}`;
- фильтры type/status/title, paginator name и shareable URL-state;
- day/week/month/upcoming/personal semantics;
- visibility, publication, audience, premium, region/legal и personal
  authorization boundaries;
- timezone и civil-date semantics;
- notification, subscription, correction, importer и administration flows;
- SEO canonical/noindex, sitemap и structured-data boundaries;
- `CacheDomain::ReleaseCalendar`, dimensions и targeted invalidation;
- RU/EN translation keys, accessible labels и mobile layout;
- schema, indexes, data, queues, storage, dependencies и environment.

Новых migrations, DML, cache keys, permissions, routes, translations,
dependencies, workers или asset modules не требуется.

## TDD и acceptance

### PHPUnit

`tests/Feature/ReleaseCalendarDefaultViewTest.php` создаёт две released-записи
с точными гражданскими датами и проверяет:

1. `/calendar`: `29 мая 2026` раньше `28 мая 2026`;
2. `/calendar?sort=earliest`: `28 мая 2026` раньше `29 мая 2026`;
3. чистый Recent не требует query-параметра `sort`;
4. upcoming сохраняет ascending default;
5. invalid sort не открывает произвольный SQL order;
6. именованный paginator сбрасывается при смене sort.

Отдельный query-test создаётся только если существующий feature/component
контракт не может наблюдать направление до пагинации. Дублирующий тест-класс
без дополнительного доказательства не добавляется.

### Playwright

`tests/browser/release-calendar.spec.js` на Desktop Chromium `1440×1200` и
Mobile Chromium `390×844` проверяет:

1. чистый `/calendar` выбирает `latest`;
2. URL не получает лишний `sort` для default;
3. все видимые timestamps монотонно убывают;
4. выбор `earliest` добавляет `sort=earliest` и делает timestamps монотонно
   возрастающими;
5. возврат к `latest` очищает default-параметр;
6. Livewire обновляет тот же results region без browser/page errors;
7. нет horizontal overflow и отказов локальных assets.

Deterministic browser fixtures включают минимум две разные даты и достаточно
строк для проверки границы пагинации. Production smoke не создаёт данные и не
зависит от постоянного номера страницы.

### Verification и delivery

Минимальный gate:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=ReleaseCalendarDefaultViewTest
php artisan test --filter=ReleaseCalendar
npm run build
npx playwright test tests/browser/release-calendar.spec.js \
  --project="Desktop Chromium" --project="Mobile Chromium"
php artisan project:docs-refresh --check
git diff --check
```

Перед commit повторно проверяются branch/status, применимые requirements,
calendar legacy/duplicate implementations, README и русский CHANGELOG.
Завершённый scope коммитится только в существующую `main` и отправляется
обычным fast-forward push. Foreign dirty worktree остаётся `unresolved`, пока
его ownership нельзя отделить без потери или поглощения чужих изменений.

## Rollback

Rollback возвращает только route-specific default и matching
Blade/tests/docs к предыдущему состоянию. Схема и данные не меняются, поэтому
database restore, cache flush, queue cleanup, asset rollback и остановка
worker не требуются. После rollback чистый Recent снова использует `earliest`,
а явные sort values остаются совместимыми.

## Интеграция в безлимитный master-plan

Одобренная спецификация оформлена через `writing-plans` как конечная Task 47
в существующем append-only master-plan. Она:

- ссылается на Task 38 и не копирует её как вторую feature;
- закрывает Git/delivery regression между рабочим деревом и `HEAD`;
- добавляет недостающие deterministic acceptance gates;
- заканчивается отдельным commit/push gate;
- сохраняет уже выделенный следующему workstream Task 48 и оставляет Task 49+
  для нового evidence-backed intake без верхнего лимита и без перенумерации
  истории.
