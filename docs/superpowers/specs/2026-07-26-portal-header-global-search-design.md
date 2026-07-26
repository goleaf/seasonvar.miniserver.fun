# Task 88 — новая шапка портала и усиленный глобальный поиск

Дата: 26.07.2026.

Статус: `approved_for_implementation`.

## Цель

Перестроить общую оболочку Seasonvar вокруг главных пользовательских
сценариев: на desktop оставить четыре основных раздела, отдать поиску всё
свободное место и вынести account actions вправо; на compact viewport
показывать короткую верхнюю строку и пять закреплённых нижних пунктов.
Глобальный поиск должен остаться прогрессивной GET-формой, но получить
полноэкранный мобильный режим, горячие клавиши, недавние запросы, страну в
быстрых карточках, канонический переход ко всему каталогу и действие
создания заявки при честном нулевом результате.

Пользователь прямо разрешил автономно выбрать техническое решение и
потребовал не останавливаться на отдельном согласовании. Этот документ
фиксирует выбранную реализацию перед изменением application code.

## Подтверждённый baseline

- `x-layout.site-header` сейчас состоит из двух полос и до `lg` использует
  native `details` со всеми разделами.
- `AppLayoutData` готовит один смешанный список из home, catalog,
  discovery, calendar, Top 100, requests, help и account destinations.
- `x-layout.header-search` является одной SSR `GET /search` формой; Vite
  добавляет два независимых bounded API scope.
- `CatalogTitleSuggestionQuery` уже ищет русское, оригинальное и
  альтернативные названия, загружает постер и grouped counts сезонов/серий
  без N+1, но не загружает страну.
- API header title payload ограничен пятью карточками, public-only,
  locale-aware и cache-safe; raw query отсутствует в shared cache key.
- `mobile-runtime.js` уже владеет `visualViewport`, классом
  `.app-keyboard-visible`, safe-area variables и lifecycle после Livewire
  navigation.
- Отдельного persisted search-history/privacy owner нет. Поэтому
  серверная, account-synced или долговременная browser history не
  допускается без отдельной retention-модели.

## Рассмотренные варианты

### 1. Два независимых search-компонента для desktop и mobile

Плюс — простой native `<dialog>` на телефоне. Минусы — два поля, два набора
ARIA ID, два lifecycle instance, риск расходящегося query, duplicate fetch
и неоднозначный shortcut/focus owner. Вариант отклонён.

### 2. Перевести шапку в глобальный Livewire-компонент

Плюс — серверное состояние меню и поиска. Минусы — новый Livewire request
boundary на каждой странице, сериализация layout state, более сложный
focus/keyboard lifecycle и дублирование уже работающего read-only API.
Вариант отклонён.

### 3. Эволюция существующего Blade/Vite boundary

Один `x-layout.header-search` остаётся единственным form/input и на compact
viewport CSS/JavaScript переводит его в полноэкранный modal-like режим.
`AppLayoutData` разделяет уже разрешённые URLs по presentation groups.
Существующие API/query/cache boundaries расширяются только nullable
`country`. Этот вариант выбран: он сохраняет no-JS fallback, не добавляет
framework/package/schema и минимизирует расхождение состояния.

## Desktop information architecture

В одну sticky строку входят:

1. брендовый link с компактным знаком и неизменяемым словом `Seasonvar`;
2. четыре primary destination:
   - каталог;
   - подборки;
   - календарь;
   - Топ 100;
3. native `details` «Ещё», где обязательно находятся «Заявки» и «Помощь»;
4. гибкий global search, занимающий оставшееся место;
5. для authenticated user — отдельный link-колокольчик уведомлений;
6. avatar/account `details`; для гостя он содержит вход и регистрацию, для
   пользователя — профиль, библиотеку, настройки и остальные уже
   разрешённые server-prepared destinations;
7. logout остаётся существующим Livewire action внутри account menu.

Active primary link получает одновременно `aria-current="page"`,
`font-semibold/font-bold` и нижний emerald marker. Active состояние в
dropdown дополнительно имеет левый marker/фон и не передаётся только цветом.

