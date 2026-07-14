# Portal Pagination and Alphabet Groups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every Livewire paginator progressively enhanced and scroll to its own refreshed result list, while rendering Cyrillic and `A`–`Z` as separate alphabet groups everywhere alphabet filtering already exists.

**Architecture:** A small query-free `CatalogAlphabet` support class owns script order and grouping. Catalog and directory presentation layers consume its plain arrays. The shared Livewire pagination view renders real links enhanced by `wire:click.prevent`; each caller passes an explicit result selector, and the existing smooth-scroll helper runs after the corresponding Livewire morph.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4, Blade, Tailwind CSS 4.3, Vite 8, PHPUnit 12.5, Playwright 1.61.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Keep visible interface text in Russian and add reusable strings to `lang/{locale}/catalog.php`.
- Do not add production dependencies, change database schema, change route names, or change API pagination shape.
- Keep database queries out of Blade and do not add `@php`, PHP tags, or inline asset logic to Blade.
- Preserve `letter=latin` as the legacy whole-Latin filter while making individual `A`–`Z` controls the normal UI.
- Preserve Russian pagination copy, light-only styling, stable keys, and controls at least 44 px high.
- Run Pint after PHP edits, focused tests before the full suite, `npm run build` after JS/Blade/Tailwind edits, and desktop/mobile Playwright QA.

---

### Task 1: Centralize catalog alphabet ordering and grouping

**Files:**
- Create: `app/Support/CatalogAlphabet.php`
- Create: `tests/Unit/CatalogAlphabetTest.php`

**Interfaces:**
- Consumes: normalized first-character strings from catalog presentation code.
- Produces: `CatalogAlphabet::titleGroups(): array{symbols: list<string>, cyrillic: list<string>, latin: list<string>}` and `CatalogAlphabet::availableGroups(iterable $letters): array{symbols: list<string>, cyrillic: list<string>, latin: list<string>}`.

- [ ] **Step 1: Write the failing unit tests**

```php
<?php

namespace Tests\Unit;

use App\Support\CatalogAlphabet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogAlphabetTest extends TestCase
{
    #[Test]
    public function it_builds_the_full_title_filter_groups(): void
    {
        $groups = CatalogAlphabet::titleGroups();

        $this->assertSame(['#'], $groups['symbols']);
        $this->assertSame(mb_str_split('АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'), $groups['cyrillic']);
        $this->assertSame(range('A', 'Z'), $groups['latin']);
    }

    #[Test]
    public function it_groups_only_available_letters_in_canonical_script_order(): void
    {
        $groups = CatalogAlphabet::availableGroups(['я', 'B', '#', 'Ё', 'A', 'б', 'A']);

        $this->assertSame(['#'], $groups['symbols']);
        $this->assertSame(['Б', 'Ё', 'Я'], $groups['cyrillic']);
        $this->assertSame(['A', 'B'], $groups['latin']);
    }
}
```

- [ ] **Step 2: Run the unit tests and confirm the red state**

Run: `php artisan test --filter=CatalogAlphabetTest`

Expected: FAIL because `App\Support\CatalogAlphabet` does not exist.

- [ ] **Step 3: Implement the query-free alphabet support class**

```php
<?php

namespace App\Support;

final class CatalogAlphabet
{
    /** @var list<string> */
    private const TITLE_CYRILLIC = ['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я'];

    /** @var list<string> */
    private const CYRILLIC = ['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я'];

    /** @var list<string> */
    private const LATIN = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    /** @return array{symbols: list<string>, cyrillic: list<string>, latin: list<string>} */
    public static function titleGroups(): array
    {
        return [
            'symbols' => ['#'],
            'cyrillic' => self::TITLE_CYRILLIC,
            'latin' => self::LATIN,
        ];
    }

    /**
     * @param  iterable<mixed>  $letters
     * @return array{symbols: list<string>, cyrillic: list<string>, latin: list<string>}
     */
    public static function availableGroups(iterable $letters): array
    {
        $available = collect($letters)
            ->filter(fn (mixed $letter): bool => is_string($letter) && $letter !== '')
            ->map(fn (string $letter): string => mb_strtoupper($letter))
            ->unique()
            ->values()
            ->all();

        return [
            'symbols' => in_array('#', $available, true) ? ['#'] : [],
            'cyrillic' => array_values(array_intersect(self::CYRILLIC, $available)),
            'latin' => array_values(array_intersect(self::LATIN, $available)),
        ];
    }
}
```

