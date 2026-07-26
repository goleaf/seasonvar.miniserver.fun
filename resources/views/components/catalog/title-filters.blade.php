<div class="mt-4 space-y-4" wire:loading.class="opacity-60">
    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-emerald-50 text-sm font-bold text-emerald-700">
        <span class="flex min-h-11 items-center justify-center gap-2 px-3 py-2">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
            <span>{{ __('catalog.catalog.filters.updating') }}</span>
        </span>
    </div>
    <div data-catalog-filter-groups class="space-y-3">
        <section class="border-b border-slate-200 pb-3">
            <div class="mb-2 flex items-center justify-between gap-2">
                <div class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <x-ui.icon name="fa-solid fa-calendar-days text-slate-400" />
                    <span>{{ __('catalog.catalog.filters.years') }}</span>
                </div>
                @if ($filterView->selectedYears() !== [])
                    <a href="{{ route('titles.index', $filterView->yearQuery(null)) }}" rel="nofollow" wire:click.prevent="resetGroup('year')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-800">
                        <x-ui.icon name="fa-solid fa-rotate-left" />
                        <span>{{ __('catalog.catalog.filters.reset') }}</span>
                    </a>
                @endif
            </div>
            <div class="space-y-1">
                @forelse ($yearBuckets as $bucket)
                    <label @class([
                        'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                        'bg-emerald-50 font-bold text-emerald-700' => $filterView->isActiveYear($bucket),
                        'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' => ! $filterView->isActiveYear($bucket),
                    ])>
                        <span class="inline-flex min-w-0 items-center gap-2">
                            @if ($routeYear === $filterView->bucketYear($bucket))
                                <input type="checkbox" checked wire:click="removeYear({{ $filterView->bucketYear($bucket) }})" name="year[]" value="{{ $filterView->bucketYear($bucket) }}" class="h-5 w-5 shrink-0 accent-emerald-700">
                            @else
                                <input type="checkbox" wire:model.live="filters.years" wire:replace.self name="year[]" value="{{ $filterView->bucketYear($bucket) }}" class="h-5 w-5 shrink-0 accent-emerald-700">
                            @endif
                            <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em] text-slate-400" />
                            <span class="min-w-0 break-words">{{ $filterView->bucketYear($bucket) }}</span>
                        </span>
                        <span class="shrink-0 text-xs font-bold tabular-nums">{{ $bucket->context_titles_count }}</span>
                    </label>
                @empty
                    <p class="text-sm text-slate-500">{{ __('catalog.catalog.filters.years_empty') }}</p>
                @endforelse
            </div>
        </section>

        <section class="border-b border-slate-200 pb-3">
            <div class="mb-2 flex items-center justify-between gap-2">
                <div class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <x-ui.icon name="fa-solid fa-clapperboard text-slate-400" />
                    <span>{{ __('catalog.catalog.filters.publication_type') }}</span>
                </div>
                @if ($filterView->listState('publication_type') !== [])
                    <a href="{{ route('titles.index', $filterView->withoutCatalogState('publication_type')) }}" rel="nofollow" wire:click.prevent="resetGroup('publication_type')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-800">
                        <x-ui.icon name="fa-solid fa-rotate-left" />
                        <span>{{ __('catalog.catalog.filters.reset') }}</span>
                    </a>
                @endif
            </div>
            <div class="space-y-1">
                @forelse ($publicationTypeOptions as $option)
                    <label @class([
                        'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                        'bg-emerald-50 font-bold text-emerald-700' => in_array($option->value, $filterView->listState('publication_type'), true),
                        'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' => ! in_array($option->value, $filterView->listState('publication_type'), true),
                    ])>
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <input type="checkbox" wire:model.live="filters.publicationTypes" wire:replace.self name="publication_type[]" value="{{ $option->value }}" class="h-5 w-5 shrink-0 accent-emerald-700">
                            <span class="min-w-0 break-words">{{ $option->label }}</span>
                        </span>
                        <span class="shrink-0 text-xs font-bold tabular-nums">{{ $option->context_titles_count }}</span>
                    </label>
                @empty
                    <p class="text-sm text-slate-500">{{ __('catalog.catalog.filters.publication_type_empty') }}</p>
                @endforelse
            </div>
        </section>

        <section class="border-b border-slate-200 pb-3">
            <div class="mb-2 flex items-center justify-between gap-2">
                <div class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <x-ui.icon name="fa-solid fa-closed-captioning text-slate-400" />
                    <span>{{ __('catalog.catalog.filters.subtitles') }}</span>
                </div>
                @if ($filterView->listState('subtitles') !== [])
                    <a href="{{ route('titles.index', $filterView->withoutCatalogState('subtitles')) }}" rel="nofollow" wire:click.prevent="resetGroup('subtitles')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-800">
                        <x-ui.icon name="fa-solid fa-rotate-left" />
                        <span>{{ __('catalog.catalog.filters.reset') }}</span>
                    </a>
                @endif
            </div>
            <div class="space-y-1">
                @foreach ($subtitleOptions as $option)
                    <label @class([
                        'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                        'bg-emerald-50 font-bold text-emerald-700' => in_array($option->value, $filterView->listState('subtitles'), true),
                        'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' => ! in_array($option->value, $filterView->listState('subtitles'), true),
                    ])>
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <input type="checkbox" wire:model.live="filters.subtitles" wire:replace.self name="subtitles[]" value="{{ $option->value }}" class="h-5 w-5 shrink-0 accent-emerald-700">
                            <span class="min-w-0 break-words">{{ $option->label }}</span>
                        </span>
                        <span class="shrink-0 text-xs font-bold tabular-nums">{{ $option->context_titles_count }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        @foreach ($filterView->typeLabels as $filterType => $label)
            <details
                data-catalog-filter-group
                @if ($filterView->isFilterGroupExpanded($filterType)) open @endif
                class="group border-b border-slate-200 pb-3"
            >
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 rounded-lg px-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                    <span class="inline-flex min-w-0 items-center gap-2">
                        <x-ui.icon name="{{ $filterView->icon($filterType) }} text-slate-400" />
                        <span>{{ $label }}</span>
                    </span>
                    <span class="inline-flex shrink-0 items-center gap-2">
                        @if ($selectedTaxonomies->get($filterType, collect())->isNotEmpty())
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">{{ $selectedTaxonomies->get($filterType)->count() }}</span>
                        @endif
                        <x-ui.icon name="fa-solid fa-chevron-down text-xs text-slate-600 transition group-open:rotate-180" />
                    </span>
                </summary>
                <div class="pt-2">
                    @if ($selectedTaxonomies->get($filterType, collect())->isNotEmpty())
                        <a href="{{ route('titles.index', $filterView->filterQuery($filterType, null)) }}" rel="nofollow" wire:click.prevent="resetGroup('{{ $filterType }}')" class="mb-2 inline-flex min-h-11 items-center gap-1 px-2 text-xs font-bold text-emerald-700 hover:text-emerald-800">
                            <x-ui.icon name="fa-solid fa-rotate-left" />
                            <span>{{ __('catalog.catalog.filters.reset') }}</span>
                        </a>
                    @endif
                @if (in_array($filterType, ['actor', 'director'], true))
                    <div class="relative mb-2">
                        <label class="sr-only" for="catalog-filter-search-{{ $filterType }}">{{ __('catalog.catalog.filters.search_group', ['group' => $label]) }}</label>
                        <div data-focus-frame class="flex min-h-11 items-center gap-2 rounded-control border border-transparent bg-slate-50 px-3 text-sm text-slate-500">
                            <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                            <input
                                id="catalog-filter-search-{{ $filterType }}"
                                type="search"
                                autocomplete="off"
                                maxlength="80"
                                placeholder="{{ __('catalog.catalog.filters.person_placeholder') }}"
                                wire:model.live.debounce.300ms="optionSearch.{{ $filterType }}"
                                class="min-h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-700 placeholder:text-slate-500 focus:outline-none"
                            >
                            <span wire:loading.delay wire:target="optionSearch.{{ $filterType }}" role="status" aria-live="polite" class="shrink-0 text-emerald-700">
                                <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                                <span class="sr-only">{{ __('catalog.catalog.filters.people_searching') }}</span>
                            </span>
                        </div>
                    </div>
                @elseif ($filterTaxonomies->get($filterType, collect())->count() > 8)
                    <label class="sr-only" for="catalog-filter-search-{{ $filterType }}">{{ __('catalog.catalog.filters.search_group', ['group' => $label]) }}</label>
                    <div data-focus-frame class="mb-2 flex min-h-11 items-center gap-2 rounded-control border border-transparent bg-slate-50 px-3 text-sm text-slate-500">
                        <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                        <input
                            id="catalog-filter-search-{{ $filterType }}"
                            type="search"
                            autocomplete="off"
                            placeholder="{{ __('catalog.catalog.filters.group_placeholder') }}"
                            class="min-h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-700 placeholder:text-slate-500 focus:outline-none"
                            data-catalog-filter-search
                        >
                    </div>
                @endif
                <div class="space-y-1" data-catalog-filter-options>
                    @forelse ($filterTaxonomies->get($filterType, collect()) as $taxonomy)
                        <label data-catalog-filter-option data-catalog-filter-text="{{ mb_strtolower($taxonomy->name.' '.$taxonomy->slug, 'UTF-8') }}" @class([
                            'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                            'bg-emerald-50 font-bold text-emerald-700' => $filterView->isActiveTaxonomy($filterType, $taxonomy),
                            'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' => ! $filterView->isActiveTaxonomy($filterType, $taxonomy),
                        ])>
                            <span class="inline-flex min-w-0 items-center gap-2">
                                @if ($routeFilterType === $filterType && $routeTaxonomy === $taxonomy->slug)
                                    <input type="checkbox" checked wire:click="removeTaxonomy('{{ $filterType }}', '{{ $taxonomy->slug }}')" name="{{ $filterType }}[]" value="{{ $taxonomy->slug }}" class="h-5 w-5 shrink-0 accent-emerald-700">
                                @else
                                    <input type="checkbox" wire:model.live="filters.{{ $filterType }}" wire:replace.self name="{{ $filterType }}[]" value="{{ $taxonomy->slug }}" class="h-5 w-5 shrink-0 accent-emerald-700">
                                @endif
                                <x-ui.icon name="{{ $filterView->icon($filterType) }} text-[0.85em] text-slate-400" />
                                <span class="min-w-0 break-words">{{ $taxonomy->name }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-bold tabular-nums">{{ $taxonomy->context_titles_count }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500">{{ in_array($filterType, ['actor', 'director'], true) && mb_strlen($optionSearch[$filterType] ?? '') >= 2 ? __('catalog.catalog.filters.nothing_found') : __('catalog.catalog.filters.no_data') }}</p>
                    @endforelse
                </div>
                <p class="hidden px-3 py-2 text-sm text-slate-500" data-catalog-filter-empty>{{ __('catalog.catalog.filters.group_empty') }}</p>
                </div>
            </details>
        @endforeach
    </div>
</div>
