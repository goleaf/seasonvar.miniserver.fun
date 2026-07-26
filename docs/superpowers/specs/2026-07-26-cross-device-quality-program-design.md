# Cross-device quality program design

**Дата:** 26.07.2026
**Статус:** approved by standing user authorization
**Scope:** phone, tablet portrait/landscape, desktop, TV-like browser, public/private/admin surfaces

## Goal

Сделать существующий Laravel/Livewire portal одинаково надёжным на узком
телефоне, обычном телефоне, планшете, компьютере и большом экране. Это не
масштабирование одного desktop-макета и не второй mobile/TV frontend:
маршруты, policies, page builders, translations, cache/SEO identities и
server-rendered content остаются общими, а presentation адаптируется к
доступному месту и способу ввода.

## Evidence baseline

- Каноническая оболочка уже mobile-first, использует `viewport-fit=cover`,
  safe-area variables, `svh`/`dvh`, общий visible focus, responsive header,
  native mobile disclosure и минимум `44px` для основных controls.
- Default Playwright projects: `390×844`, `768×1024`, `1440×1200`.
  Отдельные catalog cases меняют ширину до `375`, `1280`, `1920`, но это не
  единый cross-surface gate.
- Нет обязательной проверки `320×720`, phone landscape, `1024×768`, browser
  zoom `200%`, `pointer: coarse`, keyboard-only `1920×1080`, long RU/EN
  labels и safe-area geometry на общей representative matrix.
- Header переключается на полный navigation row уже с `sm` (`640px`).
  Планшет получает desktop navigation независимо от реальной доступной
  ширины, количества действий и длины перевода.
- Content max width `1760px` предотвращает бесконечное растяжение, но обычный
  text/control scale остаётся desktop-like на TV-like viewport.
- Плотные responsive-risk участки сосредоточены в stats, catalog/admin,
  tags, settings, requests, collection classification, title/player,
  discovery и library templates. Наличие `overflow-x-auto` допустимо только
  у локального semantic region и не доказывает отсутствие page overflow.
- Existing `/discover` browser contract уже подтверждает text-only
  collection cards, categories/subcategories, RU/EN и no overflow на трёх
  базовых viewport. Эта возможность сохраняется.

## Usage contexts

### Narrow phone

- `320×720`, touch, virtual keyboard, медленное соединение.
- Один основной столбец, полная функциональность, controls не меньше `44px`,
  отсутствие horizontal page overflow, no hover dependency.
- Filters/navigation используют native disclosure или sheet/dialog, но
  core links и submit fallback работают без JavaScript.

### Standard phone

- `390×844` portrait и representative landscape.
- Primary actions находятся в досягаемой зоне; длинные labels переносятся,
  sticky элементы не перекрывают focused input или validation.
- Player controls, season/episode menus и dialogs помещаются в visual
  viewport с safe-area padding.

### Tablet

- `768×1024` portrait и `1024×768` landscape, touch и возможные
  keyboard/pointer.
- Tablet не считается уменьшенным desktop: two-column/master-detail
  применяется только когда content выигрывает; full desktop navigation
  появляется по content-driven gate, а не только по ширине.
- Forms и admin emergency workflows сохраняют все действия с touch targets
  `44px`, без скрытого desktop-only capability.

### Desktop

- `1440×1200`, keyboard и fine pointer.
- Сохраняются плотность, быстрые фильтры, таблицы, multi-panel context и
  существующие keyboard paths.
- Hover усиливает, но не открывает единственный путь к действию.

### TV-like browser

- `1920×1080`, viewing distance, keyboard/remote-like sequential input,
  возможно отсутствие hover/fine pointer.
- Это Chromium evidence, а не заявление о конкретной Smart TV OS, codec,
  WebView или physical remote.
- Phase 1 использует устойчивый native sequential focus, крупный visible
  focus, комфортные action targets и ограниченную ширину чтения. Собственный
  spatial-navigation engine не добавляется до воспроизводимого требования:
  он легко ломает screen-reader/keyboard order и создаёт второй interaction
  layer.

## Design decisions

### 1. One adaptive shell

`layouts/app`, `site-header`, `header-search`, `site-footer` и `app.css`
остаются единственными shell owners. Не создаются mobile Blade copies,
device routes, user-agent branching или client-trusted capability state.

Header получает три presentation stages:

1. narrow/phone — компактная brand/action row, search второй строкой,
   navigation disclosure;