- [ ] **Step 4: Run the focused unit test and formatter**

Run: `php artisan test --filter=CatalogAlphabetTest && ./vendor/bin/pint --dirty --format agent`

Expected: 2 tests pass; Pint exits 0.

- [ ] **Step 5: Commit the independently tested support boundary**

```bash
git status --short --branch
git add app/Support/CatalogAlphabet.php tests/Unit/CatalogAlphabetTest.php
git commit -m "feat: group catalog alphabets by script"
```

### Task 2: Render separate Cyrillic and Latin controls in catalog and directories

**Files:**
- Create: `resources/views/components/catalog/alphabet-filter.blade.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/Services/Catalog/CatalogDirectoryPageBuilder.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/livewire/catalog-directory-browser.blade.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`
- Modify: `tests/Unit/CatalogTitlesViewModelTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Consumes: `CatalogAlphabet::titleGroups()` and `CatalogAlphabet::availableGroups()` from Task 1.
- Produces: `CatalogTitlesViewModel::$alphabetGroups`, directory render data key `letterGroups`, and `data-catalog-alphabet-group` / `data-directory-alphabet-group` DOM contracts.

- [ ] **Step 1: Add failing ViewModel and rendered-page assertions**

Add to `CatalogTitlesViewModelTest`:

```php
public function test_alphabet_groups_expose_individual_latin_letters_and_legacy_copy(): void
{
    $viewModel = new CatalogTitlesViewModel(
        search: '',
        sort: 'updated',
        year: null,
        requestedYear: '',
        invalidYear: false,
        activeTaxonomies: collect(),
        selectedTaxonomies: collect(),
        activeFilterSlugs: [],
        invalidFilterSlugs: [],
        titleContext: null,
        catalogQueryState: ['letter' => 'latin'],
    );

    $this->assertSame(range('A', 'Z'), $viewModel->alphabetGroups['latin']);
    $this->assertSame('Латиница A–Z', $viewModel->advancedFilterChips()[0]['value']);
    $this->assertTrue($viewModel->isActiveLetter('latin'));
}
```

Add to `CatalogPageTest`:

```php
public function test_catalog_and_people_directories_render_separate_script_groups(): void
{
    $title = CatalogTitle::factory()->create();
    $actors = collect([
        ['name' => 'Борис Актёр', 'slug' => 'boris-akter'],
        ['name' => 'Ёлка Актриса', 'slug' => 'elka-aktrisa'],
        ['name' => 'Alice Actor', 'slug' => 'alice-actor'],
        ['name' => 'Zed Actor', 'slug' => 'zed-actor'],
        ['name' => '123 Actor', 'slug' => '123-actor'],
    ])->map(fn (array $attributes) => Actor::query()->create($attributes));
    $title->actors()->attach($actors->pluck('id'));

    $catalog = $this->get(route('titles.index'))->assertOk()->getContent();
    $actorsPage = $this->get(route('actors.index'))->assertOk()->getContent();

    $this->assertStringContainsString('data-catalog-alphabet-group="cyrillic"', $catalog);
    $this->assertStringContainsString('data-catalog-alphabet-group="latin"', $catalog);
    $this->assertSame(2, substr_count($catalog, 'data-alphabet-letter="Z"'));
    $this->assertMatchesRegularExpression('/data-directory-alphabet-group="cyrillic".*Б.*Ё/s', $actorsPage);
    $this->assertMatchesRegularExpression('/data-directory-alphabet-group="latin".*A.*Z/s', $actorsPage);
    $this->assertStringContainsString('data-directory-alphabet-symbols', $actorsPage);
}
```

- [ ] **Step 2: Run both tests and confirm they fail for missing grouped state/markup**

Run: `php artisan test --filter='CatalogTitlesViewModelTest|test_catalog_and_people_directories_render_separate_script_groups'`

Expected: FAIL because `alphabetGroups` and grouped DOM attributes are absent.

- [ ] **Step 3: Prepare alphabet state outside Blade**

In `CatalogTitlesViewModel` replace `$alphabet` with:

```php
use App\Support\CatalogAlphabet;

