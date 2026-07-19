# Livewire Pagination Islands Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать все найденные web-пагинаторы локальными Livewire islands с единым spinner и мягкой прокруткой к точному result block с динамическим offset верхнего меню; финальный inventory — `54` вызова в `40` шаблонах.

**Architecture:** Один пассивный `x-ui.pagination-region` задаёт loading/scroll DOM contract, опубликованный Livewire pagination view остаётся единственным producer controls, а `resources/js/app.js` выполняет immediate/post-morph scroll. Каждый paginated result получает уникальный named `@island(always: true, with: $this->...Page)`; root render и island используют один computed presentation array, не меняя domain queries или URL page names.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3 class-based components/islands, Blade, Tailwind CSS 4.3, Vite 8, PHPUnit 12.5, Playwright managed Chromium.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree/PR.
- Не менять dependencies, lock files, `.env`, schema, data, cache/session/queue или public routes.
- Сохранить реальные `href`, named paginator query keys, back/forward, locale и no-JavaScript fallback.
- Не использовать Volt, `@php`, inline CSS, inline business JavaScript, Blade queries/services или hardcoded user-facing copy.
- Каждый pagination island объявляется вне Blade condition/loop; conditions и loops находятся внутри island.
- Spinner и `aria-busy` относятся только к control ближайшего region; старое содержимое остаётся на месте до успешного morph.
- Sticky header измеряется в runtime; static mobile header не вычитается. Дополнительный gap равен `1rem`; reduced motion отключает animation.
- Сначала focused PHPUnit, затем Pint для PHP, Vite build, полный релевантный suite, browser matrix, docs check.

---

### Task 1: Зафиксировать полный pagination inventory красным contract test

**Files:**
- Modify: `tests/Unit/FrontendAssetContractTest.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`

**Interfaces:**
- Consumes: все `resources/views/**/*.blade.php` с `->links(`.
- Produces: repository gate `test_every_livewire_paginator_declares_a_unique_island_region_contract`.

- [x] **Step 1: Написать failing inventory test**

Добавить test, который объединяет Blade templates, утверждает актуальный baseline вызовов `->links(`, требует для каждого вызова форму `->links(data: ['region' => '...'])`, равное число `<x-ui.pagination-region`, наличие `@island(... with: $this->...)` в каждом затронутом template и уникальность `region` внутри одного template.

```php
public function test_every_livewire_paginator_declares_a_unique_island_region_contract(): void
{
    $templates = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->mapWithKeys(fn (SplFileInfo $file): array => [$file->getPathname() => File::get($file->getPathname())])
        ->filter(fn (string $contents): bool => str_contains($contents, '->links('));

    $this->assertSame(40, $templates->count());
    $this->assertSame(54, $templates->sum(fn (string $contents): int => substr_count($contents, '->links(')));

    foreach ($templates as $path => $contents) {
        preg_match_all("/->links\\(data: \\['region' => '([^']+)'/", $contents, $matches);
        $links = substr_count($contents, '->links(');

        $this->assertCount($links, $matches[1], $path);
        $this->assertSame($matches[1], array_values(array_unique($matches[1])), $path);
        $this->assertSame($links, substr_count($contents, '<x-ui.pagination-region'), $path);
        $this->assertStringContainsString('@island(', $contents, $path);
        $this->assertStringContainsString('with: $this->', $contents, $path);
    }
}
```

- [x] **Step 2: Запустить test и подтвердить RED**

Run: `php artisan test --filter=FrontendAssetContractTest::test_every_livewire_paginator_declares_a_unique_island_region_contract`

Expected: FAIL, потому что только шесть links имеют target, region component/islands ещё отсутствуют.

- [x] **Step 3: Не писать production code в этой задаче**

Зафиксировать точный failure output в current task evidence; GREEN достигается только после Tasks 2–6.

---

### Task 2: Реализовать shared pagination frame, spinner и scroll runtime через TDD

