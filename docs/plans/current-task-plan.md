# Task 11 — полный аудит и безопасная интеграция тегов

Дата начала: 18.07.2026 (`Europe/Vilnius`)

## Цель

Проверить и довести до единого production-ready состояния уже существующий канонический домен системных, редакционных и личных тегов, не создавая второй tag aggregate и не меняя публичные/постоянные contracts без совместимого перехода.

## Обязательные ограничения

- Работа выполняется только в существующей `main`; branch/worktree/PR не создаются.
- Стартовый `HEAD`: `f983ad3`; `main...origin/main [ahead 14]`; рабочее дерево после внешнего commit чистое.
- До implementation читаются все project-owned Markdown-файлы, канонические owners — в порядке `docs/requirements/index.md`; repository-relative links проверяются.
- Новые automated tests не создаются и существующие automated tests не запускаются по прямому требованию Task 11. Проверки: static inspection, route/schema/query/policy/cache/SEO/translation inspection, Pint, PHP syntax/static analysis, docs diagnostics, Vite build и browser smoke, где доступно.
- Новые production dependencies, обязательные queues/scheduler/workers, Volt, `@php`, inline CSS и inline business JavaScript не добавляются.
- Все database changes только additive/reversible и SQLite-compatible. Legacy tags, translations, assignments, order, slugs, aliases, provider provenance, moderation и privacy data сохраняются.
- `CHANGELOG.md` остаётся на русском по каноническому project policy, несмотря на противоречивое требование Task 11 об английском changelog.

## Исходная архитектура, подтверждённая документацией и Git history

Commit `ca70246` уже ввёл канонический tag domain. Task 11 является audit/hardening существующей системы, а не новой реализацией.

### Identity и модели

- Глобальный canonical base: `App\Models\Tag` + существующие `tags` и `catalog_title_tag`.
- Stable database identity: `tags.id`; opaque public/API identity: `tags.public_id`; optional language-independent integration identity: `tags.code`.
- Mutable `name`, translation label, slug и alias не являются identity.
- Глобальные adjunct models: `TagTranslation`, `TagAlias`, `TagSynonym`, `TagSlug`, `TagProviderMapping`, `CatalogTitleTagSource`, `TagMergeEvent`.
- Личный owner aggregate: `UserTag` + `user_tags` и explicit `catalog_title_user_tag`; он не смешан с global classification.
- Season/episode/collection/comment polymorphic tag pivots отсутствуют; необходимость их добавления пока не подтверждена доменом.

### Stable values

- Global type allowlist: `system`, `editorial`, `imported`, `hidden_internal`.
- Global visibility: `public`, `internal`.
- Moderation: `pending`, `approved`, `rejected`, `hidden`, `merged`, `archived`.
- Public-user/unlisted tags намеренно не поддерживаются; public-user moderation/report/appeal surface не должен имитироваться.
- Personal tags owner-scoped и private by design; original Unicode label/content locale сохраняются без machine translation.

### Translation, alias, synonym и slug model

- `tag_translations` хранит locale-specific label/plain-text descriptions/SEO fields с unique tag+locale; active/fallback locale выбирается query boundary без размножения rows.
- `tag_aliases` хранит normalized locale/source-aware alternative labels и разрешается в один canonical tag.
- `tag_synonyms` — bounded explicit relationship, не автоматический merge; search expansion должна оставаться ограниченной одним hop.
- `tag_slugs` сохраняет current/history/alias/merge-compatible URL identity; public canonical URL — `/titles/tag/{slug}`.
- `/tags/{slug}` и `/tag/{slug}` сохраняются как compatibility redirects; loops/case/history/merged targets проверяет `ResolveCanonicalTagRoute`.

### Assignment, routes и UI

