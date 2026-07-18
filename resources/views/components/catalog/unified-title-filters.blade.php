<details id="catalog-filters" data-catalog-advanced-filters data-catalog-unified-filters data-active-filter-count="{{ $filterView->activeFilterCount() }}" class="group mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3" open>
    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-1 text-sm font-bold text-slate-700">
        <span class="inline-flex min-w-0 items-center gap-2">
            <x-ui.icon name="fa-solid fa-sliders text-emerald-700" />
            <span class="min-w-0 break-words">{{ __('catalog.catalog.exact_filters.title') }}</span>
            @if ($filterView->activeFilterCount() > 0)
                <span data-catalog-filter-count class="inline-flex min-w-6 items-center justify-center rounded-full bg-emerald-100 px-2 py-1 text-xs tabular-nums text-emerald-700">{{ $filterView->activeFilterCount() }}</span>
            @endif
        </span>
        <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
    </summary>
    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">{{ __('catalog.catalog.exact_filters.description') }}</p>
    <form method="GET" action="{{ route('titles.index') }}" wire:submit="applyFilters" wire:island="catalog-live" class="mt-4 space-y-4">
        @foreach ($filterView->filterFormState() as $stateKey => $stateValue)
            @if (is_array($stateValue))
                @foreach ($stateValue as $stateItem)
                    <input type="hidden" name="{{ $stateKey }}[]" value="{{ $stateItem }}">
                @endforeach
            @else
                <input type="hidden" name="{{ $stateKey }}" value="{{ $stateValue }}">
            @endif
        @endforeach

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
                        <select wire:model="filters.updated" name="updated" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-56">
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
                        <select wire:model="filters.ratingSource" name="rating_source" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-52">
                            <option value="">{{ __('catalog.catalog.exact_filters.any_source') }}</option>
                            <option value="kinopoisk" @selected($filterView->scalarState('rating_source') === 'kinopoisk')>{{ __('catalog.catalog.advanced_filter_values.rating_kinopoisk') }}</option>
                            <option value="imdb" @selected($filterView->scalarState('rating_source') === 'imdb')>{{ __('catalog.catalog.advanced_filter_values.rating_imdb') }}</option>
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
                    <select wire:model="filters.video" name="video" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 sm:w-48">
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
                                <input type="checkbox" wire:model="filters.qualities" name="quality[]" value="{{ $quality }}" class="h-5 w-5 accent-emerald-700">
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

        <div class="flex flex-col gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:items-center">
            <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
                <x-ui.icon name="fa-solid fa-filter" />
                <span>{{ __('catalog.catalog.exact_filters.show_results') }}</span>
            </button>
            <button type="button" data-catalog-filter-cancel class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                <x-ui.icon name="fa-solid fa-xmark" />
                <span>{{ __('catalog.catalog.exact_filters.cancel') }}</span>
            </button>
            <a href="{{ route('titles.index') }}" rel="nofollow" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700">
                <x-ui.icon name="fa-solid fa-rotate-left" />
                <span>{{ __('catalog.catalog.exact_filters.reset') }}</span>
            </a>
        </div>
    </form>
</details>