/** @var array{symbols: list<string>, cyrillic: list<string>, latin: list<string>} */
public array $alphabetGroups;
```

Initialize it in the constructor:

```php
$this->alphabetGroups = CatalogAlphabet::titleGroups();
```

Make an empty value represent the active “Все” state and present the legacy value in Russian:

```php
public function isActiveLetter(string $letter): bool
{
    if ($letter === '') {
        return $this->activeLetter === null || $this->activeLetter === '';
    }

    return $this->activeLetter === mb_strtoupper($letter);
}
```

Update `alphabetQuery()` so the explicit empty value always removes the filter:

```php
public function alphabetQuery(string $letter): array
{
    $query = $this->sortQuery($this->sort);

    if ($letter === '' || $this->isActiveLetter($letter)) {
        unset($query['letter']);
    } else {
        $query['letter'] = $letter;
    }

    return $query;
}
```

Add the `letter` branch in `advancedFilterValue()`:

```php
'letter' => mb_strtolower((string) $value) === 'latin' ? 'Латиница A–Z' : (string) $value,
```

In `CatalogDirectoryPageBuilder::data()` replace the raw `letters` render key with:

```php
use App\Support\CatalogAlphabet;

'letterGroups' => CatalogAlphabet::availableGroups($letters),
```

Update `CatalogSeries::setLetter()` so the explicit “Все” link clears the filter:

```php
public function setLetter(mixed $letter): void
{
    if ($letter === '') {
        $this->filters->letter = '';
        $this->resetPage();

        return;
    }

    if (! is_string($letter) || preg_match('/^(?:latin|[A-Za-zА-Яа-яЁё]|#)$/u', $letter) !== 1) {
        return;
    }

    $this->filters->letter = mb_strtoupper((string) $this->filters->letter) === mb_strtoupper($letter)
        ? ''
        : $letter;
    $this->resetPage();
}
```

- [ ] **Step 4: Add reusable translations and declarative grouped markup**

Add equivalent keys under `catalog.alphabet` in Russian and English:

```php
'alphabet' => [
    'label' => 'Алфавит',
    'all' => 'Все',
    'symbols' => 'Символы',
    'cyrillic' => 'Кириллица',
    'latin' => 'Латиница',
],
```

```php
'alphabet' => [
    'label' => 'Alphabet',
    'all' => 'All',
    'symbols' => 'Symbols',
    'cyrillic' => 'Cyrillic',
    'latin' => 'Latin',
],
```

Create `resources/views/components/catalog/alphabet-filter.blade.php`:

```blade
@props([
    'filterView',
    'mobile' => false,
])

<nav
    data-catalog-alphabet-groups
    @if (! $mobile) data-catalog-desktop-alphabet @endif
    aria-label="{{ $mobile ? 'Мобильный алфавитный переход по названиям' : 'Алфавитный переход по названиям' }}"
    {{ $attributes }}
