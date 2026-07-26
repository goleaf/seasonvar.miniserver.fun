# Новая персонализированная главная — design specification

Дата: 26.07.2026.

Статус: approved by the explicit user brief; реализация продолжается без
дополнительной остановки на подтверждение.

## Цель

Сделать `/`, `/ru` и `/en` короткой точкой входа в каталог, где сериалы
визуально важнее статистики и справочной навигации, а вошедший пользователь
сразу возвращается к своему просмотру, библиотеке и рекомендациям.

## Исходная проблема

Текущая главная использует пять одинаковых metric-карточек, широкую колонку
справочной навигации и несколько вложенных panel/list оболочек. Один тайтл с
массово добавленными сериями занимает много вертикального пространства,
потому что каждая серия и каждый видеовариант выводятся отдельной строкой.
Гостевая и authenticated страницы имеют почти одинаковый порядок и не
ставят личное продолжение просмотра первым.

## Рассмотренные подходы

### A. Только переставить существующие Blade-блоки

Минимальный diff, но нет отдельного источника трендов, настоящих новых
тайтлов, продолжения просмотра и обновлений личной библиотеки. Подход не
выполняет обязательный authenticated порядок.

### B. Расширить существующую web-проекцию builder

`CatalogHomePageBuilder::webData()` получает bounded guest/auth sections,
используя существующие `CatalogRecommendationService`,
`CatalogViewingActivityQuery`, `UserLibraryQuery`,
`CatalogHomeContentAdditionQuery` и текущие cache boundaries. API-проекция
`data()` сохраняет прежнюю форму. Это выбранный вариант: он не вводит новый
архитектурный слой и оставляет ranking, visibility и personal ownership в
канонических сервисах.

### C. Создать отдельный homepage-dashboard domain

Даёт изоляцию, но дублирует builder, recommendation orchestration, cache
dimensions и title hydration. Для одной presentation-задачи это
необоснованная абстракция и повышенный риск расхождения web/API.

## Информационная архитектура

### Гость

1. компактная статистика портала;
2. «В тренде»;
3. сгруппированные последние обновления;
4. новые сериалы;
5. сейчас можно смотреть;
6. тематические подборки;
7. поиск по жанрам, странам и годам.

### Вошедший пользователь

1. продолжить просмотр;
2. новые серии из моей библиотеки;
3. рекомендовано для меня;
4. «В тренде»;
5. сгруппированные последние обновления;
6. мои подборки и календарь.

Пустые personal sections не исчезают бесследно: они показывают короткое
состояние и реальную ссылку на каталог/библиотеку. Гостевые общие секции не
подменяются персональными данными.

## Источники данных

- статистика — существующий `CatalogHomeMetricsCache` плюс facet counts;
- тренды — `CatalogRecommendationService` с
  `CatalogRecommendationType::Trending`, максимум пять тайтлов;
- новые сериалы — тот же recommendation boundary с
  `CatalogRecommendationType::RecentlyAdded`, максимум шесть;
- последние обновления —
  `CatalogHomeContentAdditionQuery::latestReleaseGroups()`, максимум шесть
  тайтлов;
- доступно к просмотру — bounded `video_title_ids` текущего homepage
  snapshot;
- подборки — текущий `CatalogCollectionQuery::featured()`;
- продолжение просмотра —
  `CatalogViewingActivityQuery::continueWatching()`, максимум шесть;
- обновления библиотеки — новый compact read поверх существующего
  `CatalogPersonalUpdateQuery`, максимум шесть;
- персональные рекомендации — существующий `Personalized` result, максимум
  шесть.

Recommendation score, private history, raw media URL и importer state в
Blade не передаются.

## Группировка обновлений

Один тайтл занимает одну строку с постером. Для последовательных номеров
серий выводится диапазон, например «Добавлены серии 185–194». Metadata
содержит сезон, точное количество новых серий и локализованное время
последнего добавления. Для непоследовательных номеров используется
правдивое количество без выдуманного диапазона; для media-only события —
отдельная подпись добавленного видео.

Primary action ведёт к последнему реально доступному media/episode, а
secondary — к `#seasons` полной карточки. Скрытые, удалённые, будущие и
недоступные release rows остаются исключены текущими scopes.

## Карточки

Обычная homepage-карточка содержит:

- постер строго `2:3`;
- русское и оригинальное название;
- год;
- максимум два жанра;
- доступный рейтинг;
- одну строку количества сезонов/серий;
- без полного описания.

Spotlight тренда отличается только на desktop: занимает большую область,
показывает описание максимум в четыре строки, одну причину «Растёт интерес
зрителей» и кнопки «Смотреть»/«Подробнее». На mobile все пять кандидатов
становятся обычной двухколоночной сеткой без отдельной hero-композиции.

## Статистика и поверхности

На desktop статистика является одной строкой с разделителями. На mobile —
`2 + 2 + 1`; последний показатель занимает ширину двух колонок. У метрик
нет самостоятельных белых карточек, теней или крупных иконок.

Homepage sections используют заголовок, отступ и нижний divider. Общая
facet-навигация остаётся такой же обычной секцией без внешней panel-рамки.
Карточки контента не вкладывают одинаковые panel shells друг в друга.

## Accessibility и responsive

- один server-rendered `h1` остаётся скрытым только визуально;
- каждый section получает связанный `h2`;
- все действия имеют минимум `44px`, видимый `focus-visible` и русский/английский
  accessible text;
- mobile начинает с двух колонок и не создаёт horizontal overflow;
- poster alt, progress label, empty state и section navigation локализованы;
- порядок DOM совпадает с визуальным порядком;
- контент не использует внутренние scroll-зоны.

## Безопасность, cache и совместимость

- authenticated request уже bypass-ит shared public response cache;
- personal queries выполняются только при ненулевом server-authenticated
  `User`;
- guest `webData(null)` не читает personal tables и не содержит user ID;
- `/api/v1/home`, его Resource keys, routes, query parameters, SEO и locale
  aliases не меняются;
- все URL строятся named routes, output экранируется Blade;
- нет write endpoint, новой validation boundary, migration, package, cache
  key или environment variable.

Rollback: вернуть builder/view/component/translation/docs изменения и
пересобрать Vite assets. Database restore, cache flush и data backfill не
нужны; старый API contract остаётся доступным на протяжении deploy.

## Acceptance

1. Guest и authenticated section markers следуют указанному порядку.
2. Guest HTML не содержит personal sections/data.
3. Authenticated HTML показывает только данные текущего владельца.
4. Статистика имеет один desktop row и mobile `2 + 2 + 1`.
5. Trending содержит максимум пять, один desktop spotlight и обычную mobile
   grid.
6. Массовое обновление из десяти серий создаёт одну карточку с диапазоном и
   точным count, а не десять событий.
7. Обычные homepage cards не выводят description и показывают не более двух
   жанров.
8. Query count bounded, N+1 отсутствует, API payload не меняется.
9. Focused/full PHP tests, Pint, PHPStan, Vite build и Playwright
   desktop/mobile проходят; screenshots просмотрены вручную.
