# Remove Catalog Relations Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the standalone «Связи каталога» panel from public title pages while preserving taxonomy metadata everywhere it still has context, and prevent the panel from returning.

**Architecture:** Keep taxonomy loading and `CatalogShowViewModel` collections intact for the title hero, reference panel, SEO, and recommendations. Remove only the duplicate Blade panel, its now-unused page payload/property, and its orphaned translation strings; protect the presentation contract with a focused feature test and an explicit UI standard.

**Tech Stack:** PHP 8.5, Laravel 13.19, Blade, PHPUnit 12.5, Tailwind CSS 4.3, Laravel Pint 1.29.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Do not stage, commit, format, or overwrite unrelated changes. If the shared worktree is dirty when execution starts, wait for its owner or stop and report the conflict.
- Preserve genres, countries, actors, directors, age ratings, translations, statuses, networks, studios, and tags in their existing contextual placements.
- Preserve `taxonomiesByType`, `taxonomyRows`, individual taxonomy collections, SEO input, recommendations, Eloquent relations, importer behavior, and API behavior.
- Do not add dependencies, migrations, placeholder content, or new public copy.
- Visible interface text remains Russian; remove the now-orphaned Russian and English translation entries together.
- Run Pint after PHP changes, focused tests before broad tests, and `npm run build` after the Blade change.

---

### Task 1: Remove the duplicate title-page panel with a regression test

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php:72-96`
- Modify: `resources/views/catalog/show.blade.php:321-342`
- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php:73-89`
- Modify: `app/View/ViewModels/CatalogShowViewModel.php:38-42,195`
- Modify: `lang/ru/catalog.php:87-88`
- Modify: `lang/en/catalog.php:87-88`

**Interfaces:**
- Consumes: `CatalogTitle::genres(): BelongsToMany`, the named route `titles.show`, `CatalogTitlePageBuilder::data(CatalogShowRequest, CatalogTitle): array`, and the existing `data-title-reference` panel marker.
- Produces: a public title page that still renders taxonomy metadata in contextual sections but has no standalone relations panel; a smaller Blade data array without `taxonomyGroups`, `taxonomyLabels`, or `taxonomyIcons` keys.

- [x] **Step 1: Write the failing feature test**

Add this method after `test_title_page_places_player_before_secondary_reference_metadata()` in `tests/Feature/CatalogVisualSystemTest.php`:

```php
public function test_title_page_does_not_render_a_standalone_catalog_relations_panel(): void
{
    $title = CatalogTitle::factory()->create();
    $genre = Genre::query()->create([
        'name' => 'Детектив',
        'slug' => 'detektiv',
    ]);
    $title->genres()->attach($genre);

    $response = $this->get(route('titles.show', $title));

    $response
        ->assertOk()
        ->assertSee('data-title-reference', false)
        ->assertSeeText('Детектив')
        ->assertDontSeeText('Связи каталога')
        ->assertDontSeeText('Связи не указаны.')
        ->assertDontSee('fa-diagram-project', false);
}
```

- [x] **Step 2: Run the focused test and verify the regression is exposed**

Run:

```bash
php artisan test --filter=test_title_page_does_not_render_a_standalone_catalog_relations_panel
```

Expected: FAIL because the current response contains `Связи каталога` and `fa-diagram-project`. The earlier assertions confirm that the title page and its contextual genre metadata render correctly before removal.

- [x] **Step 3: Remove the standalone Blade panel**

Delete the complete `<x-ui.panel :title="__('catalog.title.relations')" ...>` block from `resources/views/catalog/show.blade.php`, leaving the end of the main content column as:

```blade
            @if (! empty($seo['faq']))
                <x-ui.panel :title="__('catalog.title.questions')" icon="fa-solid fa-circle-question" :pad="false">
                    <div class="divide-y divide-slate-200">
                        @foreach ($seo['faq'] as $faqItem)
                            <details class="group px-4 py-3">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-bold text-slate-700">
                                    <span>{{ $faqItem['question'] }}</span>
                                    <i class="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $faqItem['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </x-ui.panel>
            @endif
        </div>
    </section>
@endsection
```

- [x] **Step 4: Remove only data that became unused with the panel**

In `app/Services/Catalog/CatalogTitlePageBuilder.php`, remove these three entries from the array returned by `data()`:

```php
'taxonomyGroups' => $showView->taxonomyGroups,
'taxonomyLabels' => $showView->taxonomyLabels,
'taxonomyIcons' => $showView->taxonomyIcons,
```

Keep `taxonomiesByType`, all individual taxonomy collections, `taxonomyRows`, `topTaxonomies`, `showView`, and the SEO call unchanged.

In `app/View/ViewModels/CatalogShowViewModel.php`, remove the panel-only property:

```php
/**
 * @var Collection<string, Collection<int, mixed>>
 */
public Collection $taxonomyGroups;
```

Also remove only this constructor assignment:

```php
$this->taxonomyGroups = $this->taxonomiesByType;
```

Keep `taxonomyLabels`, `taxonomyIcons`, `taxonomyLabel()`, and `taxonomyIcon()` because `taxonomyRows` still uses them in the contextual «О сериале» panel.

- [x] **Step 5: Remove the orphaned translations**

Delete these entries from the `title` array in both `lang/ru/catalog.php` and `lang/en/catalog.php`:

```php
'relations' => 'Связи каталога',
'relations_missing' => 'Связи не указаны.',
```

```php
'relations' => 'Catalog relations',
'relations_missing' => 'No relations are listed.',
```

- [x] **Step 6: Confirm the removed presentation contract has no remaining references**

Run:

```bash
rg -n "catalog\.title\.relations|relations_missing|\$taxonomyGroups|fa-diagram-project" resources/views/catalog/show.blade.php app/Services/Catalog/CatalogTitlePageBuilder.php lang/ru/catalog.php lang/en/catalog.php
rg -n "'taxonomyGroups' =>|'taxonomyLabels' =>|'taxonomyIcons' =>" app/Services/Catalog/CatalogTitlePageBuilder.php
```

Expected: both commands exit with status 1 and print no matches. References to `taxonomyLabels`, `taxonomyIcons`, and `taxonomyGroups()` outside these scoped paths are intentionally preserved.

- [x] **Step 7: Format PHP and run the focused visual-system test class**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogVisualSystemTest
```

Expected: Pint exits successfully and every `CatalogVisualSystemTest` test passes, including `test_title_page_does_not_render_a_standalone_catalog_relations_panel`.

- [x] **Step 8: Commit the behavior change**

First verify that only task files are dirty:

```bash
git status --short --branch
```

Then commit:

```bash
git add tests/Feature/CatalogVisualSystemTest.php resources/views/catalog/show.blade.php app/Services/Catalog/CatalogTitlePageBuilder.php app/View/ViewModels/CatalogShowViewModel.php lang/ru/catalog.php lang/en/catalog.php
git commit -m "fix: remove catalog relations panel"
```

Expected: commit succeeds on `main`. If any unrelated file is dirty, do not stage or commit; stop and report the conflicting paths.

---

### Task 2: Document the permanent UI rule and verify the complete change

**Files:**
- Modify: `docs/UI_STANDARDS.md:74-79`
- Reference: `docs/superpowers/specs/2026-07-13-remove-catalog-relations-panel-design.md`

**Interfaces:**
- Consumes: the public title-page presentation contract implemented in Task 1.
- Produces: a project-level rule that forbids reintroducing a standalone relations summary while allowing contextual taxonomy metadata.

- [x] **Step 1: Add the permanent UI rule**

Add this bullet after the existing title-page quick-access rules in `docs/UI_STANDARDS.md`:

```markdown
- На странице сериала запрещена отдельная панель «Связи каталога» или её переименованный аналог со сводным повтором таксономий. Жанры, страны, актёры и другие связи выводятся только в существующих контекстных блоках страницы.
```

- [x] **Step 2: Verify the documentation states both the prohibition and preservation boundary**

Run:

```bash
rg -n "запрещена отдельная панель «Связи каталога»|Жанры, страны, актёры" docs/UI_STANDARDS.md
```

Expected: one matching rule containing both phrases.

- [x] **Step 3: Run the complete verification suite**

Run in this order:

```bash
php artisan test --filter=test_title_page_does_not_render_a_standalone_catalog_relations_panel
php artisan test
npm run build
git diff --check
```

Expected: the focused test passes; the full Laravel suite passes with zero failures; Vite completes a production build without errors; `git diff --check` exits with status 0 and prints nothing.

- [x] **Step 4: Review the final scope**

Run:

```bash
git diff --stat
git diff -- resources/views/catalog/show.blade.php app/Services/Catalog/CatalogTitlePageBuilder.php app/View/ViewModels/CatalogShowViewModel.php lang/ru/catalog.php lang/en/catalog.php tests/Feature/CatalogVisualSystemTest.php docs/UI_STANDARDS.md
git status --short --branch
```

Expected: the diff contains only the panel removal, panel-only payload/property cleanup, orphaned translation cleanup, regression test, and UI rule. The branch is `main`; no unrelated file is staged or modified by this task.

- [x] **Step 5: Commit the documentation rule**

```bash
git add docs/UI_STANDARDS.md
git commit -m "docs: forbid catalog relations panel"
```

Expected: commit succeeds on `main`, and `git status --short --branch` is clean apart from any paths explicitly identified as pre-existing and owned by another active task. Do not commit those paths.