>
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-600">{{ __('catalog.catalog.alphabet.label') }}:</span>
        <a
            data-catalog-alphabet-option
            data-alphabet-letter=""
            href="{{ route('titles.index', $filterView->alphabetQuery('')) }}"
            rel="nofollow"
            wire:click.prevent="setLetter('')"
            @class([
                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-3 text-xs font-bold transition',
                'bg-emerald-50 text-emerald-700' => $filterView->isActiveLetter(''),
                'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $mobile && ! $filterView->isActiveLetter(''),
                'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $mobile && ! $filterView->isActiveLetter(''),
            ])
        >{{ __('catalog.catalog.alphabet.all') }}</a>
    </div>

    @foreach (['symbols', 'cyrillic', 'latin'] as $group)
        @if ($filterView->alphabetGroups[$group] !== [])
            <div data-catalog-alphabet-group="{{ $group }}" class="mt-2 grid gap-2 sm:grid-cols-[6.5rem_minmax(0,1fr)] sm:items-start">
                <span class="py-3 text-xs font-bold text-slate-500">{{ __("catalog.catalog.alphabet.{$group}") }}</span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($filterView->alphabetGroups[$group] as $letter)
                        <a
                            data-catalog-alphabet-option
                            data-alphabet-letter="{{ $letter }}"
                            href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}"
                            rel="nofollow"
                            wire:click.prevent="setLetter(@js($letter))"
                            @class([
                                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-2 text-xs font-bold transition',
                                'bg-emerald-50 text-emerald-700' => $filterView->isActiveLetter($letter),
                                'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $mobile && ! $filterView->isActiveLetter($letter),
                                'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $mobile && ! $filterView->isActiveLetter($letter),
                            ])
                        >{{ $letter }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</nav>
```

Replace both duplicated loops in `catalog/titles.blade.php` with:

```blade
<x-catalog.alphabet-filter :filter-view="$filterView" class="mt-4 hidden lg:block" />
```

```blade
<x-catalog.alphabet-filter :filter-view="$filterView" mobile class="mt-3" />
```

In `catalog-directory-browser.blade.php`, replace the mixed `$letters` loop with:

```blade
@if ($letterGroups['symbols'] !== [] || $letterGroups['cyrillic'] !== [] || $letterGroups['latin'] !== [])
    <div data-directory-alphabet-groups class="mt-5" aria-label="{{ __('catalog.directories.alphabet') }}">
        <p class="text-sm font-bold text-slate-700">{{ __('catalog.directories.alphabet') }}</p>
        <div data-directory-alphabet-symbols class="mt-2 flex flex-wrap items-center gap-1.5">
            <button type="button" wire:click="setLetter('')" @class([
                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-3 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                'bg-emerald-700 text-white' => $letter === '',
                'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $letter !== '',
            ])>{{ __('catalog.catalog.alphabet.all') }}</button>
            @foreach ($letterGroups['symbols'] as $availableLetter)
                <button type="button" wire:key="directory-letter-{{ $availableLetter }}" wire:click="setLetter(@js($availableLetter))" @class([
                    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-3 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                    'bg-emerald-700 text-white' => $letter === $availableLetter,
                    'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $letter !== $availableLetter,
                ]) aria-pressed="{{ $letter === $availableLetter ? 'true' : 'false' }}">{{ $availableLetter }}</button>
            @endforeach
        </div>

        @foreach (['cyrillic', 'latin'] as $group)
            @if ($letterGroups[$group] !== [])
                <div data-directory-alphabet-group="{{ $group }}" class="mt-3 grid gap-2 sm:grid-cols-[6.5rem_minmax(0,1fr)] sm:items-start">
                    <span class="py-3 text-xs font-bold text-slate-500">{{ __("catalog.catalog.alphabet.{$group}") }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($letterGroups[$group] as $availableLetter)
                            <button type="button" wire:key="directory-letter-{{ $availableLetter }}" wire:click="setLetter(@js($availableLetter))" @class([
                                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-3 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                                'bg-emerald-700 text-white' => $letter === $availableLetter,
                                'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $letter !== $availableLetter,
                            ]) aria-pressed="{{ $letter === $availableLetter ? 'true' : 'false' }}">{{ $availableLetter }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endif
```

- [ ] **Step 5: Run alphabet tests, Blade architecture tests, Pint, and frontend build**

Run:

```bash
php artisan test --filter='CatalogAlphabetTest|CatalogTitlesViewModelTest|test_catalog_and_people_directories_render_separate_script_groups|BladeTemplateTest'
./vendor/bin/pint --dirty --format agent
npm run build
```

Expected: focused tests pass, Pint exits 0, Vite build exits 0.

- [ ] **Step 6: Commit the alphabet UI**

```bash
git status --short --branch
git add app/Support/CatalogAlphabet.php app/View/ViewModels/CatalogTitlesViewModel.php app/Livewire/CatalogSeries.php app/Services/Catalog/CatalogDirectoryPageBuilder.php resources/views/components/catalog/alphabet-filter.blade.php resources/views/catalog/titles.blade.php resources/views/livewire/catalog-directory-browser.blade.php lang/ru/catalog.php lang/en/catalog.php tests/Unit/CatalogTitlesViewModelTest.php tests/Feature/CatalogPageTest.php
git commit -m "feat: split catalog alphabets by script"
```

### Task 3: Make every Livewire paginator a real link with scoped post-morph scrolling

**Files:**
- Modify: `resources/views/vendor/livewire/tailwind.blade.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/livewire/catalog-directory-browser.blade.php`
- Modify: `resources/views/livewire/viewing-activity.blade.php`
- Modify: `resources/views/livewire/catalog-administration-manager.blade.php`
- Modify: `resources/js/app.js`
- Modify: `tests/Unit/FrontendAssetContractTest.php`
- Modify: `tests/Feature/CatalogRouteFilterCompositionTest.php`

**Interfaces:**
- Consumes: paginator URLs and the optional `scrollTo` view-data selector passed by each `links()` call.
- Produces: anchors with `href`, `wire:click.prevent`, `data-pagination-scroll-to`, loading `inert`, and four explicit result-target attributes.

- [ ] **Step 1: Write failing pagination fallback and scroll-contract tests**

Add to `CatalogRouteFilterCompositionTest`:

```php
public function test_country_route_pagination_has_a_get_fallback_and_preserves_country_state(): void
{
    $turkey = Country::query()->create(['name' => 'Турция', 'slug' => 'turciia']);

    CatalogTitle::factory()->count(30)->create()->each(
        fn (CatalogTitle $title) => $title->countries()->attach($turkey),
    );

    $content = $this->get(route('titles.taxonomy', [
        'type' => 'country',
        'taxonomy' => $turkey->slug,
        'country' => [$turkey->slug],
    ]))->assertOk()->getContent();

    $this->assertMatchesRegularExpression(
        '/<a[^>]+href="[^"]*country(?:%5B0%5D|\[0\])=turciia[^"]*page=2"[^>]+wire:click\.prevent="gotoPage\(2, \'page\'\)"/s',
        html_entity_decode($content),
    );
}
```

Replace the pagination method in `FrontendAssetContractTest` with assertions for the generic contract:

```php
public function test_livewire_pagination_uses_scoped_post_morph_scroll_targets(): void
{
    $app = File::get(resource_path('js/app.js'));
    $pagination = File::get(resource_path('views/vendor/livewire/tailwind.blade.php'));
    $views = collect([
        resource_path('views/catalog/titles.blade.php'),
        resource_path('views/livewire/catalog-directory-browser.blade.php'),
        resource_path('views/livewire/viewing-activity.blade.php'),
        resource_path('views/livewire/catalog-administration-manager.blade.php'),
    ])->map(fn (string $path): string => File::get($path))->implode("\n");

    $this->assertStringContainsString('data-pagination-scroll-to', $pagination);
    $this->assertStringContainsString('wire:click.prevent="gotoPage', $pagination);
    $this->assertStringContainsString('href="{{ $url }}"', $pagination);
    $this->assertStringContainsString('pendingPaginationScrollTo', $app);
    $this->assertStringContainsString("window.Livewire.hook('morphed'", $app);
    $this->assertStringContainsString('smoothAnchorScroll', $app);
    $this->assertStringContainsString('[data-catalog-results]', $views);
    $this->assertStringContainsString('[data-directory-results]', $views);
    $this->assertStringContainsString('[data-viewing-history-results]', $views);
    $this->assertStringContainsString('[data-admin-catalog-results]', $views);
}
```

- [ ] **Step 2: Run focused tests and confirm the red state**

Run: `php artisan test --filter='test_country_route_pagination_has_a_get_fallback_and_preserves_country_state|test_livewire_pagination_uses_scoped_post_morph_scroll_targets'`

Expected: FAIL because paginator controls are buttons and only the catalog target exists.

- [ ] **Step 3: Convert the shared active paginator controls to enhanced anchors**

Replace `resources/views/vendor/livewire/tailwind.blade.php` with:

```blade
@if ($paginator->hasPages())
    <nav data-catalog-pagination role="navigation" aria-label="Страницы каталога" class="flex flex-col gap-3 rounded-panel border border-slate-200 bg-white p-3 shadow-panel sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm font-semibold text-slate-600">
            Показано
            <span class="font-black text-slate-800">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</span>
            из <span class="font-black text-slate-800">{{ $paginator->total() }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400">
                    <x-ui.icon name="fa-solid fa-chevron-left" />
                    <span>{{ __('pagination.previous') }}</span>
                </span>
            @else
                <a data-pagination-control data-pagination-scroll-to="{{ $scrollTo ?? '' }}" href="{{ $paginator->previousPageUrl() }}" rel="prev" wire:click.prevent="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="inert" wire:loading.class="opacity-60" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                    <x-ui.icon name="fa-solid fa-chevron-left" />
                    <span>{{ __('pagination.previous') }}</span>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="inline-flex min-h-11 min-w-11 items-center justify-center text-sm font-bold text-slate-500">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span wire:key="paginator-{{ $paginator->getPageName() }}-{{ $page }}" aria-current="page" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-emerald-700 px-3 py-2 text-sm font-black text-white">{{ $page }}</span>
                        @else
                            <a data-pagination-control data-pagination-scroll-to="{{ $scrollTo ?? '' }}" data-pagination-page="{{ $page }}" href="{{ $url }}" wire:key="paginator-{{ $paginator->getPageName() }}-{{ $page }}" wire:click.prevent="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="inert" wire:loading.class="opacity-60" aria-label="Страница {{ $page }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a data-pagination-control data-pagination-scroll-to="{{ $scrollTo ?? '' }}" href="{{ $paginator->nextPageUrl() }}" rel="next" wire:click.prevent="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="inert" wire:loading.class="opacity-60" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                    <span>{{ __('pagination.next') }}</span>
                    <x-ui.icon name="fa-solid fa-chevron-right" />
                </a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400">
                    <span>{{ __('pagination.next') }}</span>
                    <x-ui.icon name="fa-solid fa-chevron-right" />
                </span>
            @endif
        </div>
    </nav>
@endif
```

- [ ] **Step 4: Give every paginator an explicit result target**

Apply these exact caller contracts:

```blade
<div data-catalog-results class="relative scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
{{ $titles->links(data: ['scrollTo' => '[data-catalog-results]']) }}
```

```blade
<section data-directory-results aria-labelledby="directory-results" class="mt-6 scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
{{ $items->onEachSide(1)->links(data: ['scrollTo' => '[data-directory-results]']) }}
```

```blade
<x-ui.panel
    data-viewing-history-results
    class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48"
    :title="__('catalog.viewing.history')"
    :subtitle="__('catalog.viewing.history_description').' '.trans_choice('catalog.counts.history_items', $history->total()).'.'"
    icon="fa-solid fa-list-ul"
    :pad="false"
>
{{ $history->links(data: ['scrollTo' => '[data-viewing-history-results]']) }}
```

```blade
<x-ui.panel
    data-admin-catalog-results
    class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48"
    title="Сериалы"
    subtitle="Поиск по ID, точному внешнему ID, началу slug или названию."
    icon="fa-solid fa-film"
    :pad="false"
>
{{ $titles->links(data: ['scrollTo' => '[data-admin-catalog-results]']) }}
```

- [ ] **Step 5: Scroll only after the refreshed DOM morphs**

Replace the catalog-specific click behavior in `resources/js/app.js` with this state machine:

```js
let paginationScrollReady = false;
let pendingPaginationScrollTo = null;

const loadPaginationScroll = () => {
    if (paginationScrollReady) {
        return;
    }

    paginationScrollReady = true;

    document.addEventListener('click', (event) => {
        const eventTarget = event.target instanceof Element ? event.target : event.target?.parentElement;
        const control = eventTarget?.closest('[data-pagination-control]');
        const selector = control?.getAttribute('data-pagination-scroll-to') || '';

        pendingPaginationScrollTo = selector === '' ? null : selector;
    });
};

const flushPaginationScroll = () => {
    const selector = pendingPaginationScrollTo;

    pendingPaginationScrollTo = null;

    if (!selector) {
        return;
    }

    const target = document.querySelector(selector);

    if (!target) {
        return;
    }

    window.requestAnimationFrame(() => {
        smoothAnchorScroll(target, { animate: true });
    });
};
```

Call `loadPaginationScroll()` from `loadCatalogInterfaces()`. Update the lifecycle hooks to this exact ordering so redirects/full navigations cannot reuse stale state:

```js
window.Livewire.hook('morphed', () => {
    void loadCatalogPlayers();
    loadCatalogInterfaces();
    flushPaginationScroll();
});

document.addEventListener('livewire:navigating', () => {
    pendingPaginationScrollTo = null;
    flushCatalogPlayersWithin(document, 'navigation');
    destroyCatalogPlayersWithin(document, { flush: false });
});
```

- [ ] **Step 6: Run focused PHP/JS contracts, Pint, and build**

Run:

```bash
php artisan test --filter='CatalogRouteFilterCompositionTest|FrontendAssetContractTest|BladeTemplateTest'
./vendor/bin/pint --dirty --format agent
npm run build
```

Expected: focused tests pass, Pint exits 0, Vite build exits 0.

- [ ] **Step 7: Commit the portal-wide paginator contract**

```bash
git status --short --branch
git add resources/views/vendor/livewire/tailwind.blade.php resources/views/catalog/titles.blade.php resources/views/livewire/catalog-directory-browser.blade.php resources/views/livewire/viewing-activity.blade.php resources/views/livewire/catalog-administration-manager.blade.php resources/js/app.js tests/Unit/FrontendAssetContractTest.php tests/Feature/CatalogRouteFilterCompositionTest.php
git commit -m "fix: make portal pagination resilient"
```

### Task 4: Add deterministic desktop/mobile browser regression coverage

**Files:**
- Modify: `tests/browser/prepare-fixtures.php`
- Modify: `tests/browser/catalog.spec.js`

**Interfaces:**
- Consumes: the DOM contracts from Tasks 2 and 3.
- Produces: a deterministic multi-page country fixture and Playwright assertions for page content, URL, scroll position, script groups, geometry, and runtime errors.

- [ ] **Step 1: Extend the isolated browser fixture database**

Import `App\Models\Actor`, create `Турция`, then create 30 published titles with deterministic slugs/titles and attach them to Turkey:

```php
$turkey = Country::query()->create([
    'name' => 'Турция',
    'slug' => 'turciia',
]);

CatalogTitle::factory()->count(30)->sequence(
    fn (Sequence $sequence): array => [
        'title' => sprintf('Турецкий браузерный сериал %02d', $sequence->index + 1),
        'slug' => sprintf('turkish-browser-title-%02d', $sequence->index + 1),
        'indexed_at' => now()->subMinutes($sequence->index + 1),
    ],
)->create()->each(fn (CatalogTitle $catalogTitle) => $catalogTitle->countries()->attach($turkey));
```

Attach five actors to the existing `Browser Smoke` title so both script groups and the symbols control are deterministic:

```php
collect([
    ['name' => 'Борис Актёр', 'slug' => 'boris-akter'],
    ['name' => 'Ёлка Актриса', 'slug' => 'elka-aktrisa'],
    ['name' => 'Alice Actor', 'slug' => 'alice-actor'],
    ['name' => 'Zed Actor', 'slug' => 'zed-actor'],
    ['name' => '123 Actor', 'slug' => '123-actor'],
])->each(function (array $attributes) use ($title): void {
    $actor = Actor::query()->create($attributes);
    $title->actors()->attach($actor);
});
```

Import both classes:

```php
use App\Models\Actor;
use Illuminate\Database\Eloquent\Factories\Sequence;
```

- [ ] **Step 2: Add a failing Playwright scenario**

Add a test that:

```js
test('country pagination changes results, scrolls to them and keeps alphabet scripts separate', async ({ page, baseURL }) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles/country/turciia?country%5B0%5D=turciia');
    const results = page.locator('[data-catalog-results]');
    const firstTitle = await page.locator('[data-catalog-card]').first().innerText();

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.getByRole('link', { name: 'Страница 2' }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page.locator('[data-catalog-pagination] [aria-current="page"]')).toHaveText('2');
    await expect(page.locator('[data-catalog-card]').first()).not.toHaveText(firstTitle);
    await expect.poll(() => results.evaluate((element) => Math.round(element.getBoundingClientRect().top))).toBeLessThan(320);

    await page.getByRole('link', { name: 'Назад' }).click();
    await expect(page).not.toHaveURL(/page=2/);

    const mobileControls = page.locator('[data-catalog-mobile-output-controls]');
    if ((page.viewportSize()?.width || 0) < 1024) {
        await mobileControls.locator('summary').click();
    }

    const alphabetRoot = (page.viewportSize()?.width || 0) < 1024
        ? mobileControls
        : page.locator('[data-catalog-desktop-alphabet]');
    await expect(alphabetRoot.locator('[data-catalog-alphabet-group="cyrillic"]')).toBeVisible();
    await expect(alphabetRoot.locator('[data-catalog-alphabet-group="latin"]')).toBeVisible();
    await expect(alphabetRoot.locator('[data-alphabet-letter="A"]')).toBeVisible();
    await expect(alphabetRoot.locator('[data-alphabet-letter="Z"]')).toBeVisible();

    await page.goto('/actors');
    await expect(page.locator('[data-directory-alphabet-group="cyrillic"]')).toBeVisible();
    await expect(page.locator('[data-directory-alphabet-group="latin"]')).toBeVisible();
    await expect(page.locator('[data-directory-alphabet-symbols]')).toBeVisible();
    await assertPageGeometry(page);
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});
```

- [ ] **Step 3: Run browser tests before the implementation commit and inspect failures**

Run: `npx playwright test tests/browser/catalog.spec.js --project='Desktop Chromium' --project='Mobile Chromium'`

Expected before Tasks 2–3 are present: grouped selectors or link selectors fail. If Tasks 2–3 are already present, temporarily verify the regression by checking the test failed on the parent commit or by reverting only the relevant working-tree hunk, then restore it.

- [ ] **Step 4: Run the completed browser suite and capture desktop/mobile evidence**

Run: `npx playwright test tests/browser/catalog.spec.js --project='Desktop Chromium' --project='Mobile Chromium'`

Expected: both Chromium projects pass with no overflow, local asset failures, console errors, or page errors. Retain failure-only artifacts under `output/playwright/`.

- [ ] **Step 5: Commit browser coverage**

```bash
git status --short --branch
git add tests/browser/prepare-fixtures.php tests/browser/catalog.spec.js
git commit -m "test: cover pagination and alphabet browser flows"
```

### Task 5: Update owner documentation and run final verification

**Files:**
- Modify: `docs/catalog-search.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: verified behavior from Tasks 1–4.
- Produces: one non-duplicated durable contract in each topic-owner document and a dated changelog entry.

- [ ] **Step 1: Update the durable behavior contracts**

Document these exact outcomes without editing managed `project-docs` blocks:

- `catalog-search.md`: individual Latin title letters, separate Cyrillic/Latin actor/director groups, unchanged `letter` query contract, legacy `latin`, and page/filter preservation;
- `frontend.md`: real-link Livewire fallback, per-list `scrollTo`, post-morph smooth scrolling, reduced motion, and the four targets;
- `views.md`: `CatalogAlphabet` prepares groups and the alphabet component remains query-free;
- `UI_STANDARDS.md`: labeled script groups wrap naturally, have no internal scrolling, and keep 44 px targets;
- `CHANGELOG.md`: one concise 14.07.2026 entry covering resilient pagination and split alphabets.

- [ ] **Step 2: Refresh managed documentation and inspect the resulting diff**

Run:

```bash
php artisan project:docs-refresh
git diff --check
git diff --stat
```

Expected: refresh exits 0, no whitespace errors, only scoped documentation/code/tests/build manifest changes are present.

- [ ] **Step 3: Run fresh final verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogAlphabetTest|CatalogTitlesViewModelTest|CatalogRouteFilterCompositionTest|FrontendAssetContractTest|CatalogPageTest|CatalogVisualSystemTest|BladeTemplateTest'
php artisan test
npm run build
npx playwright test tests/browser/catalog.spec.js --project='Desktop Chromium' --project='Mobile Chromium'
```

Expected: Pint exits 0; focused and full PHPUnit suites report 0 failures; Vite exits 0; both Playwright projects pass.

- [ ] **Step 4: Perform production HTTPS smoke checks with managed Chromium**

Check `/`, `/titles`, `/titles/country/turciia?country%5B0%5D=turciia`, `/actors`, and `/directors` at `1440×1200` and `390×844`. Record HTTP status, final URL, `h1`, active page, first-card change, scroll target top, horizontal overflow, console/page errors, failed local assets, and screenshots under `output/playwright/`. Do not fetch external media.

Expected: public routes return 200, pagination changes cards/URL, alphabet groups are separate, scroll reaches the result boundary, and runtime error arrays remain empty.

- [ ] **Step 5: Commit documentation and any generated tracked assets**

```bash
git status --short --branch
git add CHANGELOG.md docs/catalog-search.md docs/frontend.md docs/views.md docs/UI_STANDARDS.md README.md docs/CODE_STANDARDS.md docs/DATA_RELATIONS.md docs/MAINTENANCE_LOG.md docs/SOURCE_PARITY.md public/build/manifest.json public/build/assets
git commit -m "docs: document pagination and alphabet contracts"
```

If Vite build files are ignored or unchanged, omit them from `git add`. Before the commit, confirm the branch is `main`; after the commit, confirm `git status --short --branch` is clean apart from the expected ahead count.