Header всегда sticky. После небольшого scroll threshold он получает
`data-compact="true"`: уменьшается вертикальный rhythm/wordmark, но
navigation, search и minimum 44 px targets сохраняются. Шапка не
переводится в `display:none` и не уезжает transform-анимацией.

## Compact/mobile information architecture

В верхней sticky строке находятся только:

- логотип Seasonvar;
- кнопка открытия поиска;
- avatar/account menu.

Нижняя fixed navigation содержит ровно пять destinations:

1. Главная;
2. Каталог;
3. Поиск — button, открывающий тот же единственный search component;
4. Календарь;
5. Библиотека; для гостя destination ведёт через существующий login
   boundary, а не имитирует доступ.

Каждый пункт имеет icon, короткую подпись, минимум `44px`, `aria-current`
или `aria-pressed` и видимый focus. Padding учитывает
`env(safe-area-inset-bottom)`. Main/footer получают только presentation
clearance под fixed navigation. При `.app-keyboard-visible` нижняя
navigation скрывается; наличие этого client class никогда не влияет на
authorization.

## Полноэкранный мобильный поиск

Единственный `x-layout.header-search` остаётся в desktop DOM position.
При открытии на compact viewport:

- root становится fixed viewport surface с `role="dialog"` и
  `aria-modal="true"`;
- устанавливается `data-mobile-open="true"`;
- page scroll блокируется только на время открытого поиска;
- focus переносится в input;
- Tab циклически остаётся в доступных controls;
- Escape и явная кнопка закрытия возвращают focus opener;
- Livewire navigation и переход по search result снимают modal state;
- высота использует `--app-visual-viewport-height`, safe-area и
  viewport-fit contract.

Без JavaScript desktop form остаётся доступна начиная с `lg`; compact
search opener является progressive control и не создаёт ложного submit.
Canonical catalog/search routes остаются обычными links.

## Search behavior

- `Ctrl+K` и `Meta+K` открывают/focus-ят global search.
- `/` делает то же без modifier, если focus не находится в input, textarea,
  select, button, link или contenteditable и не открыт другой dialog.
- Поле ищет русское, оригинальное и alias-название через существующий
  `CatalogTitleQuery::matchingTitles()`.
- Быстрая title row показывает poster, display/original title, year,
  максимум две страны, количество доступных public сезонов и серий.
- Страна загружается одним constrained eager-load для bounded title IDs;
  запрос из Blade/JavaScript и N+1 запрещены.
- Отдельная строка «Искать во всём каталоге» всегда ведёт в
  `/titles?q=...` для непустого query и не зависит от наличия suggestions.
- Если оба API scope успешно завершились без items, показывается
  «Создать заявку» на существующий `requests.create`. Гость проходит
  штатный auth/intended flow; UI не обходит middleware/policy.
- Ошибка одного API scope не называется честным нулевым результатом и не
  показывает request CTA как замену unavailable backend.
- Нейтральная рамка `#site-search`/`data-header-search-input-frame`
  остаётся `border-slate-300` без цветного focus ring. Все остальные
  autocomplete links/buttons имеют обычный заметный `focus-visible`.

## Недавние запросы и privacy

Недавние запросы принадлежат только текущей вкладке:

- хранилище — `sessionStorage`, versioned key с interface locale;
- максимум пять строк;
- сохраняется только явно отправленный пользователем нормализованный query
  длиной `1..80`;
- значения не отправляются отдельным запросом, не попадают в Laravel
  session/database/cache/log/analytics и не синхронизируются с account;
- вкладка может очистить список явной keyboard-accessible кнопкой;
- при блокировке browser storage функция безопасно деградирует в memory-only
  список;
- закрытие вкладки удаляет историю по browser contract.

Это не вводит «популярные запросы», персональное ранжирование или новый
retention backend.

## Backend и API

- `CatalogTitleSuggestionQuery` получает constrained `countries:id,name`
  eager-load вместе с прежними aliases/card summaries.
- `CatalogSearchSuggestionQuery::headerTitleItem()` формирует nullable
  `country` из не более двух отображаемых имён и включает его в `meta`.
