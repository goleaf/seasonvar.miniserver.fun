<section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,1fr)]">
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
        <dialog
            id="catalog-filters"
            aria-labelledby="catalog-filter-dialog-title"
            data-catalog-filter-dialog
            wire:ignore.self
    class="fixed inset-x-0 top-0 m-0 min-h-dvh max-h-dvh w-full max-w-none overflow-y-auto border-0 bg-slate-50 p-3 backdrop:bg-slate-900/40 lg:sticky lg:inset-auto lg:top-24 lg:order-1 lg:block lg:min-h-0 lg:max-h-none lg:w-auto lg:self-start lg:overflow-visible lg:bg-transparent lg:p-0 lg:pr-1"
        >
            <div class="sticky top-0 z-20 mb-3 flex items-center justify-between gap-3 rounded-control bg-white p-2 shadow-panel lg:hidden">
                <h2 id="catalog-filter-dialog-title" class="flex min-w-0 items-center gap-2 break-words text-base font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-sliders" class="text-emerald-700" />
                    <span>Фильтры каталога</span>
                </h2>
                <button type="button" data-catalog-filter-dialog-close class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700" aria-label="Закрыть фильтры">
                    <x-ui.icon name="fa-solid fa-xmark" />
                </button>
            </div>
            <x-ui.panel title="Фильтры каталога" icon="fa-solid fa-sliders">
                @island(name: 'catalog-live', defer: true)
                    @placeholder
                        <div data-catalog-facets-loading aria-live="polite" class="flex min-h-24 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-5 text-sm font-bold text-slate-600">
                            <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
                            <span>Загружаем фильтры…</span>
                        </div>
                    @endplaceholder

                    <x-catalog.title-filters :data="$this->catalogFacets" :option-search="$this->optionSearch" />
                @endisland
            </x-ui.panel>
        </dialog>

        @island(name: 'catalog-live', with: $this->catalogPage)
        <div class="order-1 min-w-0 space-y-5 lg:order-2">
            <x-ui.panel>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="inline-flex items-center gap-2 text-3xl font-bold text-slate-700">
                            <x-ui.icon name="fa-solid fa-clapperboard text-emerald-700" />
                            <span>{{ $seo['h1'] ?? 'Сериалы' }}</span>
                        </h1>

                        <a href="#catalog-filters" data-catalog-filter-dialog-open aria-controls="catalog-filters" aria-haspopup="dialog" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 lg:hidden">
                            <x-ui.icon name="fa-solid fa-sliders" />
                            <span>Фильтры · {{ $filterView->activeFilterCount() }}</span>
                        </a>

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
                    <span class="text-slate-400">Вид:</span>
                    @foreach (['grid' => 'Сетка', 'list' => 'Список'] as $viewKey => $viewLabel)
                        <a data-catalog-view-option href="{{ route('titles.index', $filterView->viewQuery($viewKey)) }}" rel="nofollow" wire:click.prevent="setView('{{ $viewKey }}')" @class([
                            'inline-flex min-h-11 items-center rounded-full px-3 py-2',
                            'bg-emerald-50 text-emerald-700' => $view === $viewKey,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $view !== $viewKey,
                        ])>{{ $viewLabel }}</a>
                    @endforeach
                    <span class="ml-2 text-slate-400">На странице:</span>
                    @foreach ([24, 48, 96] as $pageSize)
                        <a data-catalog-page-size-option href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}" rel="nofollow" wire:click.prevent="setPerPage({{ $pageSize }})" @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-3 py-2 tabular-nums',
                            'bg-emerald-50 text-emerald-700' => $perPage === $pageSize,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $perPage !== $pageSize,
                        ])>{{ $pageSize }}</a>
                    @endforeach
                </div>

                <nav class="mt-4 hidden flex-wrap items-center gap-1.5 lg:flex" aria-label="Алфавитный переход по названиям">
                    <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-400">Алфавит:</span>
                    @foreach ($filterView->alphabet as $letter)
                        <a data-catalog-alphabet-option href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}" rel="nofollow" wire:click.prevent="setLetter('{{ $letter }}')" @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-2 text-xs font-bold transition',
                            'bg-emerald-50 text-emerald-700' => $filterView->isActiveLetter($letter),
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveLetter($letter),
                        ])>{{ $letter === 'latin' ? 'A–Z' : $letter }}</a>
                    @endforeach
                </nav>

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
                    <nav class="mt-3 flex flex-wrap items-center gap-1.5" aria-label="Мобильный алфавитный переход по названиям">
                        @foreach ($filterView->alphabet as $letter)
                            <a href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}" rel="nofollow" wire:click.prevent="setLetter('{{ $letter }}')" @class([
                                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-2 text-xs font-bold',
                                'bg-emerald-50 text-emerald-700' => $filterView->isActiveLetter($letter),
                                'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveLetter($letter),
                            ])>{{ $letter === 'latin' ? 'A–Z' : $letter }}</a>
                        @endforeach
                    </nav>
                </details>

                <details data-catalog-advanced-filters class="group mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3" @if ($filterView->hasAdvancedFilters()) open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-1 text-sm font-bold text-slate-700">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <x-ui.icon name="fa-solid fa-sliders text-emerald-700" />
                            <span class="min-w-0 break-words">{{ __('catalog.catalog.exact_filters.title') }}</span>
                            @if ($filterView->advancedFilterCount() > 0)
                                <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-100 px-2 py-1 text-xs tabular-nums text-emerald-700">{{ $filterView->advancedFilterCount() }}</span>
                            @endif
                        </span>
                        <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                    </summary>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">{{ __('catalog.catalog.exact_filters.description') }}</p>
                    <form method="GET" action="{{ route('titles.index') }}" wire:submit="applyFilters" class="mt-4 space-y-4">
                        @if ($titleContext !== null)
                            <input type="hidden" name="title" value="{{ $titleContext->slug }}">
                        @endif
                        @if ($search !== '')
                            <input type="hidden" name="q" value="{{ $search }}">
                        @endif
                        @foreach ($filterView->selectedFilterSlugs as $filterType => $slugs)
                            @foreach ($slugs as $slug)
                                <input type="hidden" name="{{ $filterType }}[]" value="{{ $slug }}">
                            @endforeach
                        @endforeach
                        @foreach (['exclude_country', 'exclude_genre'] as $excludedType)
                            @foreach ($filterView->listState($excludedType) as $slug)
                                <input type="hidden" name="{{ $excludedType }}[]" value="{{ $slug }}">
                            @endforeach
                        @endforeach
                        @foreach ($filterView->listState('year') as $selectedYear)
                            <input type="hidden" name="year[]" value="{{ $selectedYear }}">
                        @endforeach
                        @foreach (['publication_type', 'subtitles'] as $fixedGroup)
                            @foreach ($filterView->listState($fixedGroup) as $fixedValue)
                                <input type="hidden" name="{{ $fixedGroup }}[]" value="{{ $fixedValue }}">
                            @endforeach
                        @endforeach
                        @if ($sort !== 'updated')
                            <input type="hidden" name="sort" value="{{ $sort }}">
                        @endif
                        @if ($view !== 'grid')
                            <input type="hidden" name="view" value="{{ $view }}">
                        @endif
                        @if ($perPage !== 24)
                            <input type="hidden" name="per_page" value="{{ $perPage }}">
                        @endif
                        @if ($filterView->scalarState('letter') !== '')
                            <input type="hidden" name="letter" value="{{ $filterView->scalarState('letter') }}">
                        @endif

                        <div class="grid gap-3 lg:grid-cols-2">
                            <fieldset data-catalog-advanced-group="period" class="min-w-0 rounded-control border border-slate-200 bg-white p-3 sm:p-4">
                                <legend class="px-1 text-sm font-black text-slate-800">
                                    <span class="inline-flex items-center gap-2">
                                        <x-ui.icon name="fa-solid fa-calendar-days text-emerald-700" />
                                        <span>{{ __('catalog.catalog.exact_filters.period') }}</span>
                                    </span>
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('catalog.catalog.exact_filters.period_description') }}</p>
                                <div class="mt-3 flex flex-wrap items-end gap-2 sm:gap-3">
                                    <label class="min-w-28 flex-1 text-xs font-bold text-slate-600 sm:flex-none">
                                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.year_from') }}</span>
                                        <input type="number" wire:model="filters.yearFrom" name="year_from" min="1900" max="{{ $filterView->maximumCatalogYear() }}" value="{{ $filterView->scalarState('year_from') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-28">
                                    </label>
                                    <label class="min-w-28 flex-1 text-xs font-bold text-slate-600 sm:flex-none">
                                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.year_to') }}</span>
                                        <input type="number" wire:model="filters.yearTo" name="year_to" min="1900" max="{{ $filterView->maximumCatalogYear() }}" value="{{ $filterView->scalarState('year_to') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-28">
                                    </label>
                                    <label class="w-full text-xs font-bold text-slate-600 sm:w-auto">
                                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.updated') }}</span>
                                        <select wire:model.live="filters.updated" name="updated" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-56">
                                            <option value="">{{ __('catalog.catalog.exact_filters.any_time') }}</option>
                                            <option value="day" @selected($filterView->scalarState('updated') === 'day')>{{ __('catalog.catalog.exact_filters.updated_day') }}</option>
                                            <option value="week" @selected($filterView->scalarState('updated') === 'week')>{{ __('catalog.catalog.exact_filters.updated_week') }}</option>
                                            <option value="month" @selected($filterView->scalarState('updated') === 'month')>{{ __('catalog.catalog.exact_filters.updated_month') }}</option>
                                            <option value="year" @selected($filterView->scalarState('updated') === 'year')>{{ __('catalog.catalog.exact_filters.updated_year') }}</option>
                                        </select>
                                    </label>
                                </div>
                            </fieldset>

                            <fieldset data-catalog-advanced-group="volume" class="min-w-0 rounded-control border border-slate-200 bg-white p-3 sm:p-4">
                                <legend class="px-1 text-sm font-black text-slate-800">
                                    <span class="inline-flex items-center gap-2">
                                        <x-ui.icon name="fa-solid fa-layer-group text-sky-700" />
                                        <span>{{ __('catalog.catalog.exact_filters.volume') }}</span>
                                    </span>
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('catalog.catalog.exact_filters.volume_description') }}</p>
                                <div class="mt-3 space-y-3">
                                    <div class="flex flex-wrap items-end gap-2 sm:gap-3">
                                        <span class="w-full text-xs font-black text-slate-700 sm:w-16">{{ __('catalog.catalog.exact_filters.seasons') }}</span>
                                        <label class="min-w-24 flex-1 text-xs font-bold text-slate-500 sm:flex-none">
                                            <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.from') }}</span>
                                            <input type="number" wire:model="filters.seasonsMin" name="seasons_min" min="0" value="{{ $filterView->scalarState('seasons_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-24">
                                        </label>
                                        <label class="min-w-24 flex-1 text-xs font-bold text-slate-500 sm:flex-none">
                                            <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.to') }}</span>
                                            <input type="number" wire:model="filters.seasonsMax" name="seasons_max" min="0" value="{{ $filterView->scalarState('seasons_max') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-24">
                                        </label>
                                    </div>
                                    <div class="flex flex-wrap items-end gap-2 sm:gap-3">
                                        <span class="w-full text-xs font-black text-slate-700 sm:w-16">{{ __('catalog.catalog.exact_filters.episodes') }}</span>
                                        <label class="min-w-28 flex-1 text-xs font-bold text-slate-500 sm:flex-none">
                                            <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.from') }}</span>
                                            <input type="number" wire:model="filters.episodesMin" name="episodes_min" min="0" value="{{ $filterView->scalarState('episodes_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-28">
                                        </label>
                                        <label class="min-w-28 flex-1 text-xs font-bold text-slate-500 sm:flex-none">
                                            <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.to') }}</span>
                                            <input type="number" wire:model="filters.episodesMax" name="episodes_max" min="0" value="{{ $filterView->scalarState('episodes_max') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-28">
                                        </label>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset data-catalog-advanced-group="rating" class="min-w-0 rounded-control border border-slate-200 bg-white p-3 sm:p-4">
                                <legend class="px-1 text-sm font-black text-slate-800">
                                    <span class="inline-flex items-center gap-2">
                                        <x-ui.icon name="fa-solid fa-star text-amber-600" />
                                        <span>{{ __('catalog.catalog.exact_filters.rating') }}</span>
                                    </span>
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('catalog.catalog.exact_filters.rating_description') }}</p>
                                <div class="mt-3 flex flex-wrap items-end gap-2 sm:gap-3">
                                    <label class="w-full text-xs font-bold text-slate-600 sm:w-auto">
                                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.source') }}</span>
                                        <select wire:model.live="filters.ratingSource" name="rating_source" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-52">
                                            <option value="">{{ __('catalog.catalog.exact_filters.any_source') }}</option>
                                            <option value="kinopoisk" @selected($filterView->scalarState('rating_source') === 'kinopoisk')>КиноПоиск</option>
                                            <option value="imdb" @selected($filterView->scalarState('rating_source') === 'imdb')>IMDb</option>
                                        </select>
                                    </label>
                                    <label class="min-w-24 flex-1 text-xs font-bold text-slate-600 sm:flex-none">
                                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.rating_from') }}</span>
                                        <input type="number" wire:model="filters.ratingMin" name="rating_min" min="0" max="10" step="0.1" value="{{ $filterView->scalarState('rating_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-24">
                                    </label>
                                    <label class="min-w-36 flex-1 text-xs font-bold text-slate-600 sm:flex-none">
                                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.votes_from') }}</span>
                                        <input type="number" wire:model="filters.votesMin" name="votes_min" min="0" value="{{ $filterView->scalarState('votes_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-36">
                                    </label>
                                </div>
                            </fieldset>

                            <fieldset data-catalog-advanced-group="video" class="min-w-0 rounded-control border border-slate-200 bg-white p-3 sm:p-4">
                                <legend class="px-1 text-sm font-black text-slate-800">
                                    <span class="inline-flex items-center gap-2">
                                        <x-ui.icon name="fa-solid fa-circle-play text-violet-700" />
                                        <span>{{ __('catalog.catalog.exact_filters.video') }}</span>
                                    </span>
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('catalog.catalog.exact_filters.video_description') }}</p>
                                <label class="mt-3 block text-xs font-bold text-slate-600">
                                    <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.availability') }}</span>
                                    <select wire:model.live="filters.video" name="video" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-48">
                                        <option value="">{{ __('catalog.catalog.exact_filters.availability_any') }}</option>
                                        <option value="available" @selected($filterView->scalarState('video') === 'available')>{{ __('catalog.catalog.exact_filters.video_available') }}</option>
                                        <option value="missing" @selected($filterView->scalarState('video') === 'missing')>{{ __('catalog.catalog.exact_filters.video_missing') }}</option>
                                    </select>
                                </label>
                                <div class="mt-3">
                                    <div class="text-xs font-bold text-slate-600">{{ __('catalog.catalog.exact_filters.quality') }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach (['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'] as $quality)
                                            <label @class([
                                                'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control px-3 py-2 text-sm font-semibold transition',
                                                'bg-emerald-50 text-emerald-700' => in_array($quality, $filterView->listState('quality'), true),
                                                'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! in_array($quality, $filterView->listState('quality'), true),
                                            ])>
                                                <input type="checkbox" wire:model.live="filters.qualities" wire:replace.self name="quality[]" value="{{ $quality }}" class="h-5 w-5 accent-emerald-700">
                                                <span>{{ $quality }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <div class="flex flex-col gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:items-center">
                            <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60">
                                <x-ui.icon name="fa-solid fa-filter" />
                                <span>{{ __('catalog.catalog.exact_filters.show_results') }}</span>
                            </button>
                            <a href="{{ route('titles.index', $filterView->advancedFiltersResetQuery()) }}" rel="nofollow" wire:click.prevent="resetAdvancedFilters" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700">
                                <x-ui.icon name="fa-solid fa-rotate-left" />
                                <span>{{ __('catalog.catalog.exact_filters.reset') }}</span>
                            </a>
                        </div>
                    </form>
                </details>
            </x-ui.panel>

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
                {{ $titles->links() }}
            </div>
        </div>
        @endisland
</section>
