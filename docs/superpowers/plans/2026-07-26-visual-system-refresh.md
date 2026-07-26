# План обновления визуальной системы Seasonvar

Статус: `implementation_committed_push_unresolved_authentication`.

Дата начала: 26.07.2026.

Approved design:
[`2026-07-26-visual-system-refresh-design.md`](../specs/2026-07-26-visual-system-refresh-design.md).

Обозначения: `[pending]`, `[in_progress]`, `[completed]`,
`[skipped: причина]`. Статусы обновляются при каждом discovery, меняющем
scope, риск или решение.

## Этап 1. Анализ

### 1. Fresh requirements и shared-tree audit

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: перечитать `AGENTS.md`, requirement index и применимые canonical
  owners; проверить branch, remote, staged/unstaged/untracked.
- Почему: requirements — source of truth, а tree содержит чужие изменения.
- Файлы/зависимости: read-only docs и Git metadata.
- Риск: смешать чужие hunks.
- Проверка: зафиксированы `main`, `origin`, исходный status и foreign scope.

### 2. Stack и architecture inventory

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: проверить версии, frontend/DB, routes, controllers, models,
  relations, Requests, policies, middleware, services/actions/DTO, events,
  jobs, notifications, commands/schedule, resources, migrations,
  factories/seeders, tests и CI.
- Почему: нельзя угадывать version-dependent APIs и boundaries.
- Файлы/зависимости: `composer.*`, `package*`, `routes/*`, `app/*`,
  `database/*`, `tests/*`, `.github/workflows/ci.yml`, Laravel Boost.
- Риск: ненужная новая архитектура или dependency.
- Проверка: PHP `8.5.8`, Laravel `13.22.0`, Livewire `4.3.3`, Tailwind
  `4.3.2`, Vite `8.1.4`, SQLite и 241 маршрут подтверждены.

### 3. UI inventory

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: найти shared components, `text-slate-400`, radii, shadows,
  heading scale и nested panels.
- Почему: исправляются доказанные причины, а не весь markup вслепую.
- Файлы: `resources/css/app.css`, `resources/views/**/*`,
  `app/View/Components/**/*`, UI tests.
- Риск: спутать decorative/disabled со значимым текстом; затронуть player.
- Проверка: baseline — 106 `text-slate-400`, 34 real shadow utilities,
  215 `shadow-panel`, 269 `rounded-panel`, 259 headings.

### 4. Database/query/security impact

- Статус/приоритет: `[completed]` / `high`.
- Сделать: проверить потребность в новых fields, relations, queries,
  validation, authorization или input normalization.
- Почему: UI не должен скрыто создавать N+1, IDOR или новый write path.
- Файлы/зависимости: route-to-Livewire/view-model trace, read-only.
- Риск: query/service call из Blade.
- Проверка: новых данных/действий нет; migrations, indexes, transactions,
  Form Requests, policy changes и `EXPLAIN` не применимы.

## Этап 2. Requirements и TDD

### 5. Canonical UI owner

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: первым тематическим изменением обновить
  `docs/UI_STANDARDS.md`: palette, semantics, contrast, radius, elevation,
  typography, title prominence.
- Почему: новое постоянное правило сначала меняет canonical owner.
- Риск: оставить старую строку о panel shadows.
- Проверка: поиск противоречащих формулировок и повторное чтение owner.

### 6. RED: tokens

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: test exact semantic aliases, radii, no-shadow panel,
  `shadow-elevated`, отсутствие body gradients и system font.
- Файлы: `tests/Unit/FrontendAssetContractTest.php`.
- Зависимость: Task 5.
- Риск: brittle CSS formatting assertion.
- Проверка: focused test сначала падает по отсутствующим tokens.

### 7. RED: shared surfaces/status/title hierarchy

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: tests для flat `x-ui.panel`, heading scale, no ordinary shadow,
  `error/info` pills и prominent title-card heading.
- Файлы: `tests/Feature/CatalogVisualSystemTest.php`,
  `tests/Feature/CatalogBladeComponentTest.php`.
- Зависимость: existing Blade rendering.
- Риск: проверять строку вместо observable markup contract.
- Проверка: RED на прежнем panel/header/title markup.

### 8. RED: contrast/elevation guard

- Статус/приоритет: `[completed]` / `high`.
- Сделать: repository scan запрещает important `text-slate-400` и real
  shadows вне raised contexts; разрешает decorative icon/skeleton/disabled.
