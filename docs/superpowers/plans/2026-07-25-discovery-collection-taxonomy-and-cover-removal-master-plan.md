# Collection Taxonomy, Cover Removal, and Discovery Refresh Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Полностью удалить изображения подборок, добавить управляемую
двухуровневую таксономию, обновить `/discover/popular` для 500+ подборок и
сделать стоимость public directory пропорциональной текущей странице.

**Architecture:** `CatalogCollection` остаётся единственной моделью подборки.
Один nullable FK указывает на корень или дочерний узел таксономии.
`CatalogCollectionCategoryQuery` читает дерево/counts,
`CatalogCollectionCategoryService` является write boundary, а существующий
`CatalogCollectionQuery` выполняет двухфазную пагинацию. Единый text-only
collection card заменяет cover/fallback presentation во всех consumers.
Обложки сначала перестают записываться и читаться, затем удаляются
идемпотентной exact-prefix командой, и только после zero-residue gate
удаляются DB columns.

**Tech Stack:** PHP 8.5, Laravel 13.22, Livewire 4.3, SQLite, PHPUnit 12.5,
Tailwind CSS 4.3, Vite 8, существующие policies/gates/cache invalidators/admin
audit.

**Canonical design:**
`docs/superpowers/specs/2026-07-25-discovery-collection-taxonomy-and-cover-removal-design.md`.

**Delivery constraint:** работать только в существующей `main`. Shared index
уже содержит большой чужой staged scope; не reset/stash/unstage его. Каждый
task заканчивается локальной проверкой и exact-path diff review. Commit
выполняется только когда разрешённый Task 51 scope можно отделить без
поглощения чужих изменений; иначе delivery остаётся `unresolved`.

---

### Task 1: Зафиксировать schema contracts таксономии

**Files:**

- Create: `tests/Feature/CatalogCollectionCategorySchemaTest.php`
- Create: `database/migrations/2026_07_25_140000_create_catalog_collection_categories.php`
- Create: `database/migrations/2026_07_25_140100_add_category_to_catalog_collections.php`
- Create: `app/Models/CatalogCollectionCategory.php`
- Create: `app/Models/CatalogCollectionCategoryTranslation.php`
- Modify: `app/Models/CatalogCollection.php`
- Modify: `app/Services/Collections/CatalogCollectionSchema.php`

**Step 1: Write the failing schema test**

Проверить:

```php
Schema::hasColumns('catalog_collection_categories', [
    'id', 'public_id', 'parent_id', 'slug', 'position', 'is_active',
    'created_at', 'updated_at',
]);

Schema::hasColumns('catalog_collection_category_translations', [
    'id', 'catalog_collection_category_id', 'locale', 'name',
    'created_at', 'updated_at',
]);

Schema::hasColumn('catalog_collections', 'catalog_collection_category_id');
```

Дополнительно через SQLite pragmas/assertDatabase constraint tests проверить:

- unique public UUID/slug;
- unique category/locale translation;
- self FK `parent_id`;
- collection FK с `RESTRICT`;
- indexes `(parent_id,is_active,position,id)` и
  `(catalog_collection_category_id,visibility,moderation_status,deleted_at,updated_at,id)`.

**Step 2: Run RED**

Run:

```bash
php artisan test --filter=CatalogCollectionCategorySchemaTest
```

Expected: fail только из-за отсутствующих таблиц/columns.

**Step 3: Generate additive migrations**

Run:

```bash
php artisan make:migration create_catalog_collection_categories
php artisan make:migration add_category_to_catalog_collections
```

DDL не смешивать с default rows. `down()` первой migration удаляет translation
table, затем category table; `down()` второй снимает FK/index/column.

**Step 4: Implement typed models and relations**

Основные relations:

```php
public function parent(): BelongsTo
public function children(): HasMany
public function translations(): HasMany
public function collections(): HasMany
public function category(): BelongsTo
```

`public_id` создаётся model event тем же UUID pattern, что collection. Casts:
`position => integer`, `is_active => boolean`.

**Step 5: Run GREEN and style**

```bash
php artisan test --filter=CatalogCollectionCategorySchemaTest
./vendor/bin/pint --dirty --format agent
```

