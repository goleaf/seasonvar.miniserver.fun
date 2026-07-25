# Навигация страницы тайтла и Livewire island для соседних серий — design

Дата: 24.07.2026  
Статус: `implemented_local`; Tasks 36–37 прошли PHPUnit и Desktop/Mobile
Playwright, commit/push остаются отдельной delivery boundary общего master plan

## Пользовательский результат

На странице тайтла каждый пункт быстрого доступа выполняет заявленное действие:

- «Смотреть» прокручивает к текущему проигрывателю;
- «Сезоны» прокручивает к списку сезонов и серий;
- «О сериале» прокручивает к справочным данным;
- «Отзывы зрителей» прокручивает к стабильной цели ещё до завершения lazy-загрузки отзывов;
- «Сообщить о недостающем материале» открывает предзаполненную форму, а для гостя после входа сохраняет возврат к ней;
- «Расписание сериала» открывает календарь, отфильтрованный по текущему тайтлу.

После бесшовного перехода между сериями кнопки «Предыдущая» и «Следующая» сразу
показывают соседей уже выбранной серии. Последовательные переходы `1 → 2 → 3`
работают без полной перезагрузки и без пересоздания `<video>`, Plyr или HLS
session.

## Подтверждённые причины

Browser-проверка тайтла `kizilovyi-shherbetkizilcik-serbeti` подтвердила:

- `#player` и `#seasons` существуют при первом HTML-ответе и прокручиваются;
- quick link «О сериале» указывает на `#data-title-reference`, но панель имеет
  только одноимённый data-атрибут, а не `id`;
- lazy-компонент отзывов первоначально выводит общий placeholder без
  `id="reviews"`, поэтому click/hashchange происходит раньше появления цели;
- активное состояние quick links статично закреплено за «Смотреть» и не
  отражает текущий hash или видимую секцию;
- гостевая кнопка заявки формируется как прямая ссылка на `login`, поэтому
  стандартный auth middleware не получает исходный защищённый URL как intended
  destination;
- календарная ссылка сама по себе корректна и ведёт на
  `/calendar/upcoming?title=<id>`.

Для player navigation подтверждена отдельная причина: JavaScript выполняет
renderless `commitPlayerTransition()` и обновляет runtime state/URL, но
серверный Blade-фрагмент навигации не morph-ится. После перехода на вторую
серию DOM продолжает содержать ссылку на вторую серию, и повторный click
коммитит уже текущий episode ID.

## Выбранная архитектура

### Стабильные section targets

Каждый внутренний quick link сохраняется обычным `<a href="#...">`. Цели
существуют в HTML независимо от JavaScript:

- `CatalogTitlePlayer` владеет `id="player"` и `id="seasons"`;
- панель справочных данных получает настоящий
  `id="data-title-reference"`;
- `CatalogTitleReviews` получает специализированный `placeholder()`, чей один
  root использует тот же `id="reviews"`, что и загруженный root компонента.

Placeholder остаётся честным loading state с `aria-busy="true"` и не выполняет
query. После Livewire lazy hydration один root заменяется другим без duplicate
ID и без изменения публичного deep-link contract.

### Progressive quick navigation

Существующий модуль `resources/js/app.js` остаётся единственным владельцем
anchor scrolling. В нём создаётся одна идемпотентная title-navigation session:

1. click по same-page hash сохраняет настоящий URL contract;
2. существующая smooth-scroll функция прокручивает к target с учётом
   `scroll-margin`;
3. hashchange, Back/Forward, initial deep link и Livewire morph повторно
   синхронизируют target;
4. один `IntersectionObserver` с учётом высоты sticky header назначает
   `aria-current="location"` ближайшей активной секции и только одному quick
   link;
5. активные/неактивные классы переключаются через стабильные data-маркеры, а
   смысл не зависит только от цвета;
6. повторная инициализация после Livewire navigation/morph не создаёт второй
   listener или observer.

При отключённом JavaScript native anchor navigation остаётся рабочей.
`prefers-reduced-motion` сохраняет немедленную прокрутку.

### Заявка и календарь

`CatalogTitleDetail` всегда формирует canonical URL защищённого
`requests.create` с безопасными `type` и `catalog_title_id`. Для гостя
существующий auth middleware перенаправляет на login и сохраняет intended URL;
после входа открывается предзаполненная форма. Новая auth/session boundary не
создаётся.

Календарная кнопка сохраняет обычный `href` с `title=<id>` без
`wire:navigate`. Полная GET-навигация является каноническим поведением, чтобы
переход не зависел от состояния вложенных Livewire-компонентов и плеера.
Существующий route, фильтр и calendar authorization не меняются.

### Navigation-only Livewire island проигрывателя

Только `<nav>` с кнопками соседних серий помещается в именованный island
`catalog-player-navigation`. Сам player shell остаётся внутри текущего
`wire:ignore` и не входит в island.

Island получает bounded presentation projection: текущую серию, допустимые
previous/next episode, их labels и canonical fallback URLs. Он не загружает
полный граф media, reviews, collections или user progress.

Порядок бесшовного перехода:

1. JavaScript просит существующий server resolver подготовить разрешённый
   episode/media transition;
2. тот же `<video>` и Plyr применяют подготовленный source;
3. commit вызывается через
   `$wire.$island('catalog-player-navigation').commitPlayerTransition(...)`;
4. сервер повторно валидирует episode/media, обновляет canonical component
   state и возвращает только island HTML;