**Files:**
- Create: `resources/views/components/ui/pagination-region.blade.php`
- Modify: `resources/views/vendor/livewire/tailwind.blade.php`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css`
- Modify: `lang/ru/pagination.php`
- Modify: `lang/en/pagination.php`
- Modify: `tests/Unit/FrontendAssetContractTest.php`

**Interfaces:**
- Produces: `<x-ui.pagination-region name="...">`, `data-pagination-region`, `data-pagination-scroll-target`, `data-pagination-loading`, `data-pagination-content`.
- Produces: control metadata `data-pagination-control`, `data-pagination-page-name`, optional `data-pagination-scroll-to`.
- Produces: runtime functions `paginationHeaderOffset()`, `paginationTargetTop()`, `startPaginationScroll()`, `finishPaginationScroll()`.

- [x] **Step 1: Расширить shared contract test и запустить RED**

Проверить в component/view/assets exact markers, `pagination.loading`, `--pagination-scroll-gap: 1rem`, `position === 'sticky' || position === 'fixed'`, `prefers-reduced-motion`, `easeInOutCubic`, `Livewire.interceptMessage`, `island.morphed`, а также отсутствие `scrollIntoView()` и `@php` в app pagination view.

- [x] **Step 2: Создать пассивный frame**

```blade
@props(['name'])

<section
    data-pagination-region="{{ $name }}"
    data-pagination-scroll-target
    aria-busy="false"
    {{ $attributes->class(['relative min-w-0']) }}
>
    <div data-pagination-loading class="pointer-events-none absolute inset-x-0 top-3 z-30 hidden justify-center px-3" role="status" aria-live="polite" aria-atomic="true">
        <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white/95 px-4 py-2 text-sm font-black text-emerald-800 shadow-panel">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
            <span>{{ __('pagination.loading') }}</span>
        </span>
    </div>
    <div data-pagination-content class="min-w-0 transition-opacity duration-200 motion-reduce:transition-none">
        {{ $slot }}
    </div>
</section>
```

- [x] **Step 3: Обновить RU/EN parity**

Добавить `loading => 'Загружаем страницу'` и `loading => 'Loading page'` без изменения stable keys остальных labels.

- [x] **Step 4: Обновить shared pagination controls**

Каждый active anchor получает `data-pagination-control`, `data-pagination-page-name`, `data-pagination-scroll-to="{{ $scrollTo ?? '' }}"`, реальный `href`, named Livewire action и `class="... data-loading:pointer-events-none data-loading:opacity-60"`. `region` используется только как explicit call-site contract и не выводится как доверенный selector.

- [x] **Step 5: Реализовать CSS loading state**

В `:root` добавить `--pagination-scroll-gap: 1rem`. Через `:has([data-pagination-control][data-loading])` показывать `[data-pagination-loading]`, снижать opacity `[data-pagination-content]`, блокировать pointer events и задавать wait cursor. Не менять layout height и не создавать внутреннюю scroll area.

- [x] **Step 6: Реализовать JS scroll lifecycle**

На primary unmodified click найти closest region, поставить `aria-busy=true`, сразу вызвать scroll. `paginationHeaderOffset()` вычитает фактический bottom только computed sticky/fixed header, затем gap. Duration bounded `520..820 ms`, easing `easeInOutCubic`; reduced motion и correction `<24 px` мгновенны. `morphed`/`island.morphed` повторно разрешают target; `interceptMessage` finish/error/failure и navigation очищают state/animation.

- [x] **Step 7: Запустить focused test до GREEN**

Run: `php artisan test --filter=FrontendAssetContractTest`

Expected: shared presentation/runtime tests PASS, global inventory test остаётся RED до миграции call sites.

---

### Task 3: Мигрировать core catalog paginators на named islands

**Files:**
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `app/Livewire/CatalogDirectoryBrowser.php`
- Modify: `resources/views/livewire/catalog-directory-browser.blade.php`
- Modify: `app/Livewire/ViewingActivity.php`
- Modify: `resources/views/livewire/viewing-activity.blade.php`
- Modify: `app/Livewire/CatalogAdministrationManager.php`
- Modify: `resources/views/livewire/catalog-administration-manager.blade.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Produces computed presentation contracts `catalogPage`, `directoryPage`, `viewingActivityPage`, `catalogAdministrationPage`.
- Produces island names `catalog-pagination`, `directory-pagination`, `viewing-history-pagination`, `admin-catalog-pagination`.

- [x] **Step 1: Написать failing Livewire island assertions**

Проверить `@island(name: ..., always: true, with: $this->...)`, `<x-ui.pagination-region`, region data in `links()`, named URL state и island fragment в Livewire response.