- Global title assignment остаётся в `catalog_title_tag`; personal assignment — в `catalog_title_user_tag` с deterministic `position`.
- Global mutations проходят `TagPolicy`, `manage-catalog`, `TagService`/`TagAssignmentService`; personal mutations — `UserTagPolicy`, current authenticated owner и `PersonalTagService`.
- Public page переиспользует full-page `CatalogSeries`, `CatalogTitlesPageBuilder`, canonical filters/sorts/cards и public title visibility.
- Personal management: `/library/tags/manage`; selector хранит только UUID draft, `Apply` transactionally reconciles, `Cancel` не пишет state.
- Administration: `/admin/tags`, `TagAdministrationManager`, bounded `TagAdministrationQuery`, explicit merge/archive confirmation.
- API: public tag list/detail и owner-only personal CRUD/assignment under existing `/api/v1` contracts.

### Search, recommendations, cache, SEO и import

- `TagQuery`/`TagResolver` владеют public search, alias resolution, active/fallback presentation, popularity и bounded related tags.
- Public tag pages/results/counts use public approved eligible global tags and distinct visible titles; personal assignments are excluded.
- Recommendations may use eligible global tags only for public similarity; personal tags remain private personalization input only.
- `TagSnapshotCache` reuses `TieredCache` and `CacheDomain::Tags`; `TagCacheInvalidator` delegates after commit to existing `CatalogCacheInvalidator`. Personal tags never enter shared cache.
- `TagSeoPresenter` owns canonical/meta/breadcrumb/structured-data decisions; alias/history redirects and private/unapproved/internal/archived/empty tags are non-indexable.
- Existing sitemap includes only non-empty canonical eligible tags.
- `TagImportSynchronizer` and provider mapping/provenance records converge Seasonvar values idempotently; unknown provider values remain pending and do not publish automatically.

## Оперативные результаты аудита

- Полный tracked Markdown corpus: `284` файла / `62 381` строк; files readable, canonical owner order and repository-relative documentation contracts reviewed. До product edits `php artisan project:docs-refresh --check` завершился сообщением `Документация уже актуальна.`
- Web/API route inventory подтверждает один canonical public route `titles.taxonomy` (`/titles/tag/{taxonomy}`), compatibility routes `tags.show` (`/tags/{value}`) и `legacy.tags.show` (`/tag/{taxonomy}`), owner-only `personal-tags.index`, gated `admin.tags`, public `/api/v1/tags*` и private `/api/v1/me/tags*`. Деструктивные действия не используют `GET`; отдельные localized tag routes отсутствуют по текущему product contract.
- Фактическая SQLite schema содержит только canonical global/personal aggregates: `tags` + adjunct tables + `catalog_title_tag` и отдельные `user_tags` + `catalog_title_user_tag`; polymorphic/season/episode/comment tag pivots не обнаружены.
- Bounded read-only audit текущих данных: `800` global tags, `2 621` personal tags, `134 040` global и `7 411 528` personal assignments. Не обнаружены missing canonical identities/hashes/slugs, invalid enum states, duplicate hashes/slugs/translations/assignments, orphan assignments/owners, self-synonyms, alias conflicts, provider/provenance gaps или merge self/two-node cycles. Все eligible public tags имеют assignment; private rows не смешаны с global aggregate.
- Existing indexes реально обслуживают owner and pivot lookup; `EXPLAIN QUERY PLAN` подтверждает indexed owner/tag and composite pivot access. Temporary sort для bounded owner tag set допустим; новый index без доказанной пользы не добавляется.
- Выявлен подтверждённый multilingual administration gap: domain/service/schema поддерживают все `config('tags.supported_locales')`, но `TagAdministrationManager` и его Blade form жёстко авторят только `ru`; alias authoring также принудительно помечает записи как `ru`. Это не повреждает public fallback, но не даёт редактору безопасно управлять существующим `en` content. План обновлён до реализации locale-selectable translation/alias authoring без изменения route/schema/cache contracts.
- Главная страница получает subtitle system tag из snapshot по mutable slug `subtitry`, а Blade повторно hardcode-ит тот же slug в filter URL. Это нарушает уже существующий stable-code contract и оставляет dead control после archive/visibility change. Исправление: resolve snapshot только по `code=subtitle-available` + public eligibility, передавать фактический slug и генерировать canonical tag route helper в prepared view data.
- `TagService::storeAlias()` предотвращает conflict только внутри одного locale, хотя public tag URL не locale-scoped. В потенциальном legacy состоянии одинаковый normalized alias мог бы указывать на разные canonical tags в разных locale и resolver выбирал бы первый row. Текущие данные чисты (`0` conflicts); исправление остаётся additive на code boundary: запрещать cross-tag conflicts независимо от locale и заставлять resolver fail closed при неоднозначном hash match, сохраняя одинаковые locale variants одного canonical tag.
- Global tag edit защищён optimistic version, но translation update мог перезаписать concurrent editorial change без expected version. Translation save будет использовать уже существующий `tagVersion` и тот же localized stale-edit response; alias/provider additive actions не перезаписывают translation payload.
- Browser smoke выявил cross-origin cache-poisoning gap на общей full-page cache boundary: read-only запрос к локальному `artisan serve` с production Host и нестандартным port создал HTML с `:8014` asset URLs, который затем был получен через canonical HTTPS tag route. До завершения требуется проверить `PublicPageCachePolicy`/cache key, включить нормализованный canonical origin или fail-closed cache eligibility для non-canonical authority, адресно bump-нуть затронутую public cache version и повторить exact-origin smoke. Глобальный cache flush запрещён.
- Public API smoke выявил projection inconsistency: cached popular tag summary не сохраняет language-independent `code`, поэтому `/api/v1/tags` может вернуть `code=null`, тогда как тот же tag через `?q=` возвращает реальный code. Исправление: добавить `code` в единственный canonical summary projection и изменить bounded snapshot projection dimension, чтобы legacy cached payload не обслуживался после deploy.
- Personal web create flows ошибочно присваивают `content_locale` из `ru` или interface locale, хотя отдельного выбора/детектора языка нет. Original Unicode label при этом сохраняется, но metadata ложно описывает английский или mixed-script UGC как язык интерфейса. Additive correction: новые web-created personal tags сохраняют `content_locale=null`; web edit не стирает уже явно выбранный API locale; API PATCH различает absent field (preserve) и explicit nullable field (clear). Существующие строки не переписываются автоматически.