**Step 6: Review task diff**

```bash
git diff --check -- app/Models app/Services/Collections/CatalogCollectionSchema.php database/migrations tests/Feature/CatalogCollectionCategorySchemaTest.php
```

---

### Task 2: Добавить idempotent reference taxonomy без классификации контента

**Files:**

- Create: `tests/Feature/CatalogCollectionCategoryDefaultsTest.php`
- Create: `app/Services/Collections/CatalogCollectionCategoryDefaults.php`
- Create: `database/migrations/2026_07_25_140200_install_default_catalog_collection_categories.php`

**Step 1: Write RED**

Тест запускает data migration дважды и проверяет:

- пять корней и согласованный набор children;
- deterministic UUID/slug;
- RU/EN translations;
- повтор не создаёт дубли;
- существующие `catalog_collections.catalog_collection_category_id` остаются
  `null`;
- изменённое администратором имя не перезаписывается повтором runtime service.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionCategoryDefaultsTest
```

**Step 3: Implement default registry**

Сервис содержит immutable allowlisted reference rows:

```php
[
    'slug' => 'themes-and-genres',
    'translations' => ['ru' => 'Темы и жанры', 'en' => 'Themes and genres'],
    'children' => [
        // exact slugs and paired translations from the approved design
    ],
]
```

Migration вызывает `install()` только после существования DDL. Использовать
`upsert`/`updateOrInsert` и отдельные root/child phases. Никаких catalog-name
heuristics.

**Step 4: Run GREEN**

```bash
php artisan test --filter=CatalogCollectionCategoryDefaultsTest
./vendor/bin/pint --dirty --format agent
```

---

### Task 3: Реализовать category read/write boundary

**Files:**

- Create: `tests/Feature/CatalogCollectionCategoryServiceTest.php`
- Create: `tests/Feature/CatalogCollectionCategoryQueryTest.php`
- Create: `app/Services/Collections/CatalogCollectionCategoryQuery.php`
- Create: `app/Services/Collections/CatalogCollectionCategoryService.php`
- Modify: `app/DTOs/CatalogCollectionData.php`
- Modify: `app/Services/Collections/CatalogCollectionService.php`
- Modify: `app/Services/Collections/CatalogCollectionCacheInvalidator.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Step 1: Write service RED cases**

- verified owner may assign active root/child to own collection;
- unknown/inactive UUID rejected;
- child-of-child and cycles rejected in admin service;
- active child below inactive parent rejected;
- owner/editor public/unlisted create/update requires category;
- private create/update permits `null`;
- trusted source reconciliation may keep an unclassified source-managed
  collection and never invents/overwrites its category;
- archived assigned category remains readable but not newly selectable;
- user cannot create/rename/archive/reorder dictionary;
- `content.manage` actor can mutate dictionary;
- source/editorial reconciliation does not overwrite assignment;
- public category change invalidates only after commit.

**Step 2: Write query RED cases**

- localized name → RU → slug fallback;
- only active roots/children returned for public selector;
- assigned archived node available in owner editor context;
- no N+1 for full two-level tree.

**Step 3: Run RED**

```bash
php artisan test --filter=CatalogCollectionCategoryServiceTest
php artisan test --filter=CatalogCollectionCategoryQueryTest
```

**Step 4: Implement typed data and transactional service**

Добавить в DTO:

```php
public ?string $categoryPublicId = null
```

Внутри service разрешать UUID server-side, блокировать record, валидировать
depth/activity и записывать только internal category ID. Category dictionary
mutations используют `Gate::authorize('manage-catalog')`/canonical
`content.manage` permission path и existing admin audit recorder.

**Step 5: Run GREEN and related collection service tests**

```bash
php artisan test --filter=CatalogCollectionCategory
php artisan test --filter=CatalogCollectionServiceTest
./vendor/bin/pint --dirty --format agent
```

---

### Task 4: Подключить выбор категории в owner/editor flows

**Files:**

- Create: `tests/Feature/CatalogCollectionCategoryEditorTest.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: `app/Livewire/Collections/CatalogCollectionDashboard.php`
- Modify: `app/Livewire/Collections/CatalogCollectionMembershipManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-editor.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Step 1: Write RED**