- Файлы: `tests/Feature/CatalogVisualSystemTest.php`.
- Риск: false positive для dialog/dropdown/toast.
- Проверка: failure содержит точные paths/lines.

## Этап 3. Реализация

### 9. Tailwind CSS-first tokens

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: semantic color aliases, compatibility `rose→red`,
  `rounded-panel=.75rem`, `rounded-control=.5rem`, panel no-shadow,
  elevated shadow; убрать body gradients; исправить progress semantics.
- Файл: `resources/css/app.css`.
- Зависимость: Tailwind 4 `@theme`/`@theme inline`.
- Риск: неправильное разрешение variables или player regression.
- Проверка: asset test, production build, computed styles, exact scoped
  player tokens unchanged.

### 10. Typographic scale

- Статус/приоритет: `[completed]` / `high`.
- Сделать: app-shell h1 `30 px` с фиксированным `36 px` desktop step,
  h2/h3 `24/18 px`, semibold, slate-900; reusable
  body/metadata/micro-label classes; сохранить `.sr-only`. Fluid `clamp()`
  отклонен после product-register review: плотный каталог использует
  предсказуемые fixed rem steps и структурные breakpoints.
- Файлы: `resources/css/app.css`, shared headings.
- Риск: selector specificity ломает hidden text или scoped player UI.
- Проверка: computed styles, one H1, logical H2/H3, 390/768/1440.

### 11. Shared structural components

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: `x-ui.panel` как одна border-only surface с white header/divider;
  обновить `x-ui.section-title`, `x-stat`, stats poster и status variants.
- Файлы: `resources/views/components/ui/{panel,section-title}.blade.php`,
  `resources/views/components/stat.blade.php`,
  `app/View/Components/Ui/{PosterCard,StatusPill}.php`.
- Зависимость: сохранить component props/slots.
- Риск: массовый visual impact.
- Проверка: Blade component suite и rendered API parity.

### 12. Elevated surfaces

- Статус/приоритет: `[completed]` / `high`.
- Сделать: `shadow-elevated` только sticky header, autocomplete/dropdown,
  dialog/player menu/toast/skip-link; убрать real shadow у fields,
  navigation, filters, ordinary cards и empty states.
- Файлы: layout/header/search/help и чистые visitor templates.
- Риск: dropdown теряет separation, обычная surface остается без border.
- Проверка: static allow-list и screenshots.

### 13. Title cards

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: title как `h3 text-lg font-semibold text-slate-900`; metadata
  `text-xs/text-sm text-slate-600`; rank без постоянного uppercase.
- Файлы: `resources/views/components/catalog/title-card-{list,recommendation}.blade.php`.
- Зависимость: current `TitleCard` props/prepared data.
- Риск: overlay/explicit links или no-query rendering.
- Проверка: existing compact/card tests, title prominence and keyboard.

### 14. Главная и каталог

- Статус/приоритет: `[completed]` / `high`.
- Сделать: flat panels, 22–26 px section headings, slate-600 metadata,
  emerald-800 hover; remove ordinary shadows without changing data/order.
- Файлы: `catalog-home-page.blade.php`, `catalog/titles.blade.php`,
  directory/discovery/top Livewire views.
- Зависимости: current builders, filters, islands, pagination/query string.
- Риск: изменить markers, keys, order, mobile controls или back/forward.
- Проверка: existing feature suites and browser interactions.

### 15. Страница сериала

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: flatten quick counters, remove gradient hero/nested description
  card, apply h1/dividers, red errors and pill status; после visual discovery
  поставить hero/player раньше quick-navigation на mobile, сохранив sidebar
  слева на desktop.
- Файл: `resources/views/livewire/catalog-title-detail.blade.php`.
- Зависимость: player identity, anchors, Livewire children.
- Риск: сломать `id=player`, quick targets, refresh, signed playback.
- Проверка: title/player/live-refresh tests and browser scenario.

### 16. `/stats`

- Статус/приоритет: `[completed]` / `high`.
- Сделать: page header как unframed section; health panels border-only;
  important metadata slate-600; micro-labels без постоянного uppercase;
  inner rounded rows заменить divider rows при outer panel.
- Файл: `resources/views/livewire/stats-dashboard.blade.php`.
- Зависимость: prepared arrays/wire keys unchanged.
- Риск: длинная page, dynamic tone classes.
- Проверка: labels/values/query budget unchanged; desktop/mobile screenshots.

## Этап 4. Cross-feature review

### 17. Validation/authorization/security

