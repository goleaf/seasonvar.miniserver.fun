# Task 119 — compliance полного редизайна главной

Обновлено: 27.07.2026.

Статус: `planning_in_progress`. MCP-аудит и repository census выполнены;
визуальное направление ожидает утверждения пользователя. Реализация не
начата.

## Цель

Переработать `/`, `/ru` и `/en`, чтобы постеры и основная площадь домашних
карточек сериалов надёжно открывали соответствующий тайтл, страница была
удобна на телефоне, планшете и desktop, а цветовая иерархия стала заметнее
без отхода от канонической светлой системы Seasonvar.

## Подтверждённое текущее состояние

- Laravel Boost подтвердил PHP `8.5`, Laravel `13.22.0`, Livewire `4.3.3`,
  Tailwind CSS `4.3.2`, Vite `8`, PHPUnit `12.5.32` и SQLite.
- `/` и `/{locale}` уже обслуживает один full-page
  `App\Livewire\CatalogHomePage`; новые routes или контроллер не нужны.
- `CatalogHomePageBuilder::webData()` остаётся владельцем guest/auth
  projection, visibility, personal ownership и bounded section data.
- Обычные `home`, `spotlight`, `trend`, latest-media и continue-watching
  карточки выводят постер вне ссылки; к тайтлу ведёт только текстовая ссылка
  или отдельная CTA. В существующих grid/list/compact карточках уже есть
  проверенный whole-card overlay pattern через `after:absolute after:inset-0`.
- Live MCP-аудит на `1440×1200` и `390×844` подтвердил длинное однообразное
  полотно, слабое визуальное разделение секций и отсутствие клика по постеру.
- Production console содержит существующий `404` одного script request и
  report-only CSP diagnostics. Это отдельный сигнал: редизайн не должен
  выдавать его за собственную регрессию или молча скрывать.

## Требования и статус

| Требование | Статус | Evidence / решение |
|---|---|---|
| Корневой `AGENTS.md` и requirements index | `completed` | Порядок подготовки, exact lease, docs и delivery применены |
| Existing architecture до замены | `completed` | Livewire page, builder, card components, cache policy и соседние tests inspected |
| Laravel 13 / Livewire 4 / Tailwind 4 | `already_compliant` | Версии подтверждены Laravel Boost; package update не требуется |
| Full-page Livewire route boundary | `already_compliant` | `home` и `localized.home` сохраняются |
| Светлая тема и системная палитра | `in_progress` | Допустимы semantic tints `emerald`, `sky`, `amber`, `red`, `slate`; dark theme, gradients и случайные цвета исключены |
| Кликабельность постеров | `pending` | Планируется additive whole-card overlay без nested anchors и перехвата вторичных controls |
| Mobile-first, touch, keyboard и focus | `pending` | Обязательны 44px essential controls, visible `focus-visible`, no page overflow, keyboard path и zoom |
| Guest/auth section order Task 94 | `already_compliant` | Порядок остаётся server-rendered и не переставляется CSS/JS |
| Полные facets Task 111 | `already_compliant` | Все genres/countries/years и стабильные `data-home-facet-list` сохраняются |
| Passive Blade | `already_compliant` | Query/ranking/URLs/ownership не переносятся в Blade; `@php`, inline CSS и business JS запрещены |
| RU/EN и localized routes | `already_compliant` | Existing keys и locale aliases сохраняются; новые keys возможны только синхронно для всех locale |
| Query и payload budgets | `pending verification` | Builder selection/hydration не расширяется; запросы и DOM/image counts должны не ухудшиться |
| Homepage cache compatibility | `pending decision` | Проверить ротацию `PublicPageCachePolicy` `response_contract=2` для недостижимости stale HTML |
| SEO, canonical и `hreflang` | `already_compliant` | Content/order change не меняет существующий SEO shell |
| PWA/private/media boundaries | `already_compliant` | Offline/private exclusions и same-origin poster proxy не меняются |
| Routes/API/schema/permissions | `not_applicable` | Изменения не требуются; `/api/v1/home` shape должен остаться прежним |
| Dependencies/migrations/data writes | `not_applicable` | Новые packages, migration, backfill и production DML не планируются |
| README/owner docs/CHANGELOG | `pending implementation` | Обновляются только после реального visitor-visible изменения |
| Commit/push | `pending implementation` | Только existing `main`; remote authentication ранее unresolved |

## Cross-feature impact