- editor renders root/subcategory controls from service;
- current UUID hydrates without numeric ID;
- changing root resets incompatible child;
- public save without active category shows Russian validation;
- private save without category succeeds;
- inactive current category is shown as requiring replacement;
- forged чужой/unknown UUID is rejected server-side;

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionCategoryEditorTest
```

**Step 3: Implement dependent native controls**

Использовать `<select>` с `wire:model.live`, labels/help/error components,
minimum 44px height. Browser отправляет public UUID; child options derive only
from selected root. Не выполнять DB query из Blade.

**Step 4: Run GREEN**

```bash
php artisan test --filter=CatalogCollectionCategoryEditorTest
php artisan test --filter=CatalogCollectionEditor
./vendor/bin/pint --dirty --format agent
```

---

### Task 5: Добавить администраторское управление справочником

**Files:**

- Create: `tests/Feature/CatalogCollectionCategoryAdministrationTest.php`
- Create: `app/Livewire/Collections/CatalogCollectionCategoryManager.php`
- Create: `resources/views/livewire/collections/catalog-collection-category-manager.blade.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `app/Services/Administration/AdminAuditRecorder.php` only if the
  existing generic collection resource allowlist requires an additive code
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Step 1: Write RED**

- manager mounts only within existing `/admin/catalog?section=collections`;
- viewer without `content.manage` sees read-only tree/no mutation controls;
- permitted actor creates root/child with RU/EN names and stable slug;
- depth > 2, duplicate slug, missing RU, unsupported locale rejected;
- rename preserves slug/public UUID;
- reorder is sibling-bounded/deterministic;
- archive keeps assignments, removes node from public selectors;
- hydration repeats permission;
- successful mutation writes safe audit field names, failed validation writes
  no event.
- administrator may explicitly bulk-assign one active node to at most 100
  selected unclassified collection UUIDs; forged/duplicate/over-limit IDs
  fail and «all results» is not supported.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionCategoryAdministrationTest
```

**Step 3: Implement nested manager**

Не создавать route/controller. Forms use public UUID, optimistic timestamp or
content version, Form/Livewire validation with Russian messages. Use existing
panels/status pills and no inline CSS/business JS.

**Step 4: Run GREEN**

```bash
php artisan test --filter=CatalogCollectionCategoryAdministrationTest
php artisan test --filter=CatalogAdministrationPage
./vendor/bin/pint --dirty --format agent
```

---

### Task 6: Перевести public directory на двухфазную пагинацию

**Files:**

- Create: `tests/Feature/CatalogCollectionDirectoryCategoryTest.php`
- Modify: `tests/Feature/CatalogDiscoveryQueryBudgetTest.php` only by
  preserving its current foreign additions
- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionExplorer.php`

**Step 1: Write RED filter/pagination cases**

Проверить:

- root includes collections assigned to root and active children;
- child matches exact child;
- `uncategorized` matches `NULL`;
- invalid category/subcategory normalizes to empty;
- incompatible child/root pair resets child;
- search/sort/category survive paginator links;
- page 2 does not duplicate/skip equal-sort rows.

**Step 2: Write RED budget cases**

Seed enough collections/items, enable query listener and assert:

- phase 1 contains no `catalog_collection_items` aggregate;
- phase 2 references only current page IDs;
- no `fallback_poster_url` query;
- empty paginator performs no summary phase;
- query count does not grow with off-page collection count.

**Step 3: Run RED**

```bash
php artisan test --filter=CatalogCollectionDirectoryCategoryTest
php artisan test --filter=CatalogDiscoveryQueryBudgetTest
```

**Step 4: Implement phase 1**

Add typed parameters:

```php
public function publicDirectory(
    string $search = '',
    string $sort = 'featured',
    ?string $category = null,
    ?string $subcategory = null,
    int $perPage = 18,
    string $pageName = 'collectionsPage',
): LengthAwarePaginator
```

Build eligible ID paginator with deterministic `(sort,id)` and category
constraints. Search remains bound/normalized.

**Step 5: Implement phase 2**