- `SearchSuggestionResource` добавляет только nullable public `country`.
- `HeaderSearchSuggestionCache::FORMAT_VERSION` повышается, поэтому старый
  payload становится недостижим без store-wide flush.
- Схема БД и индексы не меняются: primary key
  `catalog_title_country(catalog_title_id,country_id)` уже соответствует
  bounded eager-load, а отдельный reverse index обслуживает country-first
  filters.
- Routes, Form Request allowlists, legacy no-scope API shape и response
  envelope остаются совместимыми.

## Authorization и security

- Navigation URLs, authentication state и admin/private destinations
  готовит только `AppLayoutData`.
- Hidden button никогда не заменяет route middleware/policy.
- Bell/profile/library/request destinations сохраняют существующие
  `auth`, `auth.session`, `PrivateAccountResponse` и domain policies.
- API suggestions остаются public audience, escaped Resource fields и
  same-origin URLs; DOM строится через `createElement`/`textContent`.
- Poster URL не становится HTML sink и сохраняет error fallback.
- Recent query UI не использует `innerHTML`.
- Никакой raw query, user ID, notification body, avatar storage path,
  provider URL, secret или stack trace не добавляется в markup/cache.

## Performance и cache

- Один input вызывает максимум два уже существующих параллельных request,
  debounce остаётся 160 мс, stale responses отменяются.
- Country добавляет один bounded eager-load statement на максимум пять
  title suggestions; queries per card отсутствуют.
- Вкладочный response cache остаётся максимум 120 entries.
- Recent queries — максимум пять небольших strings.
- Header не запрашивает unread count и не создаёт navigation badge query.
- Новый package, cache domain, queue, scheduler или external request не
  добавляется.

## Error, empty и fallback states

- empty input: недавние запросы либо краткая подсказка shortcuts;
- one character: прежний exact-short title path;
- pending: spinner и polite live status;
- partial failure: успешная scope-group остаётся доступна;
- full failure: понятное сообщение и рабочий обычный GET submit;
- true zero: full-catalog row плюс request CTA;
- storage unavailable: search работает без недавних запросов;
- no JavaScript: обычная desktop GET форма и все route links работают;
- unavailable optional route: соответствующий prepared item отсутствует.

## Backward compatibility

Сохраняются:

- имена и URLs public routes;
- `GET /search?q=` и `/titles?q=`;
- `/api/v1/search/suggestions`, legacy no-scope clients и JSON envelope;
- `scope=header_titles|header_portal`;
- `AppLayoutData['layoutHeader']['navigation']` как совместимый полный
  список для существующих consumers;
- localized routes, locale cache dimension и `Accept-Language`;
- `#site-search`, neutral-frame data attributes и existing search
  translations;
- one full-page Livewire tree per route, server authorization and page
  cache policy.

## Production, rollout и rollback

Production impact ограничен PHP/Blade/JS/CSS/translations и Vite assets.
Deployment требует обычного atomic manifest/assets release, PHP
code/opcache refresh и smoke desktop/mobile header/search. Migration,
database backup/restore, queue restart, cache flush и data backfill не
нужны. Старые autocomplete cache entries автоматически отделены format
version.

Rollback: revert Task 88 code/docs и вернуть предыдущий Vite manifest/assets.
Schema/data/cache cleanup не требуется; format-v2 entries истекут естественно.

## Verification

- RED/GREEN PHPUnit для layout grouping, active marker, mobile five-item
  shell, API `country`, cache format, request CTA, recent/shortcut markers и
  neutral frame.
- Focused search/API/layout/auth tests.
- Query-count и `EXPLAIN QUERY PLAN` для country eager-load.
- Pint, PHP syntax, scoped static checks, translations, routes, views,
  managed docs и Vite build.
- Playwright desktop `1440×1200`, tablet `768×1024`, mobile `390×844` и
  compact `320×720`: screenshot inspection, scroll compactness, five-item
  nav, safe-area CSS, Ctrl+K and `/`, fullscreen search, recent queries,
  poster/country/year/seasons/episodes, full catalog row, true-zero request
  CTA, focus, Escape, virtual keyboard class, back/forward, no overflow and
  console/network/accessibility errors.