## Завершённый фактический аудит

1. ~~Прочитать весь Markdown corpus и проверить links/owner contradictions.~~ Выполнено; final link/owner validation повторяется после documentation edits.
2. ~~Инвентаризировать exact tag web/API/localized/legacy routes, names, middleware, bindings and destructive methods.~~ Выполнено; final route snapshot остаётся в checklist.
3. ~~Инвентаризировать migrations/schema/indexes/FKs/uniqueness/data anomalies и выполнить только bounded read-only reconciliation checks.~~ Выполнено для текущей schema/data; final migration/FK check остаётся в checklist.
4. ~~Проверить enums/models/casts/scopes/relationships/projections/soft-delete/account lifecycle/title merge.~~ Выполнено; competing aggregate или потеря user/global rows не обнаружены.
5. ~~Проверить normalization/validation/reserved-name/duplicate/slug/alias/synonym/merge loop invariants.~~ Выполнено; cross-locale alias ambiguity закрыта, fuzzy auto-merge отсутствует.
6. ~~Проверить policy/gate/Form Request/Livewire/API authorization, IDOR, CSRF, rate/batch limits, mass assignment and safe errors.~~ Выполнено статически; authenticated mutation smoke не выполнялся без безопасной учётной записи.
7. ~~Проверить public/private queries: eligibility, distinct counts, regional/premium documented behavior, N+1, active/fallback translation projection, deterministic pagination.~~ Выполнено; отдельной regional schema нет, public eligibility использует единый catalog audience/window contract.
8. ~~Проверить personal CRUD/restore/assignment/batch/cancel/order/private cache/export/delete flows.~~ Выполнено; interface locale больше не записывается как неподтверждённый язык UGC.
9. ~~Проверить public directory/page/filter/sort/search/autocomplete/popular/related/empty/error/loading/a11y/mobile behavior.~~ Выполнено статически и direct managed-Chromium smoke на desktop/mobile.
10. ~~Проверить admin create/edit/translation/alias/synonym/provider/global assignment/archive/restore/merge impact preview and audit.~~ Выполнено по route/policy/service/DTO/Blade contracts; `ru|en` translation/alias authoring и optimistic version усилены.
11. ~~Проверить importer normalization/idempotency/editorial-lock/provenance and title-merge integration.~~ Выполнено; existing mapping/provenance/pivot uniqueness и editorial suppression сохранены.
12. ~~Проверить cache dimensions/invalidation/failure fallback/search synchronization/recommendation invalidation.~~ Выполнено; origin/projection dimensions закрывают обнаруженные stale/poisoned variants без flush.
13. ~~Проверить canonical URLs/legacy redirects/noindex/hreflang distinction/structured data/sitemap eligibility.~~ Выполнено; sitemap HEAD/code/eligibility проверены, полный streamed body не завершился в bounded timeout.
14. ~~Найти repository-wide legacy/duplicate tag logic, dead controls, hardcoded UI text, raw keys, Blade queries, `@php`, inline CSS/JS and unfinished code.~~ Выполнено; оставшийся `subtitry` используется только как documented pre-migration fallback.
15. ~~Внести только подтверждённые coherent fixes, затем повторить полный acceptance/compliance review.~~ Выполнено без schema/dependency/route/data mutations.