- Статус/приоритет: `[completed]` / `high`.
- Сделать: подтвердить no new inputs/actions; проверить escaping, CSRF/auth,
  policies, external URLs и отсутствие secrets/debug.
- Риск: accidental raw output.
- Проверка: diff/repository search and relevant existing tests.
- Evidence: task PHPStan/Pint/Rector GREEN; no new input, action, route,
  policy, raw output, external URL or serialized state.

### 18. SQL/performance/cache

- Статус/приоритет: `[completed]` / `high`.
- Сделать: проверить no Blade queries/eager-load/Livewire/JSON/cache changes;
  сравнить Vite asset size.
- Риск: скрытая lazy loading или bundle regression.
- Проверка: title-card no-query, home/stats query tests and build sizes.
- Evidence: related matrix `174/174`, `1 709` assertions; production CSS
  `198.62 kB` / gzip `43.23 kB`; no JS dependency or request added.

### 19. Accessibility/error/mobile

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: contrast, focus, labels, status text+ARIA, 44px targets,
  loading/empty/error/disabled, reduced motion, long Russian labels.
- Риск: state signal только цветом.
- Проверка: axe, keyboard, console, screenshots and overflow; screenshot
  review исправил mobile title-first hierarchy.
- Evidence: new Playwright `3/3` after final order fix; autocomplete
  regression `2/2`; technical audit `19/20`, no open P0–P2.

### 20. Compatibility domains

- Статус/приоритет: `[completed]` / `high`.
- Сделать: auth/admin/translations/cache/search/SEO/notifications/player/
  Premium/payments/ads/region/legal/import/service-worker/deployment review.
- Риск: необоснованное `already_compliant`.
- Проверка: evidence или честный `not_applicable/unresolved` по domain.

## Этап 5. Verification и docs

### 21. Focused tests

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: visual/component/title/home/stats focused tests.
- Проверка: exact commands, test/assertion counts recorded.
- Evidence: `62/62`, `858` assertions; related `174/174`, `1 709`
  assertions. Full 360-file batch: `1 969` total, `1 956` passed,
  `11` skipped, `199 592` assertions and two unrelated existing failures:
  missing `SeasonvarImportDispatchBatcher` and stale
  `WebAccountManagementTest` session-flash assertion.

### 22. Formatting/static/build

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: `./vendor/bin/pint --dirty --format agent`, PHP syntax,
  task-scoped available PHPStan/Rector, `npm run build`.
- Риск: formatter touches foreign PHP.
- Проверка: inspect every formatter hunk; no foreign staging.

### 23. Browser QA

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: `/`, `/titles`, title detail, `/stats` at `390×844`,
  `768×1024`, `1440×1200`; screenshot, console, requests, headings,
  overflow, controls and autocomplete.
- Риск: browser/local DB unavailable.
- Проверка: 200, no error console/unexpected failed request/overflow.

### 24. Documentation

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: `docs/UI_STANDARDS.md`, `docs/frontend.md`, plan evidence,
  visitor `README.md`, Russian `CHANGELOG.md`; preserve managed blocks.
- Риск: fictitious claim or duplicate contract.
- Проверка: docs check and README visitor history remains last H2.

## Этап 6. Final audit, commit, push

### 25. Requirements/legacy audit

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: reread requirements/design/plan; search legacy shadows,
  nested cards, important slate-400, rose semantics, TODO/debug, inline
  CSS/JS, raw icons and duplicate tokens.
- Проверка: classify every relevant remaining hit.

### 26. Exact diff/secret audit

- Статус/приоритет: `[completed_with_foreign_tree_limits]` / `critical`.
- Сделать: status/diff/stat/staged/unstaged/untracked/branch/remote,
  secrets/debug/mass formatting/unrelated file checks.
- Риск: mixed README/CHANGELOG/current-plan hunks.
- Проверка: exact task-only index.

### 27. Commit

- Статус/приоритет: `[completed]` / `critical`.
- Сделать: commit exact scope on `main` as
  `feat: refresh the light visual system`.
- Проверка: commit `3599547499f6470088466e034c429c560df121f1`
  содержит 55 файлов Task 79; commit diff/secret/debug checks прошли.

### 28. Push

- Статус/приоритет: `[unresolved_authentication]` / `critical`.
- Сделать: configured non-force push of current `main`.
- Риск: foreign dirty tree pre-push gate, auth/network/divergence.
- Проверка: `git push origin main` завершился кодом 128:
  `fatal: could not read Username for 'https://github.com': No such device or address`.
  Remote, credentials, branch и history не менялись; force push не
  выполнялся.

