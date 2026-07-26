@props([
    'filterView',
    'perPage',
])

<div data-catalog-output-controls class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
    <div class="flex min-w-0 flex-1 flex-wrap gap-2">
        <a
            data-catalog-mobile-filter-trigger
            href="#catalog-filters"
            class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-emerald-300 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 lg:hidden"
        >
            <x-ui.icon name="fa-solid fa-sliders text-emerald-700" />
            <span>{{ __('catalog.catalog.filters_button', ['count' => $filterView->activeFilterCount()]) }}</span>
        </a>

        <details data-catalog-sort-menu class="group relative">
            <summary class="inline-flex min-h-11 max-w-full cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:border-emerald-300 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <x-ui.icon name="{{ $filterView->sortIcon($filterView->sort) }} text-emerald-700" />
                <span data-catalog-sort-current class="min-w-0 break-words">{{ $filterView->currentSortLabel() }}</span>
                <x-ui.icon name="fa-solid fa-chevron-down text-xs text-slate-600 transition group-open:rotate-180" />
            </summary>
            <div class="mt-2 w-full rounded-lg border border-slate-200 bg-white p-2 shadow-elevated sm:absolute sm:left-0 sm:z-30 sm:w-72">
                <div data-catalog-primary-sorts class="space-y-1">
                    @foreach ($filterView->primarySortOptions() as $sortKey => $sortLabel)
                        <a
                            data-catalog-sort-option
                            data-catalog-sort-value="{{ $sortKey }}"
                            href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}"
                            rel="nofollow"
                            wire:click.prevent="sortBy('{{ $sortKey }}')"
                            @if ($filterView->isActiveSort($sortKey)) aria-current="true" @endif
                            @class([
                                'flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold',
                                'bg-emerald-50 text-emerald-800' => $filterView->isActiveSort($sortKey),
                                'text-slate-700 hover:bg-slate-100' => ! $filterView->isActiveSort($sortKey),
                            ])
                        >
                            <x-ui.icon name="{{ $filterView->sortIcon($sortKey) }}" />
                            <span>{{ $sortLabel }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="my-2 border-t border-slate-200"></div>
                <div class="px-3 pb-1 text-xs font-semibold text-slate-600">{{ __('catalog.catalog.other_sort_options') }}</div>
                <div data-catalog-secondary-sorts class="space-y-1">
                    @foreach ($filterView->secondarySortOptions() as $sortKey => $sortLabel)
                        <a
                            data-catalog-sort-option
                            data-catalog-sort-value="{{ $sortKey }}"
                            href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}"
                            rel="nofollow"
                            wire:click.prevent="sortBy('{{ $sortKey }}')"
                            @if ($filterView->isActiveSort($sortKey)) aria-current="true" @endif
                            @class([
                                'flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold',
                                'bg-emerald-50 text-emerald-800' => $filterView->isActiveSort($sortKey),
                                'text-slate-700 hover:bg-slate-100' => ! $filterView->isActiveSort($sortKey),
                            ])
                        >
                            <x-ui.icon name="{{ $filterView->sortIcon($sortKey) }}" />
                            <span>{{ $sortLabel }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </details>

        <details data-catalog-alphabet-menu class="group relative hidden lg:block">
            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-emerald-300 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <x-ui.icon name="fa-solid fa-arrow-down-a-z" />
                <span>{{ __('catalog.catalog.alphabet_menu') }}</span>
                @if ($filterView->activeLetter)
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">{{ $filterView->activeLetter }}</span>
                @endif
                <x-ui.icon name="fa-solid fa-chevron-down text-xs text-slate-600 transition group-open:rotate-180" />
            </summary>
            <div class="mt-2 rounded-lg border border-slate-200 bg-white p-3 shadow-elevated lg:absolute lg:left-0 lg:z-20 lg:w-[34rem]">
                <x-catalog.alphabet-filter :filter-view="$filterView" />
            </div>
        </details>
    </div>

    <div class="hidden items-center gap-2 lg:flex">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1" aria-label="{{ __('catalog.catalog.view_label') }}">
            @foreach ($filterView->viewOptions() as $viewOption)
                <a
                    data-catalog-view-option="{{ $viewOption['value'] }}"
                    href="{{ route('titles.index', $filterView->viewQuery($viewOption['value'])) }}"
                    rel="nofollow"
                    wire:click.prevent="setView('{{ $viewOption['value'] }}')"
                    @if ($filterView->isActiveView($viewOption['value'])) aria-current="true" @endif
                    @class([
                        'inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 text-sm',
                        'bg-emerald-50 text-emerald-800' => $filterView->isActiveView($viewOption['value']),
                        'text-slate-600 hover:bg-slate-100' => ! $filterView->isActiveView($viewOption['value']),
                    ])
                    aria-label="{{ $viewOption['label'] }}"
                    title="{{ $viewOption['label'] }}"
                >
                    <x-ui.icon :name="$viewOption['icon']" />
                </a>
            @endforeach
        </div>

        <details data-catalog-page-size-menu class="group relative">
            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-emerald-300 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <span>{{ __('catalog.catalog.page_size_short', ['count' => $perPage]) }}</span>
                <x-ui.icon name="fa-solid fa-chevron-down text-xs text-slate-600 transition group-open:rotate-180" />
            </summary>
            <div class="absolute right-0 z-20 mt-2 w-48 rounded-lg border border-slate-200 bg-white p-2 shadow-elevated">
                @foreach ([24, 48, 96] as $pageSize)
                    <a
                        data-catalog-page-size-option
                        href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}"
                        rel="nofollow"
                        wire:click.prevent="setPerPage({{ $pageSize }})"
                        @if ($perPage === $pageSize) aria-current="true" @endif
                        @class([
                            'flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold',
                            'bg-emerald-50 text-emerald-800' => $perPage === $pageSize,
                            'text-slate-700 hover:bg-slate-100' => $perPage !== $pageSize,
                        ])
                    >{{ __('catalog.catalog.page_size_short', ['count' => $pageSize]) }}</a>
                @endforeach
            </div>
        </details>
    </div>
</div>
