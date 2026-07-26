# Компактные карточки каталога

Дата: 26.07.2026.

Статус: approved by explicit user requirement.

## Проблема

Общий `x-catalog.title-card` используется на `/titles`, главной, в
рекомендациях и в соседних публичных списках. Сейчас list/recommendation
варианты могут помещать в HTML полное описание, смешивают разные справочники
и выводят до четырёх причин рекомендации. Длинные строки увеличивают высоту
страницы и замедляют сканирование выдачи.

## Решение

Единый class-based Blade component остаётся presentation boundary и готовит
для обоих вариантов карточки:

- plain-text excerpt описания не длиннее 240 Unicode-символов с сохранением
  целых слов; в HTML не попадает скрытая полная копия описания;
- `line-clamp-3` только как дополнительный layout guard для уже ограниченного
  server-side excerpt;
- не более трёх уже eager-loaded жанров;
- год, предпочитаемый внешний рейтинг и число доступных серий;
- явную обычную ссылку «Подробнее» на существующий canonical title route;
- ровно одну наиболее значимую broad-причину рекомендации.

Предпочитаемый рейтинг — КиноПоиск, fallback — IMDb. Карточный loader
загружает только `catalog_title_id`, `provider` и `rating` для этих двух
provider одним bounded eager-load запросом. Компонент не делает lazy loading
и скрывает рейтинг, когда достоверного значения нет.

## Почему выбран этот вариант

### Выбран: общий server-side presentation projection

Один компонент сохраняет одинаковое поведение `/titles`, главной и
рекомендаций. Ограничение текста до render реально уменьшает HTML, а
eager-load рейтингов остаётся фиксированным запросом на коллекцию.

### Отклонён: только CSS `line-clamp`

Полное описание осталось бы в HTML и accessibility tree. Высота уменьшилась
бы визуально, но payload и скрытый лишний контент сохранились бы.

### Отклонён: отдельная карточка для каждой страницы

Три partial быстро разошлись бы по метаданным, доступности, персональному
состоянию и исправлениям. Это вернуло бы дублирование, которое уже устраняет
`x-catalog.title-card`.

## Data flow

1. Существующие page builders выбирают прежние карточные строки.
2. `CatalogTaxonomyRegistry::cardSummaryLoads()` добавляет bounded relation
   `ratings` рядом с уже ограниченными relations карточки.
3. `TitleCard` использует только attributes/loaded relations, готовит excerpt,
   жанры, rating label и первую reason label.
4. Blade выводит escaped данные, три строки excerpt и normal-link fallback.
5. Полная информация остаётся на существующем `titles.show`; API, SEO,
   recommendation ranking и feedback writes не меняются.

## Безопасность и доступность

- Provider/editorial description очищается от HTML, схлопывает пробелы,
  ограничивается до render и выводится через `{{ }}`.
- Route строится существующим route model binding; новых входных параметров,
  write actions, policies или IDOR-boundaries нет.
- «Подробнее» имеет видимый focus, минимум 44 px по высоте и остаётся
  отдельной клавиатурной ссылкой поверх stretched title link.
- Причина остаётся broad и не раскрывает score, source title, private history
  или внутренние веса.

## Производительность

- Полное описание не дублируется в DOM.
- Rating relation ограничена двумя provider и тремя колонками.
- Число запросов увеличивается не на карточку, а максимум на один eager-load
  statement для всей коллекции.
- Genres и episode count используют существующие eager/batch boundaries.
- Новых cache keys, invalidation, remote calls, JavaScript или зависимостей
  нет.

## Совместимость и rollback

Сохраняются routes/names, query string, paginator, Livewire public state,
DTO shape `reasonLabels`, feedback actions, personal state, poster contract,
API Resources, SEO и model relations. Изменяется только содержимое общей
публичной карточки по прямому требованию пользователя.

Rollback — revert code/templates/translations/docs. Миграций, DML, cache
flush и asset rollback не требуется.
