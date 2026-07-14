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
                            label="Поиск по каталогу"
                            placeholder="Название, описание или тег"
                            container-class="min-w-0 flex-1"
                            wire:model="filters.search"
                        />
                        <button type="submit" wire:loading.attr="disabled" wire:target="filters.search,applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-60">
                            <x-ui.icon name="fa-solid fa-arrow-right" />
                            <span>Найти</span>
                        </button>
                    </form>
                </div>

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
                    <span class="text-slate-600">Вид:</span>
                    @foreach (['grid' => 'Сетка', 'list' => 'Список'] as $viewKey => $viewLabel)
                        <a data-catalog-view-option href="{{ route('titles.index', $filterView->viewQuery($viewKey)) }}" rel="nofollow" wire:click.prevent="setView('{{ $viewKey }}')" @class([
                            'inline-flex min-h-11 items-center rounded-full px-3 py-2',
                            'bg-emerald-50 text-emerald-700' => $view === $viewKey,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $view !== $viewKey,
                        ])>{{ $viewLabel }}</a>
                    @endforeach
                    <span class="ml-2 text-slate-600">На странице:</span>
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
                    <div data-catalog-mobile-view-controls class="mt-3 border-t border-slate-200 pt-3">
                        <div class="text-xs font-black uppercase tracking-wide text-slate-400">{{ __('catalog.catalog.view_label') }}</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['grid' => __('catalog.catalog.view_grid'), 'list' => __('catalog.catalog.view_list')] as $viewKey => $viewLabel)
                                <a href="{{ route('titles.index', $filterView->viewQuery($viewKey)) }}" rel="nofollow" wire:click.prevent="setView('{{ $viewKey }}')" @class([
                                    'inline-flex min-h-11 items-center rounded-full px-3 py-2 text-xs font-bold',
                                    'bg-emerald-50 text-emerald-700' => $view === $viewKey,
                                    'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $view !== $viewKey,
                                ])>{{ $viewLabel }}</a>
                            @endforeach
                        </div>
                    </div>
                    <div data-catalog-mobile-page-size-controls class="mt-3">
                        <div class="text-xs font-black uppercase tracking-wide text-slate-400">{{ __('catalog.catalog.page_size_label') }}</div>
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
                        loading
                    />
                @endplaceholder

                <x-catalog.unified-title-filters
                    :data="$this->catalogFacets"
                    :option-search="$this->optionSearch"
                />
            @endisland

            @island(name: 'catalog-live', with: $this->catalogPage)
            <div data-catalog-results class="relative scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
                <div wire:loading.delay wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage" class="hidden absolute inset-x-0 top-0 z-20 rounded-panel bg-white text-sm font-bold text-emerald-700" role="status" aria-live="polite">
                    <div class="flex min-h-24 items-center justify-center">
                        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                        <span class="ml-2">Обновляем каталог…</span>
                    </div>
                </div>
                <div wire:loading.class="opacity-50" wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage" @class([
                'divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white' => $view === 'list',
                'grid auto-rows-fr gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3 2xl:grid-cols-4' => $view !== 'list',
            ])>
                @forelse ($titles as $catalogTitle)
                    @if ($view === 'list')
                        <x-catalog.title-card wire:key="catalog-title-{{ $catalogTitle->id }}" :title="$catalogTitle" layout="horizontal" readable />
                    @else
                        <x-catalog.title-card wire:key="catalog-title-{{ $catalogTitle->id }}" :title="$catalogTitle" />
                    @endif
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
                                    <x-ui.icon name="fa-solid fa-table-cells-large" />
                                    <span>Показать весь каталог</span>
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