For page IDs only, load explicit projection, bounded relations and grouped
counts. Restore order using `array_flip($ids)`. Replace paginator collection
without changing total/current page/path/query.

**Step 6: Run GREEN and inspect SQL plan**

```bash
php artisan test --filter=CatalogCollectionDirectoryCategoryTest
php artisan test --filter=CatalogDiscoveryQueryBudgetTest
php artisan test --filter=UnifiedDiscoveryCollectionsTest
./vendor/bin/pint --dirty --format agent
```

Record SQLite `EXPLAIN QUERY PLAN` evidence for unfiltered and category
filters in current-task plan.

---

### Task 7: Добавить category tree/counts и URL-state в explorer

**Files:**

- Create: `tests/Feature/CatalogCollectionExplorerCategoryTest.php`
- Modify: `app/Services/Collections/CatalogCollectionCategoryQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionExplorer.php`
- Modify: `resources/views/livewire/collections/catalog-collection-explorer.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Step 1: Write RED**

- URL keys exactly `collections_category` and `collections_subcategory`;
- selecting root resets page and incompatible child;
- counts query grouped once, excluding hidden/private;
- reset clears search/sort/category/subcategory/page;
- desktop tree and mobile selects have same options/counts;
- `uncategorized` count is explicit;
- empty states differ for search vs category.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionExplorerCategoryTest
```

**Step 3: Implement query/count view data**

Return immutable/preloaded view data; Blade does no queries. Cache only the
bounded public category count snapshot with a version owned by existing
collection invalidation.

**Step 4: Implement responsive explorer**

Desktop sidebar + result column, mobile native controls, no inner scroll,
visible active state and reset, pagination with scroll target.

**Step 5: Run GREEN**

```bash
php artisan test --filter=CatalogCollectionExplorerCategoryTest
php artisan test --filter=UnifiedDiscoveryCollectionsTest
./vendor/bin/pint --dirty --format agent
```

---

### Task 8: Сделать единый text-only collection card

**Files:**

- Create: `tests/Feature/CatalogCollectionTextPresentationTest.php`
- Modify: `app/View/Components/Collections/CollectionCard.php`
- Modify: `app/View/ViewModels/CatalogCollectionCardViewModel.php`
- Modify: `resources/views/components/collections/collection-card.blade.php`
- Modify: `resources/views/livewire/collections/catalog-collection-page.blade.php`
- Modify only if assertions require: home/title/profile/dashboard/search
  consumers already using the component

**Step 1: Write RED across consumers**

Assert no collection UI contains:

- `<img>`/poster frame for the collection;
- cover URL or `fallback_poster_url`;
- collection image alt/missing-cover copy.

Assert text row contains name, category breadcrumb, description, visible item
count, owner/status/date as allowed by context.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionTextPresentationTest
```

**Step 3: Remove image dependency from view model/component**

Delete `CatalogCollectionCoverService` injection and image properties.
Category label comes from preloaded relation, never a Blade query.

**Step 4: Implement semantic row**

Use `<article>`, clear linked heading, metadata, optional description/status,
responsive flex/grid, 44px linked action and no decorative thumbnail.

**Step 5: Run GREEN and related presentations**

```bash
php artisan test --filter=CatalogCollectionTextPresentationTest
php artisan test --filter=UnifiedDiscoveryCollectionsTest
php artisan test --filter=HdRezkaCollectionPresentationTest
./vendor/bin/pint --dirty --format agent
```

---

### Task 9: Остановить все новые cover writes и public cover reads

**Files:**

- Create: `tests/Feature/CatalogCollectionCoverRemovalTest.php`
- Delete after consumers are green:
  - `app/Services/Collections/CatalogCollectionCoverResponder.php`
  - `app/Services/Collections/CatalogCollectionCoverService.php`
  - `app/Services/Collections/Import/HdRezkaCollectionCoverImporter.php`
- Modify: `routes/web.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: `resources/views/livewire/collections/catalog-collection-editor.blade.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionParser.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionReconciler.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionSyncService.php`
- Modify: `app/Models/CatalogCollection.php`
- Modify: `app/Models/CatalogCollectionSource.php`
- Modify: `app/Services/Collections/CatalogCollectionService.php`
- Modify: `app/Services/Collections/CatalogCollectionAccountService.php`
- Modify all remaining runtime consumers from:

```bash
rg -l "CatalogCollectionCover|collections\\.cover|cover_(disk|path|mime_type|size|version|source_path|content_hash)|fallback_poster_url" app config lang resources routes
```

**Step 1: Write RED**

- old cover route is absent/404;
- editor has no upload/remove methods/state/control;
- sync parser/reconciler ignores remote collection cover and performs no
  cover HTTP request;
- account delete/force delete does not gather or remove collection cover;
- demo path creates no collection image;
- no collection view model resolves first title poster.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionCoverRemovalTest
php artisan test --filter=HdRezkaCollectionSyncTest
```

**Step 3: Remove runtime feature**

Remove code paths in dependency order. Do not yet drop legacy DB columns:
compatibility reads/writes are simply absent. Preserve unrelated profile
cover/avatar code returned by broad `cover_*` searches.

**Step 4: Replace/delete obsolete tests**

Delete only cover-feature tests whose contract is intentionally removed;
replace with negative regressions. Preserve importer membership, parser and
source schema coverage.

**Step 5: Run GREEN**

```bash
php artisan test --filter=CatalogCollectionCoverRemovalTest
php artisan test --filter=HdRezkaCollection
php artisan test --filter=CatalogCollection
./vendor/bin/pint --dirty --format agent
```

---

### Task 10: Удалить collection images из API, SEO, sitemap и exports

**Files:**

- Create: `tests/Feature/CatalogCollectionExternalContractTest.php`
- Modify: `app/Http/Resources/Api/V1/CatalogCollectionResource.php`
- Modify: `app/Services/Collections/CatalogCollectionSeoPresenter.php`
- Modify: `app/Services/Catalog/CatalogSitemapResponder.php`
- Modify: `app/Services/Collections/CatalogCollectionAccountService.php`
- Modify: profile/export DTOs and presenters only where collection image data
  is actually projected
- Modify: `resources/api/openapi.json`

**Step 1: Write RED**

- API v1 returns `cover_url: null` plus category `{slug,name,parent}`;
- no numeric category ID/private source path;
- collection JSON-LD/OpenGraph has no image/image_alt;
- sitemap collection URLs have no image extension;
- account export has stable category slug/name and no cover metadata;
- collection detail remains canonical/localized.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionExternalContractTest
```

**Step 3: Implement additive external shape**

Eager-load category translations in controller/query boundary. OpenAPI marks
`cover_url` deprecated/nullable and documents additive category object.

**Step 4: Run GREEN**

```bash
php artisan test --filter=CatalogCollectionExternalContractTest
php artisan test --filter=CatalogSitemap
php artisan test --filter=CatalogCollectionApi
./vendor/bin/pint --dirty --format agent
```

---

### Task 11: Обновить общий discovery UI без изменения mode contract

**Files:**

- Create: `tests/Feature/CatalogDiscoveryLayoutTest.php`
- Modify: `resources/views/livewire/catalog-discovery-page.blade.php`
- Modify only if needed, preserving foreign diff:
  `app/Livewire/CatalogDiscoveryPage.php`
- Modify: `lang/ru/recommendations.php` or actual discovery catalog owner
- Modify: `lang/en/recommendations.php` or paired owner

**Step 1: Write RED**

