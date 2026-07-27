# Адаптивная панель действий фильтров каталога — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перестроить действия фильтров `/titles` в устойчивую вертикальную
полноширинную панель, которая вмещает динамическую подпись
«Показать 33 005 сериалов» в `18rem` sidebar и на compact viewport без
горизонтального переполнения.

**Architecture:** Существующие `CatalogSeries`, единая GET/Livewire-форма,
`data-catalog-filter-*` hooks и `mobile-runtime.js` сохраняются. Изменение
ограничено presentation-классами одного Blade-компонента; PHP и Playwright
закрепляют геометрию, доступность и прежние action contracts до обновления
канонической документации и delivery evidence.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Livewire 4, Blade, Tailwind CSS
4.3.2, Vite 8.1.4, PHPUnit 12, Playwright 1.61.1.

## Global Constraints

- Работать только в существующей ветке `main`; branch, worktree и PR не
  создавать.
- До первого изменения расширенного scope получить/сохранить workspace lease
  `task-118-catalog-filter-actions` и объявить точный NUL-delimited manifest.
- Один существующий `<details id="catalog-filters">`, одна форма и один
  `CatalogSeries` state остаются единственными владельцами фильтров.
- `applyFilters`, `resetAll`, `data-catalog-filter-cancel`,
  `data-catalog-filter-submit-label`, GET fallback и query parameters не
  переименовывать.
- Три действия всегда располагаются одной вертикальной колонкой и занимают
  полную ширину своего container; viewport breakpoint не превращает их в
  строку.
- Каждый control имеет минимум `44×44` CSS px, видимый `focus-visible`,
  переносимый центрированный текст и не зависит от hover.
- Светлая slate/white тема и `emerald-700` primary CTA сохраняются; gradient,
  glassmorphism, inline CSS и inline JavaScript не добавляются.
- Новые routes, translations, Livewire state/actions, JavaScript, packages,
  queries, migrations, API fields, cache keys и permissions не добавляются.
- Посторонние изменения `composer.lock` и
  `storage/debugbar/.gitignore` не редактировать и не включать в index.
- Любой кодовый commit включает осмысленные русские `README.md` и
  `CHANGELOG.md`; final push выполняется только после полного review и
  восстановления protected pre-existing changes.

---

## File map

| Path | Responsibility |
| --- | --- |
| `resources/views/components/catalog/unified-title-filters.blade.php` | Единственная action panel формы фильтров |
| `tests/Feature/CatalogVisualSystemTest.php` | Статический Blade/behavior contract и RED/GREEN regression |
| `tests/browser/catalog.spec.js` | Реальная desktop/phone геометрия, focus и отсутствие overflow |
| `docs/UI_STANDARDS.md` | Постоянный mobile/accessibility contract action stack |
| `docs/frontend.md` | Lifecycle существующей формы и client hooks |
| `README.md` | Видимый посетителю результат исправления |
| `CHANGELOG.md` | Русская техническая запись реализации и verification |
| `docs/plans/current-task-plan.md` | Краткий active registry и текущий status |
| `docs/plans/task-118-catalog-filter-actions-compliance.md` | Task-specific compliance matrix |
| `docs/plans/archive/2026-07-27-catalog-filter-actions-evidence.md` | Финальное immutable evidence |

## Protected public contracts

- `GET /titles`, `titles.index`, taxonomy/year routes, query names и
  canonical/noindex policy;
- `CatalogSeries`, `CatalogSeriesFilters`, `CatalogTitlesRequest`,
  `CatalogTitlesPageBuilder`, Livewire islands и paginator history;
- submit `wire:target="applyFilters"`, cancel DOM hook и reset
  `wire:click.prevent="resetAll"`;
- динамический count, который `mobile-runtime.js` берёт из
  `data-catalog-current-result-label`;
- RU/EN translation keys и plural result label;
- API Resources, schema, queries, cache identities, SEO, sitemap, PWA,
  authentication, authorization, Premium, region/legal и importer.

### Task 1: Preparation gate and reproducible baseline

**Files:**