2. tablet — тот же disclosure с более просторной search/action geometry;
3. desktop — полный wrapping navigation row.

Breakpoint выбирается по фактической вместимости content. Существующий
`sm` switch не считается автоматически правильным.

### 2. Input-aware, not device-sniffed

Width/orientation меняют composition; `pointer`, `hover`,
`prefers-reduced-motion`, `prefers-contrast` и `forced-colors` меняют
affordance. Essential action никогда не hover-only. Coarse pointer получает
не меньше `44px`; TV-like/keyboard focus имеет отдельный сильный, но
не декоративный visual state.

### 3. Structural adaptation

- Phone: single-column, compact disclosures, progressive secondary detail.
- Tablet: one/two columns по content, no forced desktop tables.
- Desktop: multi-panel only where it improves repeated work.
- TV-like: readable measure, reduced density, larger focus/action geometry;
  page не растягивает body text на `1760px`.

Cards не становятся универсальным ответом. Tables either keep a local
accessible scroll region with headings/captions or transform to labelled
rows/cards where semantics and repeated actions benefit.

### 4. Stable interaction states

Every interactive component preserves default, hover where applicable,
focus, active, disabled, loading and error states. Livewire loading remains
component-scoped, authoritative content is not blanked before success, and
focus return/restoration is tested for dialogs/disclosures/navigation.

### 5. Typography and translations

- Product UI keeps the existing system sans family and restrained palette.
- Body copy remains at least `16px` on phone inputs and readable on TV-like
  pages; prose line length stays approximately `65–75ch`.
- H1–H3 and controls support long RU/EN labels without clipping.
- Stable locale keys and server locale hydration remain unchanged.

### 6. Performance

Device quality is not allowed to duplicate server reads or ship separate
mobile/TV payloads. Existing page/query builders, constrained eager loads,
pagination and caches remain canonical.

Each phase records:

- PHP query count and bounded payload where server render changes;
- Livewire snapshot/request count where state boundaries change;
- Vite asset sizes after CSS/JS changes;
- first-party failed requests, console/page errors and layout shift proxies;
- image count/geometry only where media presentation changes.

No external provider is called per card/render. A service worker, offline
video, push or new dependency is not part of this program.

## Cross-feature compatibility

Protected contracts:

- all existing named routes, redirects, localized aliases and route model
  binding;
- authentication, authorization, private/no-store and noindex boundaries;
- title/media visibility, signed playback/download and player lifecycle;
- RU/EN translation keys, fallback and Livewire locale;
- public/private cache identities and targeted invalidation;
- search/filter/pagination URL state and SEO canonical/hreflang/structured
  data;
- collection text-only cards, category/subcategory filters and existing
  `/discover` behavior;
- existing API, database schema, import command, queues and storage.

Schema, migration, package, environment key, cache key, route, API response,
permission, production DML and destructive operation are not planned for
the foundation phases.

## Verification matrix

Mandatory automated viewport contexts:

| Context | Viewport | Input/capability |
| --- | ---: | --- |
| narrow phone | `320×720` | touch/coarse |
| phone | `390×844` | touch/coarse |
| phone landscape | `844×390` | touch/coarse |
| tablet portrait | `768×1024` | touch |
| tablet landscape | `1024×768` | touch + keyboard |
| desktop | `1440×1200` | fine pointer + keyboard |
| TV-like | `1920×1080` | keyboard-only/no hover evidence |

Representative route tiers:

1. shell/home/search;
2. `/titles`, `/discover/popular`, collection detail;
3. title/player;
4. auth/private library/settings/calendar;
5. administration/support/premium truthful states.

Every applicable case checks HTTP/H1, page overflow, first-party responses,
console/page errors, focus visibility/order, accessible names, minimum
targets, long RU/EN labels, dialog/disclosure viewport containment and
reduced motion. Zoom `200%`, forced colors and physical-device checks remain
separate explicit gates; emulation is not misreported as real hardware.

## Rollout and rollback

Work ships in independently revertible phases. Foundation tests land before
shell/UI changes; public discovery before title/player; private/admin after
shared components stabilize. Each phase uses ordinary code revert plus Vite
rebuild. No schema/data/cache restore is required unless a later separately
approved phase changes those domains.

Physical iPhone, Android, tablet and Smart TV/remote verification cannot be
performed by repository automation alone and remains `not_performed` until
real evidence is available.
