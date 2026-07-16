<section class="space-y-5">
        @island(name: 'catalog-live', with: $this->catalogPage)
        @if ($errors->any())
            <div role="alert" class="col-span-full rounded-panel border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <div class="flex items-start gap-3">
                    <x-ui.icon name="fa-solid fa-triangle-exclamation" align="start" />
                    <div>
                        <div class="font-bold">Проверьте параметры каталога.</div>
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
        <div class="min-w-0 space-y-5">
            <x-ui.panel>
                @island(name: 'catalog-live', with: $this->catalogPage)
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="inline-flex items-center gap-2 text-3xl font-bold text-slate-700">
                            <x-ui.icon name="fa-solid fa-clapperboard text-emerald-700" />
                            <span>{{ $seo['h1'] ?? 'Сериалы' }}</span>
                        </h1>

                        @if ($search !== '' || $filterView->hasActiveFilters() || $excludedTaxonomies->isNotEmpty() || $titleContext !== null || $invalidYear)
                            <div class="mt-3 space-y-3 text-sm">
                                <div class="hidden flex-wrap items-center gap-2 sm:flex">
                                    @if ($search !== '')
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutSearchQuery)" rel="nofollow" wire:click.prevent="clearSearch" active icon="fa-solid fa-magnifying-glass">Поиск по запросу {{ $search }} · очистить</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($titleContext !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutTitleQuery)" rel="nofollow" wire:click.prevent="clearTitleContext" active icon="fa-solid fa-clapperboard">Подборка по сериалу {{ $titleContext->display_title }} · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($filterView->selectedYears() as $selectedYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->yearQuery($selectedYear))" rel="nofollow" wire:click.prevent="removeYear({{ $selectedYear }})" active icon="fa-solid fa-calendar-days">Сериалы {{ $selectedYear }} года · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @if ($invalidYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" rel="nofollow" wire:click.prevent="resetGroup('year')" active icon="fa-solid fa-calendar-days">Год {{ $requestedYear }} не найден · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($selectedTaxonomies as $filterType => $taxonomies)
                                        @foreach ($taxonomies as $taxonomy)
                                            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->filterQuery($filterType, $taxonomy->slug))" rel="nofollow" wire:click.prevent="removeTaxonomy('{{ $filterType }}', '{{ $taxonomy->slug }}')" :icon="$filterView->icon($filterType)" active>{{ $filterView->taxonomyContextLabel($filterType, $taxonomy) }} · убрать</x-ui.taxonomy-chip>
                                        @endforeach
                                    @endforeach
                                    @foreach ($excludedTaxonomies as $filterType => $taxonomies)
                                        @foreach ($taxonomies as $taxonomy)
                                            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->exclusionQuery($filterType, $taxonomy->slug))" rel="nofollow" wire:click.prevent="removeExcluded('{{ $filterType }}', '{{ $taxonomy->slug }}')" active icon="fa-solid fa-minus">{{ $filterView->excludedTaxonomyLabel($filterType, $taxonomy) }} · убрать</x-ui.taxonomy-chip>
                                        @endforeach
                                    @endforeach
                                    @foreach ($filterView->listState('publication_type') as $publicationType)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('publication_type', $publicationType))" rel="nofollow" wire:click.prevent="removeChoice('publication_type', '{{ $publicationType }}')" active icon="fa-solid fa-clapperboard">Тип материалов — {{ $filterView->publicationTypeLabel($publicationType) }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($filterView->listState('subtitles') as $subtitleValue)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('subtitles', $subtitleValue))" rel="nofollow" wire:click.prevent="removeChoice('subtitles', '{{ $subtitleValue }}')" active icon="fa-solid fa-closed-captioning">Субтитры — {{ $filterView->subtitleLabel($subtitleValue) }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($filterView->listState('quality') as $quality)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('quality', $quality))" rel="nofollow" wire:click.prevent="removeChoice('quality', '{{ $quality }}')" active icon="fa-solid fa-display">Качество — {{ $quality }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($filterView->advancedFilterChips() as $chip)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutCatalogState($chip['key']))" rel="nofollow" wire:click.prevent="resetAdvanced('{{ $chip['key'] }}')" active icon="fa-solid fa-sliders">
                                            {{ $chip['label'] }} — {{ $chip['value'] }} · убрать
                                        </x-ui.taxonomy-chip>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-3 text-slate-500">
                                    <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-diagram-project text-slate-400" /> Активных фильтров — {{ $filterView->activeFilterCount() }}</span>
                                    @if ($invalidYear)
                                        <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-calendar-xmark text-amber-600" /> Ошибочный год — {{ $requestedYear }}</span>
                                    @endif
                                    @if ($filterView->selectedYears() !== [])
                                        <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-calendar-days text-slate-400" /> Выбранные годы — {{ implode(', ', $filterView->selectedYears()) }}</span>
                                    @endif
                                    @if ($titleContext !== null)
                                        <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-clapperboard text-slate-400" /> Подборка по сериалу {{ $titleContext->display_title }}</span>
                                    @endif
                                    <span><x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" /> {{ __('catalog.catalog.found_now', ['results' => trans_choice('catalog.counts.results', $titles->total())]) }}</span>
                                    <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="hidden items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-600 sm:inline-flex">
                                        <x-ui.icon name="fa-solid fa-rotate-left" />
                                        <span>Сбросить все</span>
                                    </a>
                                </div>
                            </div>
                        @else
                            <div class="mt-3 text-sm text-slate-500">
                                <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                                {{ __('catalog.catalog.found', ['results' => trans_choice('catalog.counts.results', $titles->total())]) }}
                            </div>
                        @endif
                    </div>

                    <form method="GET" action="{{ route('titles.index') }}" wire:submit="applySearch" role="search" aria-label="{{ $filterView->hasActiveFilters() ? 'Искать в выбранной подборке' : 'Поиск по каталогу' }}" class="flex w-full max-w-md min-w-0 gap-2">
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
                            label="Поиск по названию"
                            placeholder="Название сериала"
                            container-class="min-w-0 flex-1"
                            wire:model="filters.search"
                        />
                        <button type="submit" wire:loading.attr="disabled" wire:target="filters.search,applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-60">
                            <x-ui.icon name="fa-solid fa-arrow-right" />
                            <span>Найти</span>
                        </button>
                    </form>
                </div>

                @if ($tagPage !== null)
                    <section aria-labelledby="public-tag-summary-{{ $tagPage->publicId }}" class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50/60 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 id="public-tag-summary-{{ $tagPage->publicId }}" class="break-words text-lg font-black text-slate-800">{{ $tagPage->name }}</h2>
                                    <x-ui.status-pill variant="success">{{ __('tags.types.'.$tagPage->type) }}</x-ui.status-pill>
                                </div>
                                <p class="mt-1 text-sm text-slate-600">{{ trans_choice('tags.page.count', $tagPage->publicTitleCount, ['count' => $tagPage->publicTitleCount]) }}</p>
                            </div>
                            <a href="{{ route('tags.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                <x-ui.icon name="fa-solid fa-tags" />
                                <span>{{ __('tags.title') }}</span>
                            </a>
                        </div>

                        @if ($tagPage->shortDescription !== null)
                            <p class="mt-3 max-w-4xl text-sm leading-6 text-slate-700">{{ $tagPage->shortDescription }}</p>
                        @endif

                        @if ($tagPage->description !== null && $tagPage->description !== $tagPage->shortDescription)
                            <details class="group mt-3 max-w-4xl">
                                <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                    <x-ui.icon name="fa-solid fa-circle-info" />
                                    <span>{{ __('tags.page.show_description') }}</span>
                                    <x-ui.icon name="fa-solid fa-chevron-down transition group-open:rotate-180" />
                                </summary>
                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $tagPage->description }}</p>
                            </details>
                        @endif

                        @if ($tagPage->aliases !== [])
                            <div class="mt-4">
                                <h3 class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('tags.page.aliases') }}</h3>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($tagPage->aliases as $alias)
                                        <x-ui.status-pill variant="muted">{{ $alias }}</x-ui.status-pill>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($tagPage->related !== [])
                            <nav aria-labelledby="related-tags-{{ $tagPage->publicId }}" class="mt-4">
                                <h3 id="related-tags-{{ $tagPage->publicId }}" class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('tags.page.related') }}</h3>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($tagPage->related as $relatedTag)
                                        <a href="{{ route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $relatedTag['slug']]) }}" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-full bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-100 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                            <x-ui.icon name="fa-solid fa-tag text-emerald-700" />
                                            <span class="min-w-0 break-words">{{ $relatedTag['name'] }}</span>
                                            <span class="tabular-nums text-slate-500">{{ $relatedTag['count'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </nav>
                        @endif
                    </section>
                @endif

                <div class="mt-4 hidden flex-wrap gap-2 lg:flex">
                    @foreach ($filterView->sortLabels as $sortKey => $sortLabel)
                        <a data-catalog-sort-option href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}" rel="nofollow" wire:click.prevent="sortBy('{{ $sortKey }}')" @if ($filterView->isActiveSort($sortKey)) aria-current="true" @endif @class([
                            'inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 py-2 text-xs font-bold',
                            'bg-emerald-50 text-emerald-700' => $filterView->isActiveSort($sortKey),
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveSort($sortKey),
                        ])>
                            <x-ui.icon name="{{ $filterView->sortIcon($sortKey) }}" />
                            <span>{{ $sortLabel }}</span>
                        </a>
                    @endforeach
                </div>

                <div class="mt-3 hidden flex-wrap items-center gap-2 text-xs font-bold lg:flex">
                    <span class="text-slate-600">На странице:</span>
                    @foreach ([24, 48, 96] as $pageSize)
                        <a data-catalog-page-size-option href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}" rel="nofollow" wire:click.prevent="setPerPage({{ $pageSize }})" @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-3 py-2 tabular-nums',
                            'bg-emerald-50 text-emerald-700' => $perPage === $pageSize,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $perPage !== $pageSize,
                        ])>{{ $pageSize }}</a>
                    @endforeach
                </div>

                <x-catalog.alphabet-filter :filter-view="$filterView" class="mt-4 hidden lg:block" />

                <details data-catalog-mobile-output-controls class="group mt-4 rounded-lg bg-slate-50 p-2 lg:hidden">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-2 text-sm font-bold text-slate-700">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <x-ui.icon name="{{ $filterView->sortIcon($sort) }} text-amber-700" />
                            <span class="min-w-0 break-words">Сортировка: {{ $filterView->sortLabel($sort) }}</span>
                        </span>
                        <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                    </summary>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($filterView->sortLabels as $sortKey => $sortLabel)
                            <a href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}" rel="nofollow" wire:click.prevent="sortBy('{{ $sortKey }}')" @if ($filterView->isActiveSort($sortKey)) aria-current="true" @endif @class([
                                'inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 py-2 text-xs font-bold',
                                'bg-emerald-50 text-emerald-700' => $filterView->isActiveSort($sortKey),
                                'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveSort($sortKey),
                            ])>
                                <x-ui.icon name="{{ $filterView->sortIcon($sortKey) }}" />
                                <span>{{ $sortLabel }}</span>
                            </a>
                        @endforeach
                    </div>
                    <div data-catalog-mobile-page-size-controls class="mt-3 border-t border-slate-200 pt-3">
                        <div class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('catalog.catalog.page_size_label') }}</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ([24, 48, 96] as $pageSize)
                                <a href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}" rel="nofollow" wire:click.prevent="setPerPage({{ $pageSize }})" @class([
                                    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-3 py-2 text-xs font-bold tabular-nums',
                                    'bg-emerald-50 text-emerald-700' => $perPage === $pageSize,
                                    'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $perPage !== $pageSize,
                                ])>{{ $pageSize }}</a>
                            @endforeach
                        </div>
                    </div>
                    <x-catalog.alphabet-filter :filter-view="$filterView" mobile class="mt-3" />
                </details>
                @endisland

            </x-ui.panel>

            @island(name: 'catalog-live', defer: true)
                @placeholder
                    <x-catalog.unified-title-filters
                        :filter-view="$this->catalogPage['filterView']"
                        :route-year="$routeYear"
                        :route-filter-type="$routeFilterType"
                        :route-taxonomy="$routeTaxonomy"
                        loading
                    />
                @endplaceholder

                <x-catalog.unified-title-filters
                    :data="$this->catalogFacets"
                    :option-search="$this->optionSearch"
                    :route-year="$routeYear"
                    :route-filter-type="$routeFilterType"
                    :route-taxonomy="$routeTaxonomy"
                />
            @endisland

            @island(name: 'catalog-live', with: $this->catalogPage)
            @if ($collectionSuggestions->isNotEmpty())
                <section aria-labelledby="catalog-collection-suggestions-title">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 id="catalog-collection-suggestions-title" class="flex items-center gap-2 text-lg font-black text-slate-800">
                            <x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />
                            <span>{{ __('collections.directory.search_results') }}</span>
                        </h2>
                        <a href="{{ route('collections.index', ['q' => $search]) }}" class="text-sm font-bold text-emerald-700 hover:text-emerald-600">{{ __('collections.navigation.public_collections') }}</a>
                    </div>
                    <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($collectionSuggestions as $collectionSuggestion)
                            <x-collections.collection-card wire:key="search-collection-{{ $collectionSuggestion->public_id }}" :collection="$collectionSuggestion" />
                        @endforeach
                    </div>
                </section>
            @endif

            <div data-catalog-results class="relative scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
                <div wire:loading.delay wire:target="filters.search,applySearch,applyFilters,sortBy,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage" class="hidden absolute inset-x-0 top-0 z-20 rounded-panel bg-white text-sm font-bold text-emerald-700" role="status" aria-live="polite">
                    <div class="flex min-h-24 items-center justify-center">
                        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                        <span class="ml-2">Обновляем каталог…</span>
                    </div>
                </div>
                <div data-catalog-results-list wire:loading.class="opacity-50" wire:target="filters.search,applySearch,applyFilters,sortBy,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage" class="divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white">
                @forelse ($titles as $catalogTitle)
                    <x-catalog.title-card wire:key="catalog-title-{{ $catalogTitle->id }}" :title="$catalogTitle" layout="list" readable />
                @empty
                    <x-ui.panel class="col-span-full border-dashed">
                        <div class="flex flex-col gap-4">
                            <div>
                                <div class="inline-flex items-center gap-2 text-base font-bold text-slate-700">
                                    <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                                    @if ($insufficientSearch)
                                        <span>Запрос «{{ $search }}» слишком общий.</span>
                                    @elseif ($search !== '')
                                        <span>По запросу «{{ $search }}» ничего не найдено.</span>
                                    @else
                                        <span>Ничего не найдено.</span>
                                    @endif
                                </div>
                                @if ($insufficientSearch)
                                    <p class="mt-1 text-sm text-slate-500">Добавьте название, имя актера, режиссера или жанр.</p>
                                @elseif ($search !== '')
                                    <p class="mt-1 text-sm text-slate-500">Проверьте написание или измените фильтры.</p>
                                @else
                                    <p class="mt-1 text-sm text-slate-500">Измените или сбросьте выбранные фильтры.</p>
                                @endif
                            </div>
                            @if ($searchSuggestions->isNotEmpty())
                                <div aria-labelledby="catalog-search-suggestions-title" class="rounded-control bg-emerald-50 p-3">
                                    <div id="catalog-search-suggestions-title" class="text-sm font-bold text-emerald-800">Возможно, подойдет</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($searchSuggestions as $suggestion)
                                            <a
                                                href="{{ route('titles.index', array_merge($filterView->withoutSearchQuery, ['q' => $suggestion->suggestion_name])) }}"
                                                rel="nofollow"
                                                class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"
                                            >
                                                <x-ui.icon name="fa-solid fa-wand-magic-sparkles" />
                                                <span class="min-w-0 break-words">{{ $suggestion->suggestion_name }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if ($directorySuggestions->isNotEmpty())
                                <nav aria-labelledby="catalog-directory-suggestions-title" class="rounded-control bg-sky-50 p-3">
                                    <div id="catalog-directory-suggestions-title" class="text-sm font-bold text-sky-900">{{ __('catalog.directories.search_suggestion') }}</div>
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                        @foreach ($directorySuggestions as $directorySuggestion)
                                            <a
                                                href="{{ route($directorySuggestion->indexRouteName) }}"
                                                class="inline-flex min-h-11 items-center gap-2 py-2 text-sm font-bold text-sky-800 hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
                                            >
                                                <x-ui.icon :name="$directorySuggestion->icon" />
                                                <span>{{ $directorySuggestion->title }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </nav>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                @if ($search !== '')
                                    <a href="{{ route('titles.index', $filterView->withoutSearchQuery) }}" rel="nofollow" wire:click.prevent="clearSearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <x-ui.icon name="fa-solid fa-magnifying-glass-minus" />
                                        <span>Очистить поиск</span>
                                    </a>
                                @endif
                                @if ($filterView->hasActiveFilters() || $titleContext !== null || $filterView->selectedYears() !== [] || $invalidYear)
                                    <a href="{{ route('titles.index', $filterView->withoutFiltersQuery) }}" rel="nofollow" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <x-ui.icon name="fa-solid fa-filter-circle-xmark" />
                                        <span>Убрать фильтры</span>
                                    </a>
                                @endif
                                <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100">
                                    <x-ui.icon name="fa-solid fa-list-ul" />
                                    <span>Показать весь каталог</span>
                                </a>
                                <a href="{{ route('discover.index', ['type' => 'popular']) }}" wire:navigate class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-sky-50 px-4 py-2 text-sm font-bold text-sky-800 hover:bg-sky-100">
                                    <x-ui.icon name="fa-solid fa-compass" />
                                    <span>{{ __('recommendations.page.browse_popular') }}</span>
                                </a>
                            </div>
                        </div>
                    </x-ui.panel>
                @endforelse
                </div>
            </div>

            <div>
                {{ $titles->links(data: ['scrollTo' => '[data-catalog-results]']) }}
            </div>
            @endisland
        </div>
</section>