- each of nine mode routes has one H1 and mode-specific summary;
- navigation exposes all implemented modes without fake links;
- refresh is not the primary heading action;
- popular page clearly separates titles from collection explorer;
- optional facets are collapsed/conditional without losing URL state;
- no `/discover` landing link is introduced;
- localized route/canonical/hreflang unchanged.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogDiscoveryLayoutTest
php artisan test --filter=CatalogDiscoveryInteractionTest
```

**Step 3: Implement Blade-first layout**

Prefer existing UI components and Tailwind tokens. Do not add inline CSS,
business JS, gradient, fake personalization or new backend state unless a RED
case proves it necessary.

**Step 4: Run GREEN**

```bash
php artisan test --filter=CatalogDiscoveryLayoutTest
php artisan test --filter=CatalogDiscovery
./vendor/bin/pint --dirty --format agent
npm run build
```

---

### Task 12: Реализовать exact-prefix cover purge

**Files:**

- Create: `tests/Feature/PurgeCatalogCollectionCoversCommandTest.php`
- Create: `app/Services/Collections/CatalogCollectionCoverPurgeService.php`
- Create: `app/Console/Commands/PurgeCatalogCollectionCovers.php`
- Modify: `docs/storage.md`
- Modify: `docs/requirements/production-operations.md` only if a new
  permanent operator rule is required; otherwise update thematic runbook

**Step 1: Write RED with fake disk/database**

- default mode is dry-run and mutates nothing;
- only `--execute` mutates;
- prefix must equal `catalog-collections/`, not caller input/glob;
- files inside prefix deleted; sibling poster/profile files untouched;
- collection/source metadata cleared in chunks while columns exist;
- repeated execute returns success/no-op;
- one storage failure returns non-zero and leaves schema-drop readiness false;
- output contains counts/bytes only, no private paths.

**Step 2: Run RED**

```bash
php artisan test --filter=PurgeCatalogCollectionCoversCommandTest
```

**Step 3: Implement guarded service/command**

Command signature:

```php
protected $signature = 'catalog-collections:purge-covers {--execute}';
```

Service owns constant prefix and configured disk. It enumerates only that
prefix, deletes in bounded batches, clears metadata with `chunkById`, and
reports an immutable safe result DTO.

**Step 4: Run GREEN**

```bash
php artisan test --filter=PurgeCatalogCollectionCoversCommandTest
./vendor/bin/pint --dirty --format agent
```

**Step 5: Local dry-run only**

```bash
php artisan catalog-collections:purge-covers
```

Compare safe counts/bytes to the baseline in current-task plan. Do not execute
until Tasks 8–10 and all focused tests are green.

---

### Task 13: Выполнить локальную необратимую очистку

**Files/data:**

- Mutate exact DB cover metadata rows in current configured SQLite database.
- Permanently delete only
  `storage/app/private/uploads/catalog-collections/**`.
- Update evidence in `docs/plans/current-task-plan.md`.

**Step 1: Pre-execute gates**

```bash
git status --short --branch
php artisan test --filter=CatalogCollectionCoverRemovalTest
php artisan test --filter=PurgeCatalogCollectionCoversCommandTest
php artisan catalog-collections:purge-covers
```

Require exact disk/prefix, expected bounded counts and no foreign target.

**Step 2: Execute explicit irreversible cleanup**

```bash
php artisan catalog-collections:purge-covers --execute
```

No backup/trash copy is created by explicit user instruction.

**Step 3: Verify zero residue**

Run the command in dry-run again and direct read-only DB/storage checks.
Require:

- zero files/bytes under exact prefix;
- zero non-null collection/source cover metadata;
- other uploads still present/unmodified;
- no cover HTTP/UI/API generation.

If any residue/failure remains, stop before Task 14 and record
`unresolved_cleanup`.

---

### Task 14: Удалить legacy cover columns после zero-residue

**Files:**

- Create: `tests/Feature/CatalogCollectionCoverSchemaRemovalTest.php`
- Create: `database/migrations/2026_07_25_140300_drop_catalog_collection_cover_columns.php`
- Modify: `app/Services/Collections/CatalogCollectionSchema.php`
- Modify: `app/Models/CatalogCollection.php`
- Modify: `app/Models/CatalogCollectionSource.php`
- Modify importer/source schema tests

**Step 1: Write RED**

Assert columns no longer exist in migrated test schema and importer/source
capabilities no longer require them.

**Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionCoverSchemaRemovalTest
```

**Step 3: Create destructive migration**

Drop only:

```php
// catalog_collections
cover_disk, cover_path, cover_mime_type, cover_size, cover_version

// catalog_collection_sources
cover_source_path, cover_path, cover_content_hash
```

`down()` may recreate nullable columns for code rollback but cannot restore
deleted files/metadata; document this explicitly.

**Step 4: Run GREEN and migration cycle in isolated test DB**

```bash
php artisan test --filter=CatalogCollectionCoverSchemaRemovalTest
php artisan test --filter=HdRezkaCollectionSourceSchemaTest
./vendor/bin/pint --dirty --format agent
```

Do not run broad destructive migration commands against production. Normal
deployment migration only after Task 13 readiness evidence.

---

### Task 15: Обновить canonical documentation и visitor history

**Files:**

- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/architecture.md`
- Modify: `docs/authorization.md`
- Modify: `docs/administration.md`
- Modify: `docs/views.md`
- Modify: `docs/frontend.md`
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/storage.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/system-wide-integration.md`
- Modify affected importer/API documents and `docs/README.md` only if topic
  ownership changes
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

**Step 1: Update owners**

Remove obsolete cover promises and document:

- one two-level taxonomy;
- authorization and assignment invariants;
- two-phase public directory;
- text-only collection UI;
- cover cleanup/storage/schema removal;
- API compatibility and rollout/rollback limits.

Historical dated specs/plans remain evidence and are not rewritten.

**Step 2: Update README**

Add a meaningful Russian visitor-facing entry describing category navigation
and text-only подборки. Keep `История обновлений для посетителей` as the final
H2. Do not edit managed `project-docs` block manually.

**Step 3: Add Russian CHANGELOG entry**

One dated Task 51 entry, without deleting/merging previous history.

**Step 4: Refresh/check managed docs**

```bash
php artisan project:docs-refresh
php artisan project:docs-refresh --check --no-interaction --no-ansi
```

If write-refresh would absorb unrelated untracked migration inventory, record
the exact shared blocker instead of rewriting foreign scope.

---

### Task 16: Финальная verification, legacy audit и delivery

**Files:**

- Modify final statuses/evidence:
  `docs/plans/current-task-plan.md`

**Step 1: Re-read requirements**

Fresh read root `AGENTS.md`, requirement index and every Task 51 canonical
owner. Reconcile matrix with evidence only.

**Step 2: Repository-wide legacy scans**

```bash
rg -n "CatalogCollectionCover|collections\\.cover|cover_(disk|path|mime_type|size|version|source_path|content_hash)|fallback_poster_url" app bootstrap config database lang resources routes tests docs README.md
rg -n "catalog_collection_category|collections_category|collections_subcategory" app database lang resources routes tests docs
```

Remaining matches must be historical evidence, profile/title cover domains or
intentional deprecated API `cover_url=null`; classify each dependency before
deletion.

**Step 3: Focused and broad verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogCollectionCategory
php artisan test --filter=CatalogCollection
php artisan test --filter=CatalogDiscovery
php artisan test --filter=HdRezkaCollection
php artisan test
npm run build
```

Run Playwright browser QA for `/discover/popular`, one other discovery mode,
collection detail, owner editor and admin catalog at mobile/tablet/desktop.
Verify keyboard focus, no collection image, dependent filters, paginator,
44px targets, no inner scroll and RU/EN parity.

**Step 4: Data/query verification**

- zero exact cover files/metadata;
- category/reference counts and orphan/cycle checks;
- `EXPLAIN QUERY PLAN` uses category/public indexes;
- page query count bounded;
- routes inventory preserves all contracts except cover route.

**Step 5: Exact diff and shared-worktree audit**

```bash
git status --short --branch
git diff --check
git diff --cached --check
```

Review every Task 51 path and ensure current branch is `main`. Do not claim
unrelated full-tree failures as Task 51 success.

**Step 6: Commit and push when safe**

If and only if the index can contain the complete authorized Task 51 scope
without foreign changes:

Stage каждый exact reviewed Task 51 path отдельно через `git add --`,
без glob и без списка, полученного из всего mixed working tree. Затем:

```bash
git commit -m "feat: obnovit kategorii podborok i discovery"
git push
```

If mixed staged ownership/hook failures remain, do not unstage/reset чужие
files and record commit/push as `unresolved_shared_index_collision`.

**Step 7: Final compliance report**

Report each applicable requirement as `completed`,
`already_compliant`, `not_applicable` or honest `unresolved`, with file/test/
data evidence. Distinguish local cleanup from any production execution not
actually performed.