## Task-specific compliance matrix

| Requirement/domain | Статус | Evidence / gate |
| --- | --- | --- |
| Root/index/canonical requirements | `completed` | Fresh mandatory read order |
| Runtime/package versions | `completed` | Boost + local package tools |
| Official version-specific docs | `completed` | Boost Tailwind 4 and Laravel 13 docs |
| Existing implementation first | `completed` | Components/routes/player/tests traced |
| Approved design/alternatives | `completed` | User decision + A/B/C record |
| Plan/files/contracts/risks | `completed` | This document and design |
| Canonical UI requirement first | `completed` | `docs/UI_STANDARDS.md` updated and reread before CSS/Blade |
| TDD RED/GREEN | `completed` | Intended RED, then 62 tests / 858 assertions GREEN |
| Colors/contrast/radius/shadow/type/title | `completed` | CSS, shared components, main visitor routes and mobile title-first hierarchy |
| Validation/Form Requests | `not_applicable` | No input change |
| Authorization/policies | `already_compliant` | No boundary change; related feature/browser gates GREEN |
| Routes/API/query string | `already_compliant` | No contract change; filter/query-string browser gates GREEN |
| Models/relations/SQL/index/migration/data | `not_applicable` | Presentation only |
| Translations | `already_compliant` | No new visible copy |
| Cache/queue/schedule/import | `already_compliant` | No runtime-state change |
| Security/privacy | `already_compliant` | Escaping/boundaries preserved; no new input/write/provider path |
| Performance | `completed` | Query-focused regressions and production Vite build GREEN |
| Mobile/a11y/browser | `completed` | 3/3 new cross-viewport scenarios; axe/overflow/console GREEN |
| Player | `protected` | Signed-media fixture scenario and title/player regression GREEN |
| SEO/search/notifications/Premium/payments/ads/region/legal | `already_compliant` | No contract change; public presentation smoke GREEN |
| Production/rollback | `completed` | Production build GREEN; code/assets-only rollback |
| README/CHANGELOG/docs | `completed` | UI/frontend/audit owners, visitor history and Russian changelog updated; docs profile GREEN |
| Final audit | `completed` | Requirements, legacy/design, debug/secret and task-scoped diff checks complete; three foreign EOF whitespace errors excluded |
| Commit/push main | `completed_with_unresolved_push_authentication` | `3599547` в `main`; обычный HTTPS push отклонён до передачи credentials |

## Expected changed files

- `resources/css/app.css`;
- shared UI/catalog components named in Tasks 11–13;
- layout/header/search and clean visitor views named in Tasks 14–16;
- `tests/Unit/FrontendAssetContractTest.php`;
- `tests/Feature/CatalogVisualSystemTest.php`;
- `tests/Feature/CatalogBladeComponentTest.php`;
- `tests/Feature/HeaderSearchAutocompleteTest.php` if needed;
- `docs/UI_STANDARDS.md`, `docs/frontend.md`, this design/plan,
  `docs/plans/current-task-plan.md`, task hunks in `README.md` and
  `CHANGELOG.md`.

## Protected contracts

- routes, names, API, binding, Livewire methods/state/events/islands/keys;
- controllers, Requests, Resources, policies/gates/middleware;
- models, relations, scopes, SQL, migrations/indexes/data/cache;
- single main/H1, `id="player"`, quick targets and public data markers;
- title-card props, links and query-free prepared data;
- translations, user identity values, SEO/search/feed/recommendations;
- auth/admin/Premium/payment/ads/rights/region/legal/privacy;
- importer/queue/schedule/storage/environment/service worker;
- scoped player palette, node identity, signed URLs and lifecycle;
- all pre-existing work outside Task 79.

## Compatibility and operations risks

| Domain | Impact | Decision |
| --- | --- | --- |
| Database/schema/index/data | `not_applicable` | No DDL/DML/query change |
| Routes/API/Livewire query string | `protected` | No PHP boundary edits |
| Translations/cache/permissions | `protected` | No contract/key changes |
| SEO/search/player | `critical_protected` | Same content/state/DOM |
| Mobile/a11y | `affected` | Responsive/contrast/focus verification |
| Build/deploy | `affected_low` | Atomic Vite assets; code-only rollback |
| Shared working tree | `critical_risk` | Exact index; no reset/stash/foreign stage |