| Домен | Статус | Совместимость / проверка |
|---|---|---|
| Authentication | `affected` | Отдельно проверить guest и authenticated DOM order/state |
| Authorization/privacy | `unaffected` | Ссылки ведут только на существующие публично разрешённые title routes; UI не становится authority |
| Personal progress/library | `affected` | Continue Watching сохраняет episode/player URL, progress и owner-only projection |
| Recommendations | `affected` | Только presentation; ranking, exclusions и remember-shown state неизменны |
| Collections | `affected` | Featured collection cards и пустое состояние остаются; collection links не перекрываются title overlays |
| Search/catalog/facets | `affected` | Все текущие local URLs и полный facet contract сохраняются |
| Caching | `affected` | Guest full-response HTML требует stale-cache review; private home остаётся bypass |
| Performance | `affected` | Нельзя расширять hydration, число изображений/DOM без измерения или вводить carousel/API fetch |
| Multilingual/SEO | `affected` | `/`, `/ru`, `/en`, RU/EN labels, canonical и `hreflang` проверяются |
| Mobile/PWA/browser | `affected` | Проверяются matrix viewport, touch, keyboard, zoom, reduced motion и cache denylist |
| Notifications/importer/player | `unaffected` | State, source availability, import и playback grants не меняются |
| Premium/region/legal | `unaffected` | Visibility и entitlement остаются server-owned в текущих query boundaries |
| Administration/audit | `not_applicable` | Write/admin workflow отсутствует |

## Ожидаемые изменяемые файлы после утверждения дизайна

- `resources/views/livewire/catalog-home-page.blade.php`;
- `resources/views/components/catalog/title-card-home.blade.php`;
- `resources/views/components/catalog/title-card-trend.blade.php`;
- `resources/views/components/catalog/latest-media-card.blade.php`;
- `resources/views/components/catalog/home-trending-grid.blade.php`;
- при доказанной необходимости — additive API
  `resources/views/components/ui/poster-card.blade.php` и
  `app/View/Components/Ui/PosterCard.php`;
- homepage feature/unit/browser tests;
- при ротации cache markup —
  `app/Support/Cache/PublicPageCachePolicy.php` и его unit test;
- только фактически затронутые translations, canonical UI/frontend/views/
  caching docs, `README.md` и `CHANGELOG.md`;
- design spec, detailed implementation plan и archive evidence.

## Файлы и contracts, которые должны остаться совместимыми

- `routes/web.php`: `home`, `localized.home`, middleware и URLs;
- `app/Livewire/CatalogHomePage.php` и
  `app/Services/Catalog/CatalogHomePageBuilder.php`: существующая projection
  boundary, если visual design не докажет необходимость обратимо расширить
  prepared presentation data;
- `routes/api.php`, `CatalogHomeResource` и `/api/v1/home` response shape;
- recommendation, visibility, region, Premium, legal и owner-only services;
- homepage query/cache keys, invalidation и warmers, кроме явной
  `response_contract` ротации;
- stable `data-home-section`, `data-home-facet-list` и существующие browser
  selectors либо документированный совместимый переход.

## Риски, rollout и rollback

- Migrations/data/backup: `not_applicable`; persistent state не меняется.
- Cache: при изменении guest HTML старый response contract должен стать
  недостижимым без wildcard scan или broad flush.
- Build: Blade/Tailwind release требует `npm run build`; package install и
  lockfile change не нужны.
- Production: обычный согласованный application/assets rollout, cache/config/
  view refresh по существующему runbook и smoke `/`, `/ru`, `/en`.
- Failure recovery: revert presentation/tests/docs, rebuild assets и
  восстановить предыдущий cache contract; database restore не требуется.
- Посторонние рабочие изменения `composer.lock` и
  `storage/debugbar/.gitignore` не входят в task scope.

## Planned verification

1. TDD RED/GREEN для кликабельного постера/карточки, сохранения отдельных CTA
   и отсутствия nested anchors.
2. Existing homepage, projection, performance, cache, translation и Blade
   contract suites.
3. `./vendor/bin/pint --dirty --format agent` только при PHP-правках,
   focused Larastan/Rector по фактическому scope и `npm run build`.
4. Playwright для `/`, `/ru`, `/en`, guest/auth на `320×720`, `390×844`,
   `844×390`, `768×1024`, `1024×768`, `1440×1200`, `1920×1080`, с
   keyboard/focus, zoom, reduced motion, console/network и horizontal
   overflow checks.
5. Финальное перечитывание requirements, repository-wide legacy/dead-control
   search, README check, staged diff review, exact lease approval, commit в
   `main` и push attempt.