- [x] **Step 2: Запустить catalog tests и подтвердить RED**

Run: `php artisan test --filter='CatalogPageTest|CatalogVisualSystemTest'`

- [x] **Step 3: Перенести render data в computed contracts**

Использовать существующий `boot()` injection. `render()` и island читают один `#[Computed]` array; out-of-range redirect и title warm side effects остаются в прежней root boundary, не дублируются в Blade.

- [x] **Step 4: Разметить exact result blocks**

Catalog results получают вложенный `catalog-pagination` внутри существующего `catalog-live`; остальные три templates получают по одному `always` island. Весь list/empty/paginator slot находится внутри frame, header/forms остаются вне, если не зависят от current page.

- [x] **Step 5: Запустить focused tests до GREEN**

Run: `php artisan test --filter='CatalogPageTest|CatalogVisualSystemTest|FrontendAssetContractTest'`

---

### Task 4: Мигрировать collections, tags, library и profile pagination islands

**Files:**
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: `app/Livewire/Collections/CatalogCollectionDashboard.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: `app/Livewire/Collections/CatalogCollectionExplorer.php`
- Modify: `app/Livewire/Collections/CatalogCollectionPage.php`
- Modify: `app/Livewire/Collections/CatalogCollectionProfile.php`
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `resources/views/livewire/collections/catalog-collection-dashboard.blade.php`
- Modify: `resources/views/livewire/collections/catalog-collection-editor.blade.php`
- Modify: `resources/views/livewire/collections/catalog-collection-explorer.blade.php`
- Modify: `resources/views/livewire/collections/catalog-collection-page.blade.php`
- Modify: `resources/views/livewire/collections/catalog-collection-profile.blade.php`
- Modify: `app/Livewire/Tags/PersonalTagManager.php`
- Modify: `app/Livewire/Tags/TagAdministrationManager.php`
- Modify: `resources/views/livewire/tags/personal-tag-manager.blade.php`
- Modify: `resources/views/livewire/tags/tag-administration-manager.blade.php`
- Modify: `app/Livewire/Library/UserLibraryPage.php`
- Modify: `resources/views/livewire/library/user-library-page.blade.php`
- Modify: `app/Livewire/Profile/UserProfileAdministrationManager.php`
- Modify: `app/Livewire/Profile/PublicProfilePage.php`
- Modify: `app/Livewire/Profile/ReviewHistoryPage.php`
- Modify: `resources/views/livewire/profile/administration-manager.blade.php`
- Modify: `resources/views/livewire/profile/public-profile-page.blade.php`
- Modify: `resources/views/livewire/profile/review-history-page.blade.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`
- Test: `tests/Feature/Web/UserLibraryPageTest.php`
- Test: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

**Interfaces:**
- Produces one unique region/page-name pair per existing paginator, including separate active/deleted collections and the four library sections.

- [x] **Step 1: Добавить failing static/feature assertions для каждого template group**

Assertions требуют distinct region strings внутри multi-paginator templates и сохраняют прежние page names.

- [x] **Step 2: Запустить focused group tests и подтвердить RED**

Run: `php artisan test --filter='Collection|Tag|UserLibrary|Profile'`

- [x] **Step 3: Добавить computed view-data и islands**

Каждый component переиспользует existing query/policy services. Multi-paginator component может использовать один memoized computed presentation array, но каждый DOM result получает отдельный island/region; page names не переименовываются.

- [x] **Step 4: Запустить focused group tests до GREEN**

Run: `php artisan test --filter='Collection|Tag|UserLibrary|Profile|FrontendAssetContractTest'`

---

### Task 5: Мигрировать comments, reviews, requests и technical issues

**Files:**
- Modify: `app/Livewire/Comments/CommentAdministrationManager.php`
- Modify: `app/Livewire/Comments/CommentDiscussion.php`
- Modify: `resources/views/livewire/comments/comment-administration-manager.blade.php`
- Modify: `resources/views/livewire/comments/comment-discussion.blade.php`
- Modify: `app/Livewire/Reviews/CatalogTitleReviews.php`
- Modify: `app/Livewire/Reviews/ReviewModerationManager.php`
- Modify: `resources/views/livewire/reviews/catalog-title-reviews.blade.php`
- Modify: `resources/views/livewire/reviews/review-moderation-manager.blade.php`
- Modify: `app/Livewire/ContentRequests/ContentRequestAdministrationManager.php`
- Modify: `app/Livewire/ContentRequests/ContentRequestDirectory.php`
- Modify: `app/Livewire/ContentRequests/MyContentRequestsPage.php`
- Modify: `resources/views/livewire/content-requests/administration-manager.blade.php`
- Modify: `resources/views/livewire/content-requests/directory.blade.php`
- Modify: `resources/views/livewire/content-requests/mine-page.blade.php`
- Modify: `app/Livewire/TechnicalIssues/MyTechnicalIssuesPage.php`
- Modify: `app/Livewire/TechnicalIssues/TechnicalIssueAdministrationManager.php`
- Modify: `app/Livewire/TechnicalIssues/TechnicalIssueDetailPage.php`
- Modify: `app/Livewire/TechnicalIssues/TechnicalIssueNotificationsPanel.php`
- Modify: `resources/views/livewire/technical-issues/administration-manager.blade.php`
- Modify: `resources/views/livewire/technical-issues/detail-page.blade.php`
- Modify: `resources/views/livewire/technical-issues/mine-page.blade.php`
- Modify: `resources/views/livewire/technical-issues/notifications-panel.blade.php`
- Modify: `app/Livewire/Profile/DiscussionPage.php`
- Modify: `resources/views/livewire/profile/discussion-page.blade.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`
- Create: `tests/Feature/PaginationIslandIntegrationTest.php`

**Interfaces:**
- Produces unique regions for discussion, review moderation/history, notifications, blocks/mutes, request directories/mine/admin and issue list/messages/notifications.

- [x] **Step 1: Добавить failing assertions**

Проверить multiple independent paginator state в `DiscussionPage`, точный message paginator в issue detail и отсутствие broad spinner от unrelated comment/review actions.

- [x] **Step 2: Запустить focused tests и подтвердить RED**

Run: `php artisan test --filter='Comment|Review|ContentRequest|TechnicalIssue'`

- [x] **Step 3: Реализовать computed data/islands/regions**

Не менять moderation, privacy, block/mute, notification или attachment boundaries; island получает только прежние prepared view values.

- [x] **Step 4: Запустить focused tests до GREEN**

Run: `php artisan test --filter='Comment|Review|ContentRequest|TechnicalIssue|FrontendAssetContractTest'`

---

### Task 6: Мигрировать help, premium, release calendar и settings/admin paginators

**Files:**
- Modify: `app/Livewire/HelpCenter/HelpCategoryPage.php`
- Modify: `app/Livewire/HelpCenter/HelpCenterAdministrationPage.php`
- Modify: `app/Livewire/HelpCenter/HelpSearchPage.php`
- Modify: `resources/views/livewire/help-center/administration.blade.php`
- Modify: `resources/views/livewire/help-center/category.blade.php`
- Modify: `resources/views/livewire/help-center/search.blade.php`
- Modify: `app/Livewire/Premium/PremiumAdministrationManager.php`
- Modify: `app/Livewire/Premium/PremiumNotificationsPanel.php`
- Modify: `resources/views/livewire/premium/administration-manager.blade.php`
- Modify: `resources/views/livewire/premium/notifications-panel.blade.php`
- Modify: `app/Livewire/ReleaseCalendar/ReleaseCalendarAdministrationManager.php`
- Modify: `app/Livewire/ReleaseCalendar/ReleaseCalendarNotificationsPanel.php`
- Modify: `app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php`
- Modify: `resources/views/livewire/release-calendar/release-calendar-administration-manager.blade.php`
- Modify: `resources/views/livewire/release-calendar/release-calendar-notifications-panel.blade.php`
- Modify: `resources/views/livewire/release-calendar/release-calendar-page.blade.php`
- Modify: `app/Livewire/Settings/AccountSettingsPage.php`
- Modify: `resources/views/livewire/settings/account-settings-page.blade.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`
- Test: `tests/Feature/PaginationIslandIntegrationTest.php`
- Test: `tests/Feature/ReleaseCalendarDefaultViewTest.php`

**Interfaces:**
- Produces separate article/report/feedback, premium audit/notification/payment and calendar/admin/notification regions.

- [x] **Step 1: Добавить failing assertions и запустить RED**

Run: `php artisan test --filter='Help|Premium|ReleaseCalendar|AccountSettings'`

- [x] **Step 2: Реализовать computed data/islands/regions**

Сохранить private/noindex, gate, payment, revision, locale fallback и notification state. Ни один provider/payment identifier не добавлять в DOM metadata.

- [x] **Step 3: Запустить group tests до GREEN**

Run: `php artisan test --filter='Help|Premium|ReleaseCalendar|AccountSettings|FrontendAssetContractTest'`

- [x] **Step 4: Закрыть global inventory test**

Run: `php artisan test --filter=FrontendAssetContractTest::test_every_livewire_paginator_declares_a_unique_island_region_contract`

Expected: PASS, `40` templates / `54` links полностью размечены.

---

### Task 7: Browser verification dynamic header offset, spinner и island isolation

**Files:**
- Modify: `tests/browser/catalog.spec.js`

**Interfaces:**
- Verifies production-like built assets and real Livewire network/morph behavior.

- [x] **Step 1: Добавить failing browser scenarios**

Interception delays Livewire response long enough to assert local spinner and preserved old content. Capture changed island markers before/after page click, target/header rects, URL and overflow at `390×844`, tablet and `1440×1200`; add reduced-motion context.

- [x] **Step 2: Запустить focused browser test и подтвердить RED**

Run: `npx playwright test tests/browser/catalog.spec.js --grep "pagination island"`

- [x] **Step 3: Исправить только выявленные integration defects**

Не добавлять arbitrary breakpoint constants; корректировать shared frame/runtime или exact island scope.

- [x] **Step 4: Запустить focused browser test до GREEN**

Run: `npx playwright test tests/browser/catalog.spec.js --grep "pagination island"`

---

### Task 8: Documentation, full verification и delivery

**Files:**
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`
- Modify: `README.md`