- Read: `AGENTS.md`
- Read: `docs/requirements/index.md`
- Read: `docs/CODE_STANDARDS.md`
- Read: `docs/architecture.md`
- Read: `docs/development.md`
- Read: `docs/requirements/multilingual-requirements.md`
- Read: `docs/security.md`
- Read: `docs/performance.md`
- Read: `docs/caching.md`
- Read: `docs/UI_STANDARDS.md`
- Read: `docs/frontend.md`
- Read: `docs/requirements/production-operations.md`
- Read: `docs/requirements/maintenance-and-upgrades.md`
- Read: `docs/requirements/pwa-and-push.md`
- Read: `docs/requirements/system-wide-integration.md`
- Read: `docs/catalog-search.md`
- Read: `docs/DATA_RELATIONS.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/plans/task-118-catalog-filter-actions-compliance.md`

**Interfaces:**

- Consumes: approved design
  `docs/superpowers/specs/2026-07-27-catalog-filter-actions-design.md`.
- Produces: exact task manifest, verified baseline and cross-feature matrix
  required before RED.

- [ ] **Step 1: Re-read requirement owners in canonical order**

Read every file above completely. Record `completed`, `already_compliant`,
`not_applicable` or `unresolved` in the Task 118 matrix; do not infer
production, PWA or cache state from a local render.

- [ ] **Step 2: Verify branch, protected changes and installed versions**

Run:

```bash
git status --short --branch
php -v
php artisan about --only=environment
node --version
npm --version
npm ls tailwindcss vite @playwright/test --depth=0
composer show laravel/framework
composer show livewire/livewire
```

Expected:

- branch is `main`;
- only the already-protected `composer.lock` and
  `storage/debugbar/.gitignore` are outside Task 118;
- PHP reports `8.5.8`, Laravel `13.22.0`, Tailwind `4.3.2`, Vite `8.1.4`
  and Playwright `1.61.1`;
- no dependency file changes are produced.

- [ ] **Step 3: Reconfirm the root cause against current source**

Run:

```bash
rg -n "lg:grid-cols-\\[18rem|sm:flex-row|data-catalog-filter-submit-label|data-catalog-filter-cancel|resetAll" \
  resources/views/catalog/titles.blade.php \
  resources/views/components/catalog/unified-title-filters.blade.php \
  resources/js/mobile-runtime.js \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/browser/catalog.spec.js
```

Expected: the layout owns an `18rem` sidebar while the action container still
uses viewport-driven `sm:flex-row`; existing apply/cancel/reset hooks occur
once and must remain.

- [ ] **Step 4: Declare the complete implementation manifest**

From the long-lived owner shell, declare:

```bash
printf '%s\0' \
  resources/views/components/catalog/unified-title-filters.blade.php \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/browser/catalog.spec.js \
  docs/UI_STANDARDS.md \
  docs/frontend.md \
  README.md \
  CHANGELOG.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-118-catalog-filter-actions-compliance.md \
  docs/plans/archive/2026-07-27-catalog-filter-actions-evidence.md \
  docs/superpowers/plans/2026-07-27-catalog-filter-actions.md |
  scripts/task-workspace-lease.sh declare-paths "$SEASONVAR_TASK_ID"
```

Expected: `declared_task_id=task-118-catalog-filter-actions`. Re-declaration
invalidates any older index approval and authorizes no unrelated path.

- [ ] **Step 5: Update preparation evidence and re-read this plan**

In `docs/plans/current-task-plan.md`, mark the preparation gate completed and
the TDD workstream `in_progress`. In the Task 118 matrix, record routes,
schema, translations, API, cache, permissions and data as
`already_compliant`, with the exact source evidence above. Re-read this plan
before editing tests or Blade.

### Task 2: RED contracts for the action stack

**Files:**

- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/browser/catalog.spec.js`
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: `tests/browser/catalog.spec.js`

**Interfaces:**

- Consumes: current `unified-title-filters` markup and existing Playwright
  fixtures/`installNetworkGuard`.
- Produces: `data-catalog-filter-actions` geometry contract that the Blade
  implementation must satisfy.

- [ ] **Step 1: Add the failing PHPUnit Blade contract**

Add this method immediately after
`test_advanced_catalog_filters_use_four_compact_explanatory_groups()`:

```php
public function test_catalog_filter_actions_are_a_vertical_full_width_stack(): void
{
    $template = file_get_contents(
        resource_path('views/components/catalog/unified-title-filters.blade.php'),
    );

    $this->assertIsString($template);

    $matched = preg_match(
        '/<div(?=[^>]*data-catalog-filter-actions)[^>]*>(.*?)<\\/div>\\s*<\\/form>/s',
        $template,
        $actions,
    );

    $this->assertSame(1, $matched);
    $this->assertStringContainsString(
        'grid min-w-0 grid-cols-1 gap-2',
        $actions[0],
    );
    $this->assertStringNotContainsString('sm:flex-row', $actions[0]);
    $this->assertSame(3, substr_count($actions[1], 'w-full'));
    $this->assertSame(3, substr_count($actions[1], 'break-words'));
    $this->assertSame(3, substr_count($actions[1], 'focus-visible:ring-4'));
    $this->assertStringContainsString('wire:target="applyFilters"', $actions[1]);
    $this->assertStringContainsString('data-catalog-filter-cancel', $actions[1]);
    $this->assertStringContainsString(
        'wire:click.prevent="resetAll"',
        $actions[1],
    );
}
```

- [ ] **Step 2: Run PHPUnit and prove RED**

Run:

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php \
  --filter=test_catalog_filter_actions_are_a_vertical_full_width_stack
```

Expected: FAIL because `data-catalog-filter-actions` is absent and
`$matched` equals `0`. If it passes before the Blade edit, stop and inspect
unrelated changes instead of weakening the assertion.

- [ ] **Step 3: Add the failing Playwright geometry contract**

Add this test after
`catalog keeps URL state, unified filters and responsive geometry`:

```js
test('catalog filter action stack contains a long result label at every viewport', async ({
    page,
    baseURL,
}, testInfo) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    if (testInfo.project.name === 'Narrow Phone Chromium') {
        await page.setViewportSize({ width: 320, height: 568 });
    }

    await page.goto('/titles');

    const filters = page.locator('#catalog-filters');

    if (page.viewportSize().width < 1024) {
        await page.locator('[data-catalog-mobile-filter-trigger]').click();
        await expect(filters).toHaveAttribute('open', '');
    } else {
        await expect(filters.locator('form')).toBeVisible();
    }

    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();

    const actions = filters.locator('[data-catalog-filter-actions]');
    const submit = actions.locator('button[type="submit"]');
    const cancel = actions.locator('[data-catalog-filter-cancel]');
    const reset = actions.getByRole('link', { name: 'Сбросить фильтры' });

    await actions.scrollIntoViewIfNeeded();
    await actions.locator('[data-catalog-filter-submit-label]').evaluate((label) => {
        label.textContent = 'Показать 33 005 сериалов';
    });

    const geometry = await actions.evaluate((container) => {
        const containerBox = container.getBoundingClientRect();
        const controls = [...container.querySelectorAll(':scope > button, :scope > a')]
            .map((control) => {
                const box = control.getBoundingClientRect();

                return {
                    bottom: box.bottom,
                    height: box.height,
                    left: box.left,
                    right: box.right,
                    top: box.top,
                    width: box.width,
                };
            });

        return {
            container: {
                left: containerBox.left,
                right: containerBox.right,
                width: containerBox.width,
            },
            controls,
            pageOverflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });

    expect(geometry.controls).toHaveLength(3);

    for (const control of geometry.controls) {
        expect(control.height).toBeGreaterThanOrEqual(44);
        expect(control.left).toBeGreaterThanOrEqual(geometry.container.left - 1);
        expect(control.right).toBeLessThanOrEqual(geometry.container.right + 1);
        expect(control.width).toBeGreaterThanOrEqual(geometry.container.width - 1);
    }

    for (let index = 1; index < geometry.controls.length; index += 1) {
        expect(geometry.controls[index].top)
            .toBeGreaterThanOrEqual(geometry.controls[index - 1].bottom);
    }

    expect(geometry.pageOverflow).toBeLessThanOrEqual(1);

    for (const control of [submit, cancel, reset]) {
        await control.focus();
        await expect(control).toBeFocused();
    }

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});
```

