<section class="space-y-6">
    @island(name: 'catalog-live', with: $this->catalogPage)
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <div class="flex items-start gap-3">
                    <x-ui.icon name="fa-solid fa-triangle-exclamation" align="start" />
                    <div>
                        <div class="font-semibold">{{ __('catalog.catalog.validation_summary') }}</div>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    @endisland

    @island(name: 'catalog-live', with: $this->catalogPage)
        <header data-catalog-page-header class="border-b border-slate-200 pb-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                    {{ $seo['h1'] ?? __('catalog.navigation.all_titles') }}
                </h1>
                <p class="text-sm font-semibold text-slate-600">
                    {{ __('catalog.catalog.found_label') }} {{ $filterView->resultCountLabel($titles->total()) }}
                </p>
            </div>

            <form
                method="GET"
                action="{{ route('titles.index') }}"
                wire:submit="applySearch"
                role="search"
                aria-label="{{ $filterView->hasActiveFilters() ? __('catalog.catalog.search_selected') : __('catalog.catalog.search_catalog') }}"
                class="mt-5 flex min-w-0 gap-2"
            >
                @foreach ($filterView->searchFormState() as $stateKey => $stateValue)
                    @if (is_array($stateValue))
                        @foreach ($stateValue as $stateItem)
                            <input type="hidden" name="{{ $stateKey }}[]" value="{{ $stateItem }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $stateKey }}" value="{{ $stateValue }}">
                    @endif
                @endforeach
                <x-form.search-field
                    id="catalog-search"
                    name="q"
                    :value="$search"
                    :label="__('catalog.catalog.search_label')"
                    :placeholder="__('catalog.catalog.search_placeholder')"
                    container-class="min-w-0 flex-1"
                    wire:model="filters.search"
                />
                <button type="submit" aria-label="{{ __('catalog.catalog.search_submit') }}" wire:loading.attr="disabled" wire:target="filters.search,applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 disabled:cursor-wait disabled:opacity-60">
                    <x-ui.icon name="fa-solid fa-magnifying-glass" />
                    <span class="hidden sm:inline">{{ __('catalog.catalog.search_submit') }}</span>
                </button>
            </form>
        </header>
    @endisland

    <div data-catalog-desktop-layout class="min-w-0 lg:grid lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start lg:gap-6">
        <aside data-catalog-filter-sidebar class="min-w-0 lg:sticky lg:top-24 lg:self-start">
            @island(name: 'catalog-live', lazy: true)
                @placeholder
                    <div
                        id="catalog-filters"
                        data-catalog-advanced-filters
                        data-catalog-unified-filters
                        data-catalog-mobile-filter-page
                        aria-busy="true"
                        class="rounded-xl border border-slate-200 bg-white p-3"
                    >
                        <div data-catalog-facets-loading role="status" aria-live="polite" class="flex min-h-24 items-center justify-center gap-2 px-4 py-5 text-sm font-semibold text-slate-600">
                            <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
                            <span>{{ __('catalog.catalog.filters.loading') }}</span>
                        </div>
                    </div>
                @endplaceholder

                <x-catalog.unified-title-filters
                    :data="$this->catalogFacets"
                    :option-search="$this->optionSearch"
                    :route-year="$routeYear"
                    :route-filter-type="$routeFilterType"
                    :route-taxonomy="$routeTaxonomy"
                />
            @endisland
        </aside>

        <div class="mt-5 min-w-0 space-y-5 lg:mt-0">
            @island(name: 'catalog-live', with: $this->catalogPage)
                @if ($tagPage !== null)
                    <section aria-labelledby="public-tag-summary-{{ $tagPage->publicId }}" class="border-b border-slate-200 pb-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 id="public-tag-summary-{{ $tagPage->publicId }}" class="break-words text-xl font-semibold text-slate-900">{{ $tagPage->name }}</h2>
                                    <x-ui.status-pill variant="success">{{ __('tags.types.'.$tagPage->type) }}</x-ui.status-pill>
                                </div>
                                <p class="mt-1 text-sm text-slate-600">{{ trans_choice('tags.page.count', $tagPage->publicTitleCount, ['count' => $tagPage->publicTitleCount]) }}</p>
                            </div>
                            <a href="{{ route('tags.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                <x-ui.icon name="fa-solid fa-tags" />
                                <span>{{ __('tags.title') }}</span>
                            </a>
                        </div>
                        @if ($tagPage->shortDescription !== null)
                            <p class="mt-3 max-w-4xl text-sm leading-6 text-slate-700">{{ $tagPage->shortDescription }}</p>
                        @endif
                        @if ($tagPage->description !== null && $tagPage->description !== $tagPage->shortDescription)
                            <details class="group mt-3 max-w-4xl">
                                <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                    <x-ui.icon name="fa-solid fa-circle-info" />
                                    <span>{{ __('tags.page.show_description') }}</span>
                                    <x-ui.icon name="fa-solid fa-chevron-down transition group-open:rotate-180" />
                                </summary>
                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $tagPage->description }}</p>
                            </details>
                        @endif
                        @if ($tagPage->related !== [])
                            <div class="mt-4" aria-label="{{ __('tags.page.related') }}">
                                <h3 class="text-sm font-semibold text-slate-700">{{ __('tags.page.related') }}</h3>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($tagPage->related as $relatedTag)
                                        <a
                                            href="{{ route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $relatedTag['slug']]) }}"
                                            class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
                                        >
                                            <x-ui.icon name="fa-solid fa-tag text-slate-500" />
                                            <span>{{ $relatedTag['name'] }}</span>
                                            <span class="text-xs text-slate-500">{{ $relatedTag['count'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                @endif

                <div data-catalog-current-result-label="{{ $filterView->resultCountLabel($titles->total()) }}">
                    <x-catalog.title-output-controls :filter-view="$filterView" :per-page="$perPage" />
                </div>

                <x-catalog.active-title-filters
                    :filter-view="$filterView"
                    :search="$search"
                    :title-context="$titleContext"
                    :invalid-year="$invalidYear"
                    :requested-year="$requestedYear"
                    :selected-taxonomies="$selectedTaxonomies"
                    :excluded-taxonomies="$excludedTaxonomies"
                />

                @if ($directorySuggestions->isNotEmpty())
                    <section aria-labelledby="catalog-directory-suggestions-title" class="rounded-xl border border-slate-200 bg-white p-4">
                        <h2 id="catalog-directory-suggestions-title" class="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <x-ui.icon name="fa-solid fa-folder-tree text-emerald-700" />
                            <span>{{ __('catalog.directories.label') }}</span>
                        </h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($directorySuggestions as $directorySuggestion)
                                <a
                                    href="{{ route($directorySuggestion->indexRouteName) }}"
                                    class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
                                >
                                    <x-ui.icon :name="$directorySuggestion->icon" />
                                    <span>{{ __('catalog.directories.search_suggestion') }}: {{ $directorySuggestion->title }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($collectionSuggestions->isNotEmpty())
                    <section aria-labelledby="catalog-collection-suggestions-title">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h2 id="catalog-collection-suggestions-title" class="flex items-center gap-2 text-lg font-semibold text-slate-900">
                                <x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />
                                <span>{{ __('collections.directory.search_results') }}</span>
                            </h2>
                            <a href="{{ route('discover.index', ['type' => 'popular', 'collections_q' => $search]).'#collections' }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">{{ __('collections.navigation.public_collections') }}</a>
                        </div>
                        <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($collectionSuggestions as $collectionSuggestion)
                                <x-collections.collection-card wire:key="search-collection-{{ $collectionSuggestion->public_id }}" :collection="$collectionSuggestion" />
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="relative">
                    <div wire:loading.delay wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll" class="absolute inset-x-0 top-0 z-20 hidden rounded-xl bg-white/95 text-sm font-semibold text-emerald-700" role="status" aria-live="polite">
                        <div class="flex min-h-24 items-center justify-center">
                            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                            <span class="ml-2">{{ __('catalog.catalog.updating') }}</span>
                        </div>
                    </div>

                    @island(name: 'catalog-pagination', always: true, with: $this->catalogPage)
                        @if ($cardActionNotice !== null)
                            <div data-card-action-notice role="status" aria-live="polite" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                                {{ $cardActionNotice }}
                            </div>
                        @endif
                        @error('cardAction')
                            <div role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                {{ $message }}
                            </div>
                        @enderror
                        <x-ui.pagination-region name="catalog-results" data-catalog-results>
                            <div
                                data-catalog-results-list
                                data-catalog-results-view="{{ $view }}"
                                wire:loading.class="opacity-50"
                                wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll"
                                @class([
                                    'min-w-0',
                                    'grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5' => $view === 'grid',
                                    'divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white' => $view === 'list',
                                ])
                            >
                                @forelse ($titles as $catalogTitle)
                                    <x-catalog.title-card
                                        wire:key="catalog-title-{{ $catalogTitle->id }}"
                                        :title="$catalogTitle"
                                        :layout="$view"
                                        :show-description="$view === 'list'"
                                        interactive
                                        :viewer-authenticated="$viewerAuthenticated"
                                        readable
                                    />
                                @empty
                                    <x-ui.panel class="col-span-full border-dashed">
                                        <div class="flex flex-col gap-4">
                                            <div>
                                                <div class="inline-flex items-center gap-2 text-base font-semibold text-slate-700">
                                                    <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-600" />
                                                    @if ($insufficientSearch)
                                                        <span>{{ __('catalog.catalog.empty.insufficient', ['query' => $search]) }}</span>
                                                    @elseif ($search !== '')
                                                        <span>{{ __('catalog.catalog.empty.query', ['query' => $search]) }}</span>
                                                    @else
                                                        <span>{{ __('catalog.catalog.empty.filters') }}</span>
                                                    @endif
                                                </div>
                                                <p class="mt-1 text-sm text-slate-600">
                                                    {{ $insufficientSearch ? __('catalog.catalog.empty.insufficient_hint') : ($search !== '' ? __('catalog.catalog.empty.query_hint') : __('catalog.catalog.empty.filters_hint')) }}
                                                </p>
                                            </div>
                                            @if ($searchSuggestions->isNotEmpty())
                                                <div aria-labelledby="catalog-search-suggestions-title" class="rounded-lg bg-emerald-50 p-3">
                                                    <div id="catalog-search-suggestions-title" class="text-sm font-semibold text-emerald-800">{{ __('catalog.catalog.possible_match') }}</div>
                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                        @foreach ($searchSuggestions as $suggestion)
                                                            <a href="{{ route('titles.index', array_merge($filterView->withoutSearchQuery, ['q' => $suggestion->suggestion_name])) }}" rel="nofollow" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                                                <x-ui.icon name="fa-solid fa-wand-magic-sparkles" />
                                                                <span class="min-w-0 break-words">{{ $suggestion->suggestion_name }}</span>
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                            <div class="flex flex-wrap gap-2">
                                                @if ($search !== '')
                                                    <a href="{{ route('titles.index', $filterView->withoutSearchQuery) }}" rel="nofollow" wire:click.prevent="clearSearch" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                                        <x-ui.icon name="fa-solid fa-magnifying-glass-minus" />
                                                        <span>{{ __('catalog.catalog.clear_search') }}</span>
                                                    </a>
                                                @endif
                                                <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                                                    <x-ui.icon name="fa-solid fa-list-ul" />
                                                    <span>{{ __('catalog.catalog.show_all') }}</span>
                                                </a>
                                            </div>
                                        </div>
                                    </x-ui.panel>
                                @endforelse
                            </div>

                            <div class="mt-5">
                                {{ $titles->links(data: ['region' => 'catalog-results']) }}
                            </div>
                        </x-ui.pagination-region>
                    @endisland
                </div>
            @endisland
        </div>
    </div>
</section>