5. Livewire morph-ит previous/next links, а player DOM identity сохраняется;
6. URL/history обновляются существующей navigation boundary.

`commitPlayerTransition()` перестаёт быть renderless только для island-call.
Prepare/menu actions остаются renderless. `always: true` гарантирует обновление
island и при существующих server-side fallback actions.

Каждая кнопка сохраняет canonical `href`; если JavaScript или island request
недоступны, следующий click выполняет полноценный безопасный GET.

## Ошибки и конкуренция

- Последний player transition generation побеждает; stale response не меняет
  source, URL или navigation island.
- Ошибка island commit не удаляет текущий source и показывает существующий
  player error state; fallback `href` остаётся доступным.
- Не найденный anchor не приводит к polling loop. Для ожидаемых lazy roots цель
  присутствует в специализированном placeholder.
- Livewire morph/navigation и `pagehide` отключают observer/listeners через
  одну cleanup boundary.
- Ошибка или недоступность календаря/формы отображается соответствующей
  целевой страницей; quick-navigation не симулирует успешный переход.

## Совместимость и cross-feature impact

Изменяются:

- title quick-navigation Blade contract;
- lazy placeholder отзывов;
- bounded anchor-navigation JavaScript;
- player navigation island и его presentation projection;
- seamless player commit bridge;
- feature/browser/contract tests;
- frontend/playback documentation, `README.md`, `CHANGELOG.md`, current task
  evidence и единый master plan.

Без изменений остаются:

- public route names, route model binding, query parameter identities и
  canonical SEO URL;
- review filters, pagination island, moderation и review write policies;
- content-request authorization/policies и persisted identity;
- release-calendar data, filtering, cache keys и notification domain;
- player grant, entitlement, premium/region/legal precedence, media identity,
  progress token/sequence и imported source URLs;
- database schema, migrations, queues, storage, dependencies и environment.

Новые translations не требуются: используются существующие RU/EN keys.
Если implementation добавит доступный status label, ключ одновременно
добавляется во все поддерживаемые locale с exact placeholder parity.

## Performance и production

- Anchor behavior не выполняет database или network requests.
- Review placeholder не выполняет query.
- Player transition перерисовывает только небольшой navigation island, а не
  весь `CatalogTitlePlayer`.
- Island projection имеет фиксированный размер и не сериализует provider URL.
- Новые cache keys, invalidation, migration, worker, scheduler, dependency,
  `.env` или service-worker rule не нужны.
- Rollback возвращает предыдущие Blade/JS/Livewire fragments; data rollback и
  cache flush не требуются.

## TDD и acceptance

PHPUnit RED/GREEN проверяет:

1. все quick links имеют существующую стабильную SSR/lazy-placeholder цель;
2. reviews placeholder и hydrated component сохраняют единственный
   `id="reviews"`;
3. guest content-request URL указывает на защищённую предзаполненную форму и
   auth middleware сохраняет intended destination;
4. calendar URL сохраняет title filter;
5. player navigation объявлена named island с bounded data;
6. island commit после `episode 1 → 2` выводит next episode 3;
7. fallback links, server validation и authorization остаются рабочими;
8. новая реализация не добавляет query в Blade, raw provider URL или
   untranslated interface copy.

Playwright на `1440×1200` и `390×844` проверяет:

1. каждый из четырёх quick links меняет hash, прокручивает реальную цель и
   обновляет единственный `aria-current`;
2. прямое открытие каждого hash, повторный click и Back/Forward;
3. reviews target работает до и после lazy hydration;
4. заявка гостя проходит `login → intended request form`, а расписание открывает
   отфильтрованный calendar;
5. переходы `1 → 2 → 3` обновляют URL и labels previous/next;
6. `<video>`, Plyr root и стандартный fullscreen element сохраняют identity;
7. нет horizontal overflow, duplicate listeners/IDs, local asset failures,
   page errors или accessibility dead controls.

## Объединённый неограниченно расширяемый план

После принятия этой спецификации задачи добавляются в существующий
`2026-07-24-system-maintenance-and-optimization-master-plan.md` как новые
монотонные Task IDs без перенумерации и удаления истории. Master plan связывает
пять независимых workstreams:

1. полный end-to-end аудит и оптимизацию Discover;
2. обратный default chronology для `/calendar` с сохранением explicit sort;
3. candidate-first и bounded projection оптимизацию страницы тайтла;
4. navigation-only player island;
5. стабильную quick navigation страницы тайтла.

План не обещает буквальное бесконечное выполнение. Его «безлимитность»
означает append-only registry: после каждого acceptance/audit следующий
доказанный backlog получает `Task N+1`, оставаясь в том же owner-документе.
Каждая задача имеет отдельные evidence, rollback, verification и
`completed|already_compliant|not_applicable|unresolved` compliance statuses.

## Ожидаемые implementation files

- `app/Livewire/CatalogTitleDetail.php`
- `app/Livewire/CatalogTitlePlayer.php`
- `app/Livewire/Reviews/CatalogTitleReviews.php`
- `resources/views/livewire/catalog-title-detail.blade.php`
- `resources/views/livewire/catalog-title-player.blade.php`
- новый специализированный review placeholder Blade
- `resources/js/app.js`
- `resources/js/player-navigation.js`
- focused feature/unit tests
- новый isolated browser spec, чтобы не поглощать чужой dirty
  `tests/browser/player-lifecycle.spec.js`
- применимые owner docs, `README.md`, `CHANGELOG.md`, current plan и общий
  master plan