## Cross-feature impact map

| Domain | Impact | Planned verification |
| --- | --- | --- |
| Home | affected | public tag cards/counts/cache/recommendation inputs |
| Search/autocomplete | affected | canonical/translation/alias/synonym public-only results; private exclusion |
| Catalogue/alphabet/filter/sort | affected | shared query architecture, no duplicate titles, validated URL state |
| Title pages | affected | public badges + owner personal overlay without N+1/leakage |
| Seasons/episodes/player | expected unaffected | confirm no tag pivots/access/source side effects |
| Progress/history/bookmarks/library | affected | personal assignment independence and owner filters |
| Collections | affected | no implicit membership/tag coupling; private identity separation |
| Recommendations | affected | global/public signal only; owner-private personalization only |
| Profiles/public profiles | affected | no private personal tag DTO/source leakage |
| Comments/reviews | expected unaffected | confirm hashtags do not create catalogue tags |
| Authentication/sessions | affected | verified owner mutation and no client-owned actor ID |
| Account export/deletion | affected | owner tags/assignments export and cascade lifecycle |
| Notifications | expected unaffected | confirm no fake/unowned category added |
| Administration/audit | affected | gate, per-action authorization, merge/audit evidence |
| Imports | affected | mapping/provenance/idempotency/editorial preservation |
| API | affected | public/private resource separation and stable opaque IDs |
| Translations/localized routes | affected | ru/en parity, fallback, user label preservation, no raw keys |
| Cache/search index | affected | public/private separation and targeted invalidation |
| SEO/sitemap | affected | canonical redirects, eligible-only metadata/indexing |
| Premium/region/legal | affected by visibility | reuse canonical eligibility; document absent schema honestly |
| Mobile/accessibility | affected | 320px/zoom/keyboard/touch/live-region checks |
| Production/deployment/rollback | affected | additive schema capability, migration/cache/build recovery |

## Database and migration review

- Existing additive migrations `2026_07_15_230000` through `230200` are authoritative history and are not edited.
- Before any new constraint/index: inspect real duplicate/orphan/loop collisions, existing indexes and `EXPLAIN QUERY PLAN`; no constraint is added merely because it appears desirable.
- Data safety: no tag/pivot/translation/alias/provider row is deleted by audit code. Merge/archive remains explicit authorized transaction.
- Rollback preference: feature/schema capability falls back through `TagSchema`; new code must remain compatible with legacy `tags/catalog_title_tag` until migrations are applied.
- Deployment requires normal backup assessment, migration preflight, application/config/route/view cache rebuild where applicable, Vite build when UI changes, and targeted tag/catalog namespace invalidation rather than global flush.

## Files expected to change