- [ ] **Step 4: Run desktop Playwright and prove RED**

Run:

```bash
npx playwright test tests/browser/catalog.spec.js \
  --grep "catalog filter action stack contains a long result label" \
  --project="Desktop Chromium"
```

Expected: FAIL because `data-catalog-filter-actions` is absent. After adding
only that marker, the old `sm:flex-row` must still fail the full-width and
vertical geometry assertions in the `18rem` desktop sidebar.

### Task 3: Minimal Blade implementation and focused GREEN

**Files:**

- Modify: `resources/views/components/catalog/unified-title-filters.blade.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: `tests/browser/catalog.spec.js`

**Interfaces:**

- Consumes: RED contract from Task 2.
- Produces: one presentation-only `data-catalog-filter-actions` stack while
  preserving all existing Livewire/GET/JavaScript hooks.

- [ ] **Step 1: Replace only the action container**

Replace the final action `<div>` and its three children with:

```blade
<div data-catalog-filter-actions class="grid min-w-0 grid-cols-1 gap-2 border-t border-slate-200 pt-4">
    <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 w-full min-w-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 disabled:cursor-wait disabled:opacity-60">
        <x-ui.icon name="fa-solid fa-filter shrink-0" />
        <span data-catalog-filter-submit-label class="min-w-0 break-words text-center leading-5">{{ __('catalog.catalog.exact_filters.show_results') }}</span>
    </button>
    <button type="button" data-catalog-filter-cancel class="inline-flex min-h-11 w-full min-w-0 items-center justify-center gap-2 rounded-control border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
        <x-ui.icon name="fa-solid fa-xmark shrink-0" />
        <span class="min-w-0 break-words text-center leading-5">{{ __('catalog.catalog.exact_filters.cancel') }}</span>
    </button>
    <a href="{{ route('titles.index') }}" rel="nofollow" wire:click.prevent="resetAll" class="inline-flex min-h-11 w-full min-w-0 items-center justify-center gap-2 rounded-control px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
        <x-ui.icon name="fa-solid fa-rotate-left shrink-0" />
        <span class="min-w-0 break-words text-center leading-5">{{ __('catalog.catalog.exact_filters.reset') }}</span>
    </a>
</div>
```

Do not edit the form fields, filter groups, translations or
`mobile-runtime.js`.

- [ ] **Step 2: Run focused PHPUnit GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php \
  --filter=test_catalog_filter_actions_are_a_vertical_full_width_stack
```

Expected: PASS, one test and all assertions green.

- [ ] **Step 3: Run neighboring catalog feature coverage**

Run:

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php
php artisan test tests/Feature/CatalogTitlesUxRedesignTest.php
php artisan test tests/Feature/CatalogPageTest.php
```

Expected: all tests pass; no route, Livewire state, filter semantics or GET
fallback regression.

- [ ] **Step 4: Build Tailwind/Vite assets**

Run:

```bash
npm run build
```

Expected: Vite exits `0`; generated assets remain ignored, and the build
contains the static classes `grid-cols-1`, `w-full`, `min-w-0`,
`break-words` and `focus-visible:ring-4`.

- [ ] **Step 5: Run focused browser matrix GREEN**

Run:

```bash
PLAYWRIGHT_DEVICE_MATRIX=extended npx playwright test \
  tests/browser/catalog.spec.js \
  --grep "catalog filter action stack contains a long result label" \
  --project="Desktop Chromium" \
  --project="Mobile Chromium" \
  --project="Narrow Phone Chromium"