**Interfaces:**
- Produces permanent owner contract, final compliance evidence and visitor-visible Russian history.

- [x] **Step 1: Обновить owner docs и evidence**

Зафиксировать shared region/island/data-loading/dynamic-header/reduced-motion contract, inventory result, rollback and unresolved shared-worktree state. README visitor history описывает только заметный результат.

- [x] **Step 2: Запустить format/static/build gates**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=FrontendAssetContractTest
php artisan test --filter='CatalogPageTest|CatalogVisualSystemTest'
npm run build
php artisan project:docs-refresh --check
```

- [x] **Step 3: Запустить широкий backend/frontend/browser gate**

Run: `bash scripts/ci-check.sh frontend`, затем `bash scripts/ci-check.sh browser`. Если общий snapshot содержит unrelated failures, повторить команды Task 2–7 и записать exact ownership без маскировки.

- [x] **Step 4: Выполнить final repository search**

Искать оставшиеся `->links()` без region, package/default pagination views, inline `scrollIntoView`, duplicate spinner/runtime, stale `scroll-mt-*` pagination assumptions, `@php`, unfinished island markers и legacy selectors. Проверять dependencies до удаления.

- [x] **Step 5: Проверить `main`, staged scope и delivery**

Run `git status --short --branch`. Commit/push только если изменения безопасно отделимы от чужих staged/unstaged files и hooks проходят; иначе оставить явный unresolved blocker без destructive reset/stash/unstage.

## Итог выполнения

- Inventory: `40` Blade templates / `54` paginator calls; contract test — `6` tests / `325` assertions.
- Targeted browser: `4 passed`, `2` expected reduced-motion skips на desktop/mobile/tablet. Финальная проверка воспроизвела и закрыла отдельный test-only flake: количество объединённых `scroll` events зависело от загрузки Chromium, поэтому контракт теперь проверяет промежуточное положение, длительность не менее `500 ms` и точную конечную геометрию независимо от FPS. Полный browser: `39 passed`, `4 skipped`, два unrelated transient desktop title/player failures, оба отдельными повторами прошли.
- Полный PHPUnit: `1 364` tests, `1 346` passed, `120 421` assertions, `11` skipped, семь order-dependent ошибок параллельного admin audit/catalog selection; pagination tests не падали, один representative backend failure отдельно прошёл.
- `Pint`, `npm audit`, Vite build, Blade cache, docs-refresh и diff check прошли. Commit/push остаются `unresolved`: общий `main` содержит многочисленные перекрывающиеся staged/unstaged изменения нескольких задач.