Exact list depends on audit findings. Expected owners: `app/Services/Tags/*`, tag models/enums/policies/requests/resources/Livewire, `routes/web.php`, `routes/api.php`, tag Blade views/components, `lang/ru/tags.php`, `lang/en/tags.php`, relevant importer/catalog/search/cache/SEO/sitemap/account lifecycle boundaries, additive migration only if proven necessary, canonical tag sections in architecture/data relations/search/importer/authorization/security/caching/UI/API/administration docs, `README.md`, `CHANGELOG.md`, and this plan.

## Files/contracts that must remain compatible

- Existing public/localized/legacy route names and tag slugs/history/aliases.
- `tags.id`, `tags.public_id`, optional `code`, provider identifiers and all tag/pivot rows.
- Current title/season/episode/player/progress/history/watchlist/collection/comment/review behavior.
- Existing cache domain/key infrastructure, search/catalog/recommendation/SEO/sitemap owners and `php artisan seasonvar:import` public command.
- Existing ru/en locale identifiers, fallback behavior and API field names.
- Test infrastructure and dependency/lock files.

## Rollback and failure recovery

- Code rollback must leave additive tag schema/data readable; irreversible data rewrite is prohibited.
- Failed migration: stop rollout, run only its safe `down()` where verified, restore database backup when DDL/data state is uncertain, keep legacy reads through `TagSchema`.
- Stale cache/search/sitemap: bump only canonical tag/catalog/search/sitemap/recommendation versions and rebuild bounded snapshots; never `Cache::flush()`.
- Import/provider outage: preserve last canonical mapping/assignments and pending/rejected decisions; retry through existing importer only.
- Failed merge/assignment: database transaction rolls back; after-commit invalidation/audit occurs only for committed changes.
- Interrupted Vite build/deploy: retain previous manifest/assets until new compatible unit is fully published; guest HTML is manifest-dimensioned.

## Compliance matrix — final state

Статусы выставлены после повторного чтения требований, изменённых файлов и непосредственно связанных неизменённых boundaries. `completed` означает изменение в этой задаче, `already_compliant` — проверенное существующее поведение, `not_applicable` — намеренно отсутствующий product contract.

| Requirement group | Status | Evidence / unresolved work |
| --- | --- | --- |
| One canonical global/personal architecture | already_compliant | `Tag`/adjunct global domain and separate owner-private `UserTag`; no competing table/service discovered |
| Stable identity/codes/slugs/history | completed | UUID/ID/code remain independent of label/slug; subtitle control now resolves stable code; current/history/alias/merge routes verified |
| Types/visibility/moderation/source | already_compliant | Enum allowlists and public/assignable predicates checked in model/service/query paths |
| Translation/user original language | completed | Explicit supported-locale admin forms, optimistic translation version, 183/183 RU/EN keys; personal web UGC uses null unknown locale and preserves explicit API value |
| Unicode normalization/validation/reserved terms | already_compliant | One normalization service, Unicode-preserving display, safe comparison hash, bounded plain-text validation and narrow reserved policy verified |
| Aliases/synonyms/search expansion | completed | Cross-locale/canonical target collision prevention and fail-closed ambiguity added; slug uniqueness and bounded one-hop synonyms retained |
| Duplicate detection/admin merge | already_compliant | Exact-scope reconciliation/transaction moves pivots, translations, aliases, slugs, provider mappings and evidence; fuzzy merge absent |
| Personal CRUD/restore/privacy | completed | Owner UUID/policy/version/soft-delete/30-day restore/no-store boundaries verified; false interface-locale metadata removed |
| Global/personal assignments/order/batch cancel | already_compliant | Authorized transaction, unique pivots, deterministic positions, Apply/Cancel and unrelated library-state preservation verified |
| Public pages/filter/sort/counts | already_compliant | Canonical catalog query, public title eligibility, distinct counts, stable sorting/pagination and canonical cards verified |
| Search/autocomplete/popular/related | completed | Public/private separation and UI behavior verified; cached summary now preserves stable code under projection v2 |
| Public-user tags/reporting | not_applicable | product intentionally does not support public user tags |
| Season/episode tag assignment | not_applicable | no explicit domain requirement or pivot; no hidden dependency found |
| Hierarchy | not_applicable | no hierarchy contract/table/control; related/synonym semantics do not imply inheritance |
| Administration/moderation/archive | completed | Gate/service/policy/audit/private exclusion retained; locale selectors and stale translation protection added |
| Import/provider mapping/idempotency | already_compliant | Stable provider identity, pending mapping, provenance/current observation and editorial preservation verified |
| Recommendations/collections/library/profile | already_compliant | Public similarity excludes private tags; personal filters remain owner-only and collection/progress state independent |
| Account deletion/export | already_compliant | Owner export allowlist and cascade cleanup verified; no private orphan or foreign-user data |
| Cache/search index/privacy | completed | Canonical origin and projection versions added; targeted invalidation retained; private tags never shared/indexed |
| SEO/canonical/hreflang/structured data/sitemap | already_compliant | Eligible-only canonical metadata/redirects/schemas/sitemap; no false locale routes/hreflang or private pages |
| Security/IDOR/XSS/CSRF/rate limits | completed | Existing policies/CSRF/plain-text/rate/batch controls verified; alias ambiguity and cache-origin poisoning closed |
| Query performance/indexes/N+1 | already_compliant | Zero duplicate data anomalies, existing composite indexes and bounded `EXPLAIN`; no new index justified |
| Mobile/accessibility/loading/empty/error | completed | Existing controls retained; locale fields use labelled touch-sized controls; public desktop/mobile Chromium has no overflow/errors |
| Documentation/README/changelog | completed | Canonical owners, visitor history, Russian changelog, deployment, verification evidence and this plan updated |
| Git commit/push | unresolved | Final delivery commit создан в `main`, но configured HTTPS remote отклонил push из-за отсутствующего credential; `gh` не установлен, SSH отклонён `Permission denied (publickey)`. Remote и secrets не менялись |

