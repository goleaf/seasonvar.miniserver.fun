# Единый list-only интерфейс каталога

## Цель

Убрать карточочные grid-представления контента и переключение «Сетка / Список». Главная, каталог, справочники, рекомендации и личные пользовательские коллекции должны использовать единый адаптивный list/table-подход, в котором постер виден полностью и не обрезается на телефоне или планшете.

Пользователь подтвердил вариант с одним responsive list layout. CSS Grid остаётся допустимым только как технический инструмент компоновки форм, фильтров, навигации, счётчиков и страниц; требование не запрещает саму CSS-технологию.

## Проверенный baseline и история

- В коммите `e805f8c` главная выводила `$latestTitles` линейными строками с постером около `44×64`.
- В коммите `5b5e5c6` обновления были сгруппированы по дате и показаны плотным списком без карточной сетки.
- Grid-блок «Последние обновления» с `$featuredTitles` появился позднее в `0bdb938`; отдельная нижняя «Лента обновлений по датам» сохранила те же latest-данные и создала дублирование.
- Сейчас `x-ui.poster-frame` всегда применяет `object-cover`, а для большинства layout дополнительно использует `scale-[1.02]`.
- Текущий horizontal layout задаёт постеру колонку `5rem` и минимальную высоту `7rem`, то есть отношение сторон около `5:7`, а не каноническое портретное `2:3`. Cover и overscan поэтому обрезают изображение даже при корректном исходном постере.
- `/titles` хранит `view=grid|list` в Livewire URL-state, дублирует переключатель на desktop/mobile и поддерживает две разные topology выдачи.
- Directory hubs, watchlist, ratings, Continue Watching и viewing activity используют многоколоночные card collections. Рекомендации уже являются одним списком, но используют широкий `16:10` frame, который обрезает портретный постер по высоте.

## Рассмотренные варианты

### 1. Единый адаптивный список — выбран

Одна строка и одна информационная иерархия используются на mobile, tablet и desktop. Размер постера и расположение вторичных метаданных меняются responsive-классами, но DOM и действия не дублируются. Решение проще для Livewire, клавиатуры, screen reader, browser history и тестов.

### 2. Настоящая таблица на desktop и отдельные строки на mobile

Даёт более плотные desktop-колонки, но требует двух вариантов DOM или сложного responsive table contract. Это повышает риск расхождения ссылок, Livewire keys, loading state и доступных имён, поэтому вариант отклонён.

### 3. Компактный текстовый список с минимальными изображениями

Экономит высоту, но не решает запрос на качественный видимый постер и ухудшает визуальное распознавание тайтлов. Вариант отклонён.

## Граница изменений

List-only применяется к контентным коллекциям:

- главная: «Последние обновления», «Новые серии», «Сейчас можно смотреть»;
- `/titles` и все year/taxonomy/search/filter варианты этой выдачи;
- directory hubs актёров, стран, жанров, режиссёров, сетей, студий, статусов, тегов, переводов, возрастных рейтингов и годов;
- «Советуем посмотреть» на странице тайтла;
- watchlist, ratings, Continue Watching, history и legacy `/watching`;
- другие коллекции, использующие общий catalog poster-card для перечисления тайтлов или эпизодов.

Не являются контентным grid-представлением и остаются без принудительной переделки:

- layout header/footer и desktop sidebar;
- формы, фильтры, алфавит и группы controls;
- панели счётчиков и техническая статистика;
- player controls, сезоны/серии и admin-формы;
- label/value структуры, где CSS Grid только выравнивает одну строку.

## Общий контракт списка и постера

`x-ui.poster-frame` остаётся единственной границей для `<img>`, но получает явный fit contract. Cover остаётся безопасным default для существующих не-списочных поверхностей; list layouts передают contain и отключают overscan.

Для каждой list-строки:

- poster frame имеет точное отношение сторон `2:3`;
- изображение использует `object-contain object-center`, без `scale-[1.02]` и без crop;
- свободное место у нестандартного исходника заполняет спокойный `slate-100`, а не растяжение изображения;
- ширина постера: `4rem` на телефоне, `5rem` с `sm` и `6rem` с `md`; высота получается из aspect ratio как `6rem`, `7.5rem` и `9rem`;
- строка остаётся двухколоночной даже на узком телефоне: постер слева, `minmax(0,1fr)`-контент справа;
- название и original title показываются полностью и переносятся, без `truncate`/`line-clamp`;
- badges и пользовательские действия естественно переносятся под названием;
- основной stretched link сохраняет единый большой click/touch target, вторичные taxonomy/action links остаются поверх него;
- соседние строки разделяются `divide-y`; отдельные border + shadow карточки внутри списка не используются.

`x-ui.poster-card` перестаёт поддерживать публичный `grid` layout. Общий default становится list/horizontal; compact остаётся для истории, но также использует `2:3` + contain. Recommendation переходит с широкого `16:10` crop на тот же портретный list frame.

