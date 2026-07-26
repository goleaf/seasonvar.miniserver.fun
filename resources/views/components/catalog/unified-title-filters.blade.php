<details id="catalog-filters" open data-catalog-advanced-filters data-catalog-unified-filters data-catalog-mobile-filter-page data-active-filter-count="{{ $filterView->activeFilterCount() }}" data-filter-submit-template="{{ __('catalog.catalog.show_result_count', ['results' => ':results']) }}" class="group hidden rounded-xl border border-slate-200 bg-white p-3 open:block group-open:min-h-[calc(100dvh-8rem)] lg:block lg:min-h-0">
    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-1 text-sm font-semibold text-slate-900 lg:hidden">
        <span class="inline-flex min-w-0 items-center gap-2">
            <x-ui.icon name="fa-solid fa-arrow-left text-emerald-700" />
            <span class="min-w-0 break-words">{{ __('catalog.catalog.filter_back') }}</span>
            @if ($filterView->activeFilterCount() > 0)
                <span data-catalog-filter-count class="inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-100 px-2 py-1 text-xs tabular-nums text-emerald-700">{{ $filterView->activeFilterCount() }}</span>
            @endif
        </span>
        <x-ui.icon name="fa-solid fa-chevron-down text-slate-600 transition group-open:rotate-180" />
    </summary>
    <div class="hidden items-center justify-between gap-3 lg:flex">
        <h2 class="text-lg font-semibold text-slate-900">{{ __('catalog.catalog.filters.title') }}</h2>
        @if ($filterView->activeFilterCount() > 0)
            <span data-catalog-filter-count class="inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold tabular-nums text-emerald-800">{{ $filterView->activeFilterCount() }}</span>
        @endif
    </div>
    <p class="mt-2 hidden text-sm leading-6 text-slate-600 group-open:block lg:block">{{ __('catalog.catalog.exact_filters.description') }}</p>
    <form method="GET" action="{{ route('titles.index') }}" wire:submit="applyFilters" wire:island="catalog-live" class="mt-4 hidden space-y-4 group-open:block lg:block">
        @foreach ($filterView->filterFormState() as $stateKey => $stateValue)
            @if (is_array($stateValue))
                @foreach ($stateValue as $stateItem)
                    <input type="hidden" name="{{ $stateKey }}[]" value="{{ $stateItem }}">
                @endforeach
            @else
                <input type="hidden" name="{{ $stateKey }}" value="{{ $stateValue }}">
            @endif
        @endforeach

        <div class="space-y-3">
            <fieldset data-catalog-advanced-group="period" class="min-w-0 border-b border-slate-200 pb-4">
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
                        <input type="number" wire:model.live="filters.yearFrom" name="year_from" min="1900" max="{{ $filterView->maximumCatalogYear() }}" value="{{ $filterView->scalarState('year_from') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-28">
                    </label>
                    <label class="min-w-28 flex-1 text-xs font-bold text-slate-600 sm:flex-none">
                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.year_to') }}</span>
                        <input type="number" wire:model.live="filters.yearTo" name="year_to" min="1900" max="{{ $filterView->maximumCatalogYear() }}" value="{{ $filterView->scalarState('year_to') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-28">
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

            <fieldset data-catalog-advanced-group="volume" class="min-w-0 border-b border-slate-200 pb-4">
                <legend class="px-1 text-sm font-black text-slate-800">
                    <span class="inline-flex items-center gap-2">
                        <x-ui.icon name="fa-solid fa-layer-group text-sky-700" />
                        <span>{{ __('catalog.catalog.exact_filters.volume') }}</span>
                    </span>
                </legend>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('catalog.catalog.exact_filters.volume_description') }}</p>
                <div class="mt-3 space-y-3">
                    <div>
                        <span class="block text-xs font-black text-slate-700">{{ __('catalog.catalog.exact_filters.seasons') }}</span>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <label class="min-w-0 text-xs font-bold text-slate-600">
                                <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.from') }}</span>
                                <input type="number" wire:model.live="filters.seasonsMin" name="seasons_min" min="0" value="{{ $filterView->scalarState('seasons_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            </label>
                            <label class="min-w-0 text-xs font-bold text-slate-600">
                                <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.to') }}</span>
                                <input type="number" wire:model.live="filters.seasonsMax" name="seasons_max" min="0" value="{{ $filterView->scalarState('seasons_max') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            </label>
                        </div>
                    </div>
                    <div>
                        <span class="block text-xs font-black text-slate-700">{{ __('catalog.catalog.exact_filters.episodes') }}</span>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <label class="min-w-0 text-xs font-bold text-slate-600">
                                <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.from') }}</span>
                                <input type="number" wire:model.live="filters.episodesMin" name="episodes_min" min="0" value="{{ $filterView->scalarState('episodes_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            </label>
                            <label class="min-w-0 text-xs font-bold text-slate-600">
                                <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.to') }}</span>
                                <input type="number" wire:model.live="filters.episodesMax" name="episodes_max" min="0" value="{{ $filterView->scalarState('episodes_max') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            </label>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset data-catalog-advanced-group="rating" class="min-w-0 border-b border-slate-200 pb-4">
                <legend class="px-1 text-sm font-black text-slate-800">
                    <span class="inline-flex items-center gap-2">
                        <x-ui.icon name="fa-solid fa-star text-amber-600" />
                        <span>{{ __('catalog.catalog.exact_filters.rating') }}</span>
                    </span>
                </legend>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('catalog.catalog.exact_filters.rating_description') }}</p>
                <div class="mt-3 space-y-2">
                    <label class="block text-xs font-bold text-slate-600">
                        <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.source') }}</span>
                        <select wire:model.live="filters.ratingSource" name="rating_source" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            <option value="">{{ __('catalog.catalog.exact_filters.any_source') }}</option>
                            <option value="kinopoisk" @selected($filterView->scalarState('rating_source') === 'kinopoisk')>{{ __('catalog.catalog.advanced_filter_values.rating_kinopoisk') }}</option>
                            <option value="imdb" @selected($filterView->scalarState('rating_source') === 'imdb')>{{ __('catalog.catalog.advanced_filter_values.rating_imdb') }}</option>
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="min-w-0 text-xs font-bold text-slate-600">
                            <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.rating_from') }}</span>
                            <input type="number" wire:model.live="filters.ratingMin" name="rating_min" min="0" max="10" step="0.1" value="{{ $filterView->scalarState('rating_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        </label>
                        <label class="min-w-0 text-xs font-bold text-slate-600">
                            <span class="mb-1 block">{{ __('catalog.catalog.exact_filters.votes_from') }}</span>
                            <input type="number" wire:model.live="filters.votesMin" name="votes_min" min="0" value="{{ $filterView->scalarState('votes_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        </label>
                    </div>
                </div>
            </fieldset>

            <fieldset data-catalog-advanced-group="video" class="min-w-0 border-b border-slate-200 pb-4">
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
                                'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' => ! in_array($quality, $filterView->listState('quality'), true),
                            ])>
                                <input type="checkbox" wire:model.live="filters.qualities" name="quality[]" value="{{ $quality }}" class="h-5 w-5 accent-emerald-700">
                                <span>{{ $quality }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </fieldset>
        </div>
        <section aria-labelledby="catalog-facet-groups-title" class="border-t border-slate-200 pt-4">
            <h3 id="catalog-facet-groups-title" class="text-base font-black text-slate-800">{{ __('catalog.catalog.filters.title') }}</h3>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">{{ __('catalog.catalog.filters.description') }}</p>

            @if ($facetsLoaded)
                <x-catalog.title-filters
                    :data="$facetData"
                    :option-search="$optionSearch"
                    :route-year="$routeYear"
                    :route-filter-type="$routeFilterType"
                    :route-taxonomy="$routeTaxonomy"
                />
            @else
                <div data-catalog-facets-loading aria-live="polite" class="mt-3 flex min-h-24 items-center justify-center gap-2 rounded-control bg-white px-4 py-5 text-sm font-bold text-slate-600">
                    <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
                    <span>{{ __('catalog.catalog.filters.loading') }}</span>
                </div>
            @endif
        </section>

        <details data-catalog-mobile-alphabet class="group rounded-lg border border-slate-200 p-2 lg:hidden">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-2 text-sm font-semibold text-slate-700">
                <span class="inline-flex items-center gap-2">
                    <x-ui.icon name="fa-solid fa-arrow-down-a-z text-emerald-700" />
                    <span>{{ __('catalog.catalog.alphabet_menu') }}</span>
                </span>
                <x-ui.icon name="fa-solid fa-chevron-down text-xs text-slate-600 transition group-open:rotate-180" />
            </summary>
            <x-catalog.alphabet-filter :filter-view="$filterView" mobile class="mt-2 border-t border-slate-200 pt-3" />
        </details>

        <div class="flex flex-col gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:items-center">
            <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
                <x-ui.icon name="fa-solid fa-filter" />
                <span data-catalog-filter-submit-label>{{ __('catalog.catalog.exact_filters.show_results') }}</span>
            </button>
            <button type="button" data-catalog-filter-cancel class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                <x-ui.icon name="fa-solid fa-xmark" />
                <span>{{ __('catalog.catalog.exact_filters.cancel') }}</span>
            </button>
            <a href="{{ route('titles.index') }}" rel="nofollow" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-800">
                <x-ui.icon name="fa-solid fa-rotate-left" />
                <span>{{ __('catalog.catalog.exact_filters.reset') }}</span>
            </a>
        </div>
    </form>
</details>