## Known limitations and explicitly unresolved verification evidence

- Public user tags, tag reporting, tag hierarchy and season/episode assignments remain `not_applicable`; no fake controls or schema were added.
- The product has no separate tag-specific regional or Premium schema. Public tag pages use the same real catalogue publication/audience/window eligibility as all public cards.
- The large streamed sitemap returned `200` with the expected XML/cache headers, but its full multi-megabyte body exceeded the bounded 30-second smoke; code and database eligibility were inspected instead of claiming a completed transfer.
- Authenticated admin writes were not executed against production-style data without a supplied safe account. Route gate, hydration authorization, services, validation, optimistic version, transaction and audit calls were inspected; browser success is not claimed.
- Automated tests were neither created nor run because Task 11 explicitly prohibited both. Existing test infrastructure was preserved and the pre-existing affected expectation was updated for future normal CI.
- Configured HTTPS Git push не может пройти без внешней GitHub-аутентификации; `gh` отсутствует, а SSH key не авторизован. Commit остаётся в локальном `main`; изменение remote или credentials вне полномочий задачи.

## Final verification checklist

- [x] Task 11, canonical requirements and final matrix reread; no unchecked implementation requirement promoted without evidence.
- [x] Every changed file and directly related route/model/service/query/policy/cache/SEO/import/account boundary reviewed.
- [x] Route/schema/migration/index/data anomaly and bounded query-plan evidence reviewed; no schema change justified.
- [x] PHP syntax, Pint, configured Larastan, translation parity/raw-key/hardcoded-text scans, docs-refresh check, Blade compilation and Vite build passed; no automated test command used.
- [x] Public canonical/legacy/invalid tag routes, API, cache origin, desktop/mobile layout and anonymous privacy smoke passed; credential-dependent limitation recorded above.
- [x] Repository-wide legacy/duplicate/dead/TODO/debug/`@php`/Blade-call/inline-style/script scans completed with only documented compatibility fallback remaining.
- [x] README relevance/visitor history and Russian `CHANGELOG.md` updated; final commit создан в `main`, дерево после commit чистое.
- [ ] Configured remote push заблокирован внешней GitHub-аутентификацией после HTTPS и SSH попыток; ошибка и локальный commit должны быть переданы без ложного заявления об отправке.