## Главная страница

«Последние обновления» снова использует `$latestTitles`, а не `$featuredTitles`. Записи группируются по `indexed_at` и выводятся по датам внутри одной панели. Отдельная нижняя «Лента обновлений по датам» удаляется как дубль.

Каждый тайтл выводится через общий list layout с постером, названием, original title, последним сезоном, годом, числом сезонов/серий/видео и доступным личным состоянием. Описания в этом плотном homepage-блоке не выводятся.

«Новые серии» и «Сейчас можно смотреть» становятся одноколоночными divided lists. Специализированная строка новой серии сохраняет season, episode, quality и дату, но использует тот же poster contract.

`featuredTitles` остаётся частью home page builder только там, где его требует мобильный API resource; web Blade больше не использует эту коллекцию. Это не меняет read-only API contract.

## Каталог `/titles`

- Удаляются desktop и mobile controls «Вид», действие Livewire `setView()` и `filters.view` URL property.
- `view` больше не валидируется и не включается в web query builders, pagination, canonical/SEO state или generated links.
- Старые `?view=grid` и `?view=list` не влияют на результат и не продолжают распространяться через ссылки; остальная часть query string сохраняется.
- Выдача всегда является одним bordered `divide-y` list с `layout="horizontal" readable`.
- Loading targets исключают `setView`, остальные фильтры, сортировка, page size, pagination и GET fallback продолжают работать без изменения.
- Для list row всегда выбираются description, `latestSeason` и текущие card relations; это устраняет условную загрузку данных по удалённому layout-state и не добавляет Blade queries.
- Mobile API не получает параметр `view` и продолжает использовать `includeDescription: true`; удаление web-state не меняет JSON resource shape.

## Справочники и личные коллекции

Directory hubs заменяют 1/2/3/4/6-column cards одним bordered list. Каждая строка сохраняет canonical ссылку, полное имя и published title count; search, alphabet/decade controls, pagination, URL history и stable `wire:key` не меняются.

Watchlist и ratings используют общий readable title row. Rating/watchlist mutations остаются внутри строки, сохраняют policies и Livewire confirmation contract. Continue Watching, history и `/watching` используют один vertical list вместо многоколоночных wrappers; progress, primary action и недоступные состояния остаются видимыми.

Рекомендации сохраняют ordered rank, причины и единый `<ol>`, но получают портретный full-image frame. Алгоритм, fallback и recommendation data не меняются.

## Доступность и responsive-поведение

- Один DOM на всех viewport исключает расхождение desktop/mobile действий.
- Интерактивные controls сохраняют effective target не меньше `44×44 px`.
- На `390×844` постер не занимает большую часть строки, текст имеет `min-width: 0`, badges переносятся и горизонтального overflow нет.
- На `768×1024` постер увеличивается до `80×120`, но список не превращается в две или три колонки.
- На `1440×1200` постер достигает `96×144`; metadata может использовать внутреннее выравнивание label/value, но коллекция остаётся одной вертикальной лентой.
- Hover является только дополнительной обратной связью; все функции доступны touch и keyboard без hover.
- Empty/loading/error состояния остаются в том же list container и не создают внутренней прокрутки.

## Документация

`docs/UI_STANDARDS.md`, `docs/frontend.md` и `docs/views.md` обновляются как владельцы UI, frontend и Blade contracts. Устаревшие правила о grid cards, `view` state, directory card grids и широком recommendation crop удаляются. Управляемые `project-docs` блоки вручную не меняются.

## Проверка

TDD фиксирует:

1. poster frame умеет contain без overscan, при этом default non-list cover остаётся доступен;
2. poster-card list/compact/recommendation используют `2:3`, а публичный grid layout отсутствует;
3. главная выводит `$latestTitles` grouped list и не содержит `data-home-latest-updates-grid` или дублирующую ленту;
4. `/titles` не содержит view controls, `setView`, grid result classes и всегда рендерит horizontal readable rows;
5. web request, Livewire form, criteria, view model и query builders не распространяют `view` state;
6. directory results являются одним list на desktop/tablet/mobile;
7. watchlist, ratings, continue/history и `/watching` не используют многоколоночные content wrappers;
8. recommendation rows показывают полный портретный постер без crop;
9. API catalog index и home resources сохраняют существующую JSON shape;
10. длинные названия, alt-тексты, stable keys, empty/loading state и authorization controls сохраняются.

После каждого RED/GREEN цикла запускаются focused PHPUnit tests. Перед завершением выполняются Pint, соответствующие unit/feature tests, полный `php artisan test`, `npm run build` и Playwright на `/`, `/titles`, directory route, title detail и доступных library routes при `390×844`, `768×1024` и `1440×1200`. Browser QA собирает status, `h1`, panel headings, размеры/fit изображений, horizontal overflow, console/page errors, failed local assets и screenshots в `output/playwright/`.
