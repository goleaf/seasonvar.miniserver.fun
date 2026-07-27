# Task 119 — compliance полного редизайна главной

Обновлено: 27.07.2026.

Статус: `completed`; remote delivery: `unresolved`. Пользователь утвердил
рекомендованный вариант 1; implementation и verification завершены только в
существующей `main` и зафиксированы commit `948be300`. Push отклонён внешней
HTTPS-аутентификацией.

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
- `home`, `spotlight`, `trend`, latest-media и continue-watching теперь
  используют проверенный whole-card overlay pattern через
  `after:absolute after:inset-0`; отдельные CTA/taxonomy controls остаются
  foreground links.
- Исходный live MCP-аудит на `1440×1200` и `390×844` подтвердил длинное
  однообразное полотно, слабое визуальное разделение секций и отсутствие
  клика по постеру; локальный Playwright после реализации подтвердил новый
  contract на семи viewport-профилях.
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
| Светлая тема и системная палитра | `completed: design approved` | Amber отделяет trend, sky — updates, emerald — watch/personal return, slate — neutral; red остаётся error-only |
| Кликабельность постеров | `completed` | Реальный browser hit-test координаты постера попадает в единственную title link; nested anchors отсутствуют |
| Mobile-first, touch, keyboard и focus | `completed` | Section actions ≥44px, visible `focus-visible`, wrapping и отсутствие page overflow проверены unit/browser contracts |
| Guest/auth section order Task 94 | `already_compliant` | Порядок остаётся server-rendered и не переставляется CSS/JS |
| Полные facets Task 111 | `already_compliant` | Все genres/countries/years и стабильные `data-home-facet-list` сохраняются |
| Passive Blade | `already_compliant` | Query/ranking/URLs/ownership не переносятся в Blade; `@php`, inline CSS и business JS запрещены |
| RU/EN и localized routes | `already_compliant` | Existing keys и locale aliases сохраняются; новые keys возможны только синхронно для всех locale |
| Query и payload budgets | `completed` | Builder/data selection не изменены; performance/projection suites прошли `59` tests / `485` assertions |
| Homepage cache compatibility | `completed` | `PublicPageCachePolicy` использует homepage `response_contract=3`; unit contract GREEN |
| SEO, canonical и `hreflang` | `already_compliant` | Content/order change не меняет существующий SEO shell |
| PWA/private/media boundaries | `already_compliant` | Offline/private exclusions и same-origin poster proxy не меняются |
| Routes/API/schema/permissions | `not_applicable` | Изменения не требуются; `/api/v1/home` shape должен остаться прежним |
| Dependencies/migrations/data writes | `not_applicable` | Новые packages, migration, backfill и production DML не планируются |
| README/owner docs/CHANGELOG | `completed` | UI/frontend/views/caching owners, visitor history и русский changelog обновлены |
| Commit/push | `unresolved` | Commit `948be300` в `main`; push не смог прочитать GitHub username, credential helper отсутствует |

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

## Фактический implementation scope

- `resources/views/livewire/catalog-home-page.blade.php`;
- `resources/views/components/catalog/title-card-home.blade.php`;
- `resources/views/components/catalog/title-card-trend.blade.php`;
- `resources/views/components/catalog/latest-media-card.blade.php`;
- `resources/views/components/catalog/home-section-heading.blade.php` как
  единый passive owner responsive section title/action markup;
- `app/View/Components/Ui/PosterCard.php` для белых interactive surfaces
  только layouts `home|spotlight|trend`;
- homepage feature/unit/browser tests;
- при ротации cache markup —
  `app/Support/Cache/PublicPageCachePolicy.php` и его unit test;
- translations не изменялись; canonical UI/frontend/views/caching docs,
  `README.md` и `CHANGELOG.md` обновлены;
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

## Verification

1. TDD RED подтвердил отсутствие overlay/surfaces/cache contract; GREEN —
   `43` tests / `339` assertions.
2. Projection/performance/facet/visual suites — `59` tests / `485`
   assertions; `CatalogPageTest` — `85` / `865`; `--filter=CatalogHome` —
   `31` / `242`.
3. Focused PHPStan: `0` errors; Rector: `0` changes; Pint и Vite build
   завершились успешно.
4. Playwright: основной набор `9/9`, расширенная матрица `21/21` на desktop,
   mobile, tablet, narrow phone, phone landscape, tablet landscape и TV-like
   Chromium; `/`, `/en`, guest/auth, exact poster hit target и отсутствие
   horizontal overflow проверены.
5. Полный pre-push gate GREEN: `2 305` tests, `2 294` passed, `11` skipped,
   `208 535` assertions; Composer/npm audit, Pint, Rector, PHP syntax,
   PHPStan, docs/config/routes/views cache и Vite/player release прошли.
6. Exact manifest/index approval прошёл; commit `948be300` создан в `main`.
   Push attempt отклонён до hook: HTTPS remote не смог прочитать GitHub
   username при отключённых terminal prompts.
