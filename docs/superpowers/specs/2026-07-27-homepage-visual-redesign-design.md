# Полный визуальный редизайн главной страницы

Дата: 27.07.2026.

Статус: approved. Пользователь подтвердил рекомендованный вариант
сбалансированных semantic-поверхностей и прямо разрешил начать реализацию
только в существующей `main`.

## Цель

Сделать `/`, `/ru` и `/en` удобной точкой входа на телефоне, планшете и
desktop: пользователь должен открывать сериал нажатием на постер или основную
площадь карточки, быстро различать секции и видеть реальные действия без
визуального шума.

## Подтверждённая проблема

Live-аудит через Playwright MCP на `1440×1200` и `390×844` подтвердил:

- постеры `home`, `spotlight`, `trend`, latest-media и Continue Watching
  находятся вне ссылок;
- секции почти не отличаются друг от друга и образуют длинное бело-slate
  полотно;
- ссылки «Показать всё» выглядят как малозаметный текст, особенно на phone;
- существующая информационная архитектура, данные и responsive grid работают
  и не требуют нового route, API, carousel или client-side fetch.

## Рассмотренные подходы

### A. Сбалансированные semantic-поверхности — выбран

Amber отделяет тренды, sky — информационные обновления, emerald — доступное
видео и личное продолжение, slate — справочную навигацию. Карточки остаются
белыми, без теней; emerald остаётся единственным action-цветом.

Преимущества: заметное улучшение ритма без новой темы, совместимость с
`UI_STANDARDS.md`, контролируемый контраст и минимальный runtime-риск.

### B. Один яркий trending-блок

Улучшает только верх страницы, но оставляет остальные секции визуально
неразличимыми и не решает длинное mobile-полотно.

### C. Отдельный сильный цвет каждой секции

Даёт максимум различий, но превращает каталог в разноцветный dashboard,
ослабляет content-first иерархию и конфликтует с фиксированными цветовыми
ролями проекта.

## Информационная архитектура

Server-rendered порядок Task 94 не меняется.

Guest:

1. компактная статистика;
2. «В тренде»;
3. «Последние обновления»;
4. «Новые сериалы»;
5. «Сейчас можно смотреть»;
6. тематические подборки;
7. полный поиск по жанрам, странам и годам.

Authenticated:

1. «Продолжить просмотр»;
2. обновления библиотеки;
3. персональные рекомендации;
4. «В тренде»;
5. «Последние обновления»;
6. мои подборки и календарь;
7. полный поиск по жанрам, странам и годам.

## Визуальная система

- Тема остаётся только светлой, фон страницы — текущий `slate-50`.
- Большие section-surfaces используют `rounded-panel`, один border и
  внутренний responsive padding; тени запрещены.
- Trending: `amber-50`/`amber-200`.
- Обновления и редакционные подборки: `sky-50`/`sky-200`.
- Просмотр, Continue Watching и личные действия: `emerald-50`/
  `emerald-200`.
- Нейтральные каталожные и facet-блоки: `slate-100`/`slate-200`.
- `red` остаётся только error-ролью и не используется декоративно.
- Внутренние title cards — белые, с `slate-200` border, без shadow.
- Section actions получают постоянную область не меньше `44px`, видимый
  border/focus и не зависят от hover.
- Heading scale, системный шрифт, локальные FontAwesome и `2:3` poster
  contract сохраняются.
- Не добавляются gradient, dark section, noise asset, web-font, animation
  package, fake image, marketing copy или декоративный hero.

## Кликабельность карточек

Основная title-ссылка получает существующий project pattern
`after:absolute after:inset-0` относительно `x-ui.poster-card`. Псевдоэлемент
покрывает постер и свободную площадь карточки без вложенного `<a>`.

Обязательные детали:

- `cursor-pointer` сохраняется на основной ссылке;
- keyboard focus рисует ring по полной карточке через
  `focus-visible:after:*`;
- genre chips, «Смотреть», «Подробнее», «Показать серии» и Continue Watching
  CTA остаются `relative z-10` и выполняют собственные действия;
- Continue Watching overlay ведёт к exact episode + `#player`, а не к общей
  title page;
- в markup добавляется стабильный `data-home-title-link` для feature/browser
  regression;
- доступное имя ссылки остаётся видимым названием тайтла;
- `wire:navigate`, JavaScript click handler и вложенные anchors не нужны.

## Компоненты и границы

- `CatalogHomePageBuilder` и query classes не меняют data selection.
- `catalog-home-page.blade.php` отвечает только за approved section layout.
- `TitleCard` сохраняет prepared labels/counts/relations.
- `PosterCard` получает только presentation classes для layouts `home`,
  `spotlight` и `trend`; остальные layouts должны остаться byte-compatible
  по class contract.
- `latest-media-card` переиспользует тот же overlay pattern.
- Public routes, API Resources, recommendation ranking, visibility,
  entitlement, locale и owner-only state не меняются.

## Cache, production и rollback

Guest homepage является full-response cached. Markup redesign повышает
`PublicPageCachePolicy` homepage `response_contract` с `2` до `3`, чтобы
старый HTML стал недостижим без wildcard scan или broad flush.
Authenticated homepage продолжает bypass shared response cache.

Rollout: application commit, Vite production build, штатный config/route/view
refresh и smoke `/`, `/ru`, `/en`. Migrations, data writes, package install и
backup не требуются.

Rollback: согласованный revert PHP/Blade/tests/docs, Vite rebuild и возврат
предыдущего response contract. Database restore и cache flush не требуются.

## Accessibility и responsive

- Viewport matrix: `320×720`, `390×844`, `844×390`, `768×1024`,
  `1024×768`, `1440×1200`, `1920×1080`.
- Нет horizontal page overflow, hover-only actions или перекрытия controls.
- Focus order следует DOM, ring видим на карточке и section action.
- Touch targets essential controls не меньше `44px`.
- Long RU/EN labels переносятся без обрезки essential copy.
- Reduced motion поддерживается; новая обязательная анимация не добавляется.
- Полные genres/countries/years и текущие focusable bounded regions Task 111
  сохраняются.

## Verification

1. TDD RED/GREEN для `data-home-title-link`, overlay classes, independent
   controls и redesigned section markers.
2. TDD RED/GREEN для homepage cache `response_contract=3`.
3. Focused homepage/card/cache/translation/Blade tests, затем broad
   homepage matrix и full backend gate.
4. Pint для PHP, focused Larastan/Rector и `npm run build`.
5. Playwright guest/auth, RU/EN, poster click, keyboard focus, viewport
   default/extended, overflow, console/network и screenshots.
6. Повторный requirements/compliance review, repository-wide legacy search,
   README/CHANGELOG/owner docs, exact commit в `main` и push attempt.

