# Task 116 — compliance восстановления `/discover/`

Дата: 27.07.2026.

| Требование | Статус | Evidence |
| --- | --- | --- |
| Root/index/canonical requirements | `completed` | Fresh read и route/domain owners проверены до code edit |
| Existing implementation first | `completed` | `/discover` = `404`; все `/discover/{type}` уже содержат один полный explorer |
| Последние спецификации | `completed` | Task 109/110 сохраняют 5 root и 31 child во всех девяти modes |
| Версии и Laravel API | `completed` | PHP 8.5, Laravel 13.22; Boost docs подтвердили `Route::redirect` и named redirects |
| Постоянный route contract | `completed` | `system-wide-integration`, `architecture`, `frontend`, `catalog-search` обновлены до implementation |
| Routes/backward compatibility | `completed` | Default/trailing-slash/RU/EN = exact `302`; остальные legacy aliases остаются `404` |
| Schema/data/imports | `not_applicable` | Нет migration, backfill, DML или provider request |
| Authorization/security/privacy | `already_compliant` | GET redirect на application-owned named route; locale ограничен существующим allowlist |
| Cache/SEO/sitemap/API | `already_compliant` | Landing остаётся canonical `/discover/popular`; keys, invalidation и payloads не меняются |
| Multilingual | `completed` | RU/EN сохраняют locale в exact target; новых строк нет |
| UI/mobile/accessibility | `completed` | Playwright desktop/mobile: `2/2`, 5 root, 31 child, без overflow/errors |
| TDD/verification | `completed` | RED, 58/494 focused matrix, Pint, isolated route cache, Vite и Playwright прошли |
| Production/rollback | `completed` | Code-only additive routes; rollback без data/cache/dependency операций |
| README/CHANGELOG/evidence | `completed` | Visitor section/history, русский changelog и archive evidence обновлены |
| Commit/push | `unresolved` | Exact combined commit `cc77728a`; GitHub HTTPS push запросил username при отсутствии credential helper |

## Ожидаемый и фактический scope файлов

- application/tests: `routes/web.php`,
  `tests/Feature/UnifiedDiscoveryCollectionsTest.php`,
  `tests/browser/discovery-collections.spec.js`;
- canonical/topic owners: `docs/requirements/system-wide-integration.md`,
  `docs/architecture.md`, `docs/frontend.md`, `docs/catalog-search.md`,
  `docs/deployment.md`, `docs/system-integration.md`,
  `docs/maintenance/compatibility-adapters.md`,
  `docs/audits/verification-report.md`;
- task evidence: current plan, этот compliance-файл, design,
  implementation plan и archive evidence;
- visitor/technical history: `README.md`, `CHANGELOG.md`.

## Защищённые совместимые contracts

- `discover.index`, `localized.discover.index` и все девять type values;
- один full-page `CatalogDiscoveryPage` и один вложенный
  `CatalogCollectionExplorer`;
- anchors `#collections`, `#popular-titles`, `#discovery-titles`;
- query keys `collections_q`, `collections_sort`,
  `collections_category`, `collections_subcategory`, `collectionsPage`;
- `/collections/{slug}`, profile, API и sitemap contracts;
- `404` для `/collections`, `/admin/collections`, `/recommendations`,
  `/lists`, `/selections` и `/my/lists`;
- category identity/translations, public quality/visibility,
  recommendation ranking, cache identities и permissions.

## Cross-feature impact matrix

| Domain | Статус | Причина / evidence |
| --- | --- | --- |
| Routes | `affected` | Добавлены только два named GET redirect entry routes |
| Models/schema/migrations/relationships | `not_applicable` | Eloquent и database не меняются |
| Actions/services/queries | `unaffected` | Redirect не вызывает новый domain layer; существующий page/explorer target сохранён |
| Policies/gates/authorization | `unaffected` | Public GET и прежние target boundaries; external target не принимается |
| Livewire/Blade/JavaScript | `unaffected` | Landing component и markup не меняются; browser test только фиксирует route contract |
| Translations/locale | `affected` | Supported `ru|en` route сохраняет locale; новых keys или persisted labels нет |
| Cache keys/invalidation | `unaffected` | Redirect не кеширует domain data; target использует прежний discovery cache |
| Search | `unaffected` | Documents, suggestions и query state не меняются |
| SEO/canonical/hreflang | `affected` | Default entry временно redirect-ит; canonical остаётся type URL |
| Sitemap/structured data | `unaffected` | Default entry не индексируется и не добавляется в sitemap/JSON-LD |
| Notifications/audit | `not_applicable` | State-changing action отсутствует |
| Administration | `unaffected` | `/admin/catalog` и category classification не меняются |
| Mobile/accessibility | `affected` | Desktop/mobile landing smoke подтвердил то же полное дерево без overflow |
| Authentication/sessions/privacy | `unaffected` | Redirect public; owner/private payload отсутствует |
| Premium/payments/region/legal/advertising | `not_applicable` | Entitlement и commercial boundaries не участвуют |
| Imports/providers | `unaffected` | HTTP к provider, sync и collection data не меняются |
| Account merge/deletion/export | `unaffected` | User state и persistent rows не затронуты |
| Backward compatibility | `affected` | Исторические default route names и visitor entry восстановлены |
| Production/runtime | `affected` | Требуется route-cache rebuild при deploy; live code и isolated cache проверены |