```

Expected: `3 passed`; desktop `18rem`, `390×844` and narrow `320×568`
contain all three controls, each control is at least 44 px, controls never
share a row, page overflow is at most 1 px and browser error arrays are
empty.

- [ ] **Step 6: Inspect the three screenshots/traces only if the run fails**

Use Playwright failure artifacts from ignored `output/playwright/**`.
Confirm that any failure is Task 118 geometry or a separately identified
pre-existing signal; do not weaken containment assertions to hide a real
overflow.

### Task 4: Canonical documentation and visitor-visible history

**Files:**

- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/plans/task-118-catalog-filter-actions-compliance.md`

**Interfaces:**

- Consumes: verified GREEN layout from Task 3.
- Produces: permanent UX contract, visitor history and pre-commit-compliant
  product documentation.

- [ ] **Step 1: Extend the canonical UI contract**

Under `## Каталог /titles Task 98` in `docs/UI_STANDARDS.md`, add:

```markdown
- Панель apply/cancel/reset внутри единой формы всегда остаётся одной
  вертикальной полноширинной колонкой: ширина `18rem` sidebar не зависит от
  viewport breakpoint. Динамический result count и длинные RU/EN labels
  переносятся внутри control; все три действия имеют минимум 44 px,
  `focus-visible` и не создают page-level overflow.
```

- [ ] **Step 2: Document the unchanged frontend lifecycle**

Append to `## Lifecycle каталога /titles Task 98` in `docs/frontend.md`:

```markdown
Apply/cancel/reset находятся в одном `data-catalog-filter-actions` stack.
Каждый control полноширинный и переносит длинный exact-result label; stack не
переключается в горизонтальную строку внутри `18rem` desktop sidebar.
`mobile-runtime.js` продолжает только обновлять
`data-catalog-filter-submit-label` и обрабатывать существующий cancel hook:
нового draft, event или client-side filter state нет.
```

- [ ] **Step 3: Update visitor history**

Add the first relevant bullet under `### 27 июля 2026 года` in the final
`## История обновлений для посетителей` section of `README.md`:

```markdown
- Кнопки применения, отмены и сброса фильтров каталога теперь располагаются
  вертикально и полностью помещаются в панели на телефоне, планшете и
  компьютере; длинное количество найденных сериалов аккуратно переносится.
```

Do not edit the managed `project-docs` block or move the visitor-history
section away from the end of `README.md`.

- [ ] **Step 4: Add the dated technical changelog entry**

Add a separate first bullet under `## 2026-07-27` in `CHANGELOG.md`:

```markdown
- Панель действий единой формы `/titles` переведена с viewport-зависимого
  `sm:flex-row` на вертикальный полноширинный
  `data-catalog-filter-actions`: динамическая подпись результата переносится
  внутри primary CTA, apply/cancel/reset сохраняют прежние Livewire/GET/JS
  contracts, `44 px` touch targets и явный `focus-visible`. Добавлены
  PHPUnit- и Playwright-регрессии для `18rem`, `390×844` и `320×568` без
  горизонтального переполнения.
```

- [ ] **Step 5: Update task status without overstating delivery**

In `docs/plans/current-task-plan.md`, mark Blade/focused verification
`completed`, full gate and delivery `in_progress`. In the Task 118 compliance
matrix, change geometry/accessibility/docs rows to `completed` only when the
corresponding command has passed; leave commit/push `unresolved`.

### Task 5: Full verification and implementation commit

**Files:**

- Review: every path in the task manifest
- Stage: Blade, two test files, canonical docs, README, CHANGELOG, current
  plan, compliance and this implementation plan

**Interfaces:**

- Consumes: Tasks 1–4.
- Produces: reviewed implementation commit on `main`.

- [ ] **Step 1: Run static legacy/dead-contract search**

Run:

```bash
rg -n "sm:flex-row|data-catalog-filter-actions|data-catalog-filter-cancel|data-catalog-filter-submit-label|wire:click.prevent=\"resetAll\"" \
  resources/views/components/catalog/unified-title-filters.blade.php \
  resources/js/mobile-runtime.js \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/browser/catalog.spec.js \
  docs/UI_STANDARDS.md \
  docs/frontend.md
```

Expected: no `sm:flex-row` remains in the action stack; exactly one
production apply/cancel/reset hook remains; test/docs references match the
new marker.

- [ ] **Step 2: Run formatting and documentation gates**

No PHP production file changes are planned, so Pint should report no
application rewrite. Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan project:docs-refresh --check
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all commands exit `0` and do not stage files.

- [ ] **Step 3: Run full frontend and focused backend gates**

Run:

```bash
bash scripts/ci-check.sh frontend
php artisan test tests/Feature/CatalogVisualSystemTest.php
php artisan test tests/Feature/CatalogTitlesUxRedesignTest.php
php artisan test tests/Feature/CatalogPageTest.php
```

Expected: all commands exit `0`.

- [ ] **Step 4: Re-read applicable requirements and the user request**

Re-read the UI/mobile/catalog/maintenance/production owners and approved
design. Confirm the result is the chosen option 1: three vertical full-width
controls, not a wrapped or two-column variant.

- [ ] **Step 5: Review exact diff and stage only the manifest**

Run:

```bash
git status --short --branch
git diff --check
git diff -- \
  resources/views/components/catalog/unified-title-filters.blade.php \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/browser/catalog.spec.js \
  docs/UI_STANDARDS.md \
  docs/frontend.md \
  README.md \
  CHANGELOG.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-118-catalog-filter-actions-compliance.md \
  docs/superpowers/plans/2026-07-27-catalog-filter-actions.md
```

Expected: branch `main`; protected `composer.lock` and debugbar mode remain
unstaged and byte-for-byte untouched; no unrelated hunk is present.

Stage the exact list above, then run:

```bash
printf '%s\0' \
  resources/views/components/catalog/unified-title-filters.blade.php \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/browser/catalog.spec.js \
  docs/UI_STANDARDS.md \
  docs/frontend.md \
  README.md \
  CHANGELOG.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-118-catalog-filter-actions-compliance.md \
  docs/superpowers/plans/2026-07-27-catalog-filter-actions.md |
  scripts/task-workspace-lease.sh declare-paths "$SEASONVAR_TASK_ID"
git add -- \
  resources/views/components/catalog/unified-title-filters.blade.php \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/browser/catalog.spec.js \
  docs/UI_STANDARDS.md \
  docs/frontend.md \
  README.md \
  CHANGELOG.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-118-catalog-filter-actions-compliance.md \
  docs/superpowers/plans/2026-07-27-catalog-filter-actions.md
scripts/update-changelog-for-staged-code.sh
scripts/task-workspace-lease.sh verify-paths "$SEASONVAR_TASK_ID"
git diff --cached --name-status
git diff --cached --check
git diff --cached
scripts/task-workspace-lease.sh approve-index "$SEASONVAR_TASK_ID"
scripts/task-workspace-lease.sh verify-index "$SEASONVAR_TASK_ID"
```

Expected: manifest and index paths are identical, full staged diff is
reviewed, and approval remains current.

- [ ] **Step 6: Commit the verified implementation**

Run:

```bash
git commit -m "fix: исправить действия фильтров каталога"
```

Expected: commit succeeds on `main`; record its exact hash as
`implementation_commit` for Task 6.

### Task 6: Completion evidence, delivery and recovery

**Files:**

- Create: `docs/plans/archive/2026-07-27-catalog-filter-actions-evidence.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/plans/task-118-catalog-filter-actions-compliance.md`

**Interfaces:**

- Consumes: exact implementation commit and command outputs from Task 5.
- Produces: final compliance evidence, documentation commit, push result and
  released workspace lease.

- [ ] **Step 1: Run the final clean-snapshot pre-push profile**

Because the two protected pre-existing changes prevent a clean-tree
pre-push, preserve and temporarily reverse only their exact binary diff:

```bash
task118_temp_dir="$(mktemp -d)"
task118_patch="$task118_temp_dir/protected.patch"
git diff --binary -- composer.lock storage/debugbar/.gitignore > "$task118_patch"
task118_patch_hash="$(sha256sum "$task118_patch" | cut -d' ' -f1)"
git apply --reverse --check "$task118_patch"
git apply --reverse "$task118_patch"
git status --short --branch
bash scripts/ci-check.sh pre-push
composer git:doctor -- --remote
git push origin main
```

Expected: tree is clean, pre-push exits `0` and the implementation commit is
offered to `origin/main`. Record successful delivery or the exact
authentication/network failure. If reverse-check fails, do not modify the
protected files; mark delivery blocked and retain the lease.

- [ ] **Step 2: Restore protected changes immediately**

Whether the gate passes or fails, restore before any user handoff:

```bash
git apply --check "$task118_patch"
git apply "$task118_patch"
test "$(git diff --binary -- composer.lock storage/debugbar/.gitignore | sha256sum | cut -d' ' -f1)" = "$task118_patch_hash"
rm -- "$task118_patch"
rmdir -- "$task118_temp_dir"
unset task118_temp_dir task118_patch task118_patch_hash
```

Expected: the exact protected binary diff hash matches its pre-gate value.
Never stage either protected path.

- [ ] **Step 3: Write immutable evidence**

Create
`docs/plans/archive/2026-07-27-catalog-filter-actions-evidence.md` with the
following structure, replacing the descriptive evidence instructions with
the literal values printed by the completed commands:

```markdown
# Task 118 — evidence панели действий фильтров каталога

Дата: 27.07.2026.

## Результат

- Три действия единой формы `/titles` расположены вертикально и занимают
  полную ширину панели.
- Динамическая подпись результата переносится внутри primary CTA.
- Apply/cancel/reset, Livewire/GET/JS hooks, routes, translations и data
  contracts сохранены.

## Verification

- PHPUnit RED: marker отсутствовал и новый contract падал.
- Focused PHPUnit GREEN: записать команды, число тестов и утверждений из
  успешного вывода.
- Vite/frontend gate: записать успешные команды и exit status.
- Playwright desktop/mobile/narrow: записать число passed tests и три
  проверенных viewport.
- Pre-push: записать успешный результат либо точную независимую ошибку.

## Compatibility

- migrations/data/API/cache/permissions/translations: `not_applicable` или
  `already_compliant`;
- rollback: revert implementation/docs commits и rebuild assets без data
  restore/cache flush.

## Delivery

- implementation commit: записать hash из `git rev-parse HEAD` после Task 5;
- implementation push attempt: `completed` либо честный `unresolved` с
  точной внешней причиной.
```

Before staging, verify that evidence contains literal command results and
commit hashes rather than these instructions.

- [ ] **Step 4: Complete current plan and compliance matrix**

Mark Task 118 `completed` only for requirements with evidence. Record the
implementation hash, test/build/browser results and link the archive.
Commit/push stays `unresolved` until the actual operation succeeds.

- [ ] **Step 5: Commit completion evidence**

Re-declare the exact three documentation paths, stage them, review the full
staged diff, run `verify-paths`, `approve-index`, `verify-index`, then:

```bash
printf '%s\0' \
  docs/plans/archive/2026-07-27-catalog-filter-actions-evidence.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-118-catalog-filter-actions-compliance.md |
  scripts/task-workspace-lease.sh declare-paths "$SEASONVAR_TASK_ID"
git add -- \
  docs/plans/archive/2026-07-27-catalog-filter-actions-evidence.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-118-catalog-filter-actions-compliance.md
scripts/task-workspace-lease.sh verify-paths "$SEASONVAR_TASK_ID"
git diff --cached --name-status
git diff --cached --check
git diff --cached
scripts/task-workspace-lease.sh approve-index "$SEASONVAR_TASK_ID"
scripts/task-workspace-lease.sh verify-index "$SEASONVAR_TASK_ID"
git commit -m "docs: завершить evidence панели фильтров"
```

Expected: docs-only commit succeeds on `main`.

- [ ] **Step 6: Push while preserving protected changes**

Repeat the exact reversible patch procedure from Steps 1–2, but run:

```bash
composer git:doctor -- --remote
git push origin main
```

Always restore and hash-verify the protected changes before continuing.
Record successful push, or the exact authentication/network/pre-push failure
as `unresolved`; never claim delivery without Git confirmation.

- [ ] **Step 7: Release lease and final handoff**

After the push attempt and protected-change restoration:

```bash
scripts/task-workspace-lease.sh release "$SEASONVAR_TASK_ID"
unset SEASONVAR_TASK_LEASE_TOKEN SEASONVAR_TASK_ID
git status --short --branch
```

Expected: Task 118 paths are clean/committed, only the two original protected
changes remain, and the final report includes outcome, validation,
compatibility, exact commits and push status.
