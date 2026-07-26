@props([
    'filterView',
    'search',
    'titleContext',
    'invalidYear',
    'requestedYear',
    'selectedTaxonomies',
    'excludedTaxonomies',
])

<section
    data-catalog-active-filters
    data-active-filter-count="{{ $filterView->activeFilterCount() }}"
    aria-label="{{ __('catalog.catalog.active_filters_title') }}"
    class="border-b border-slate-200 py-4"
>
    <div class="flex flex-wrap items-center gap-2">
        @if ($search !== '')
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutSearchQuery)" rel="nofollow" wire:click.prevent="clearSearch" active icon="fa-solid fa-magnifying-glass">{{ __('catalog.catalog.chips.search', ['query' => $search]) }}</x-ui.taxonomy-chip>
        @endif
        @if ($titleContext !== null)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutTitleQuery)" rel="nofollow" wire:click.prevent="clearTitleContext" active icon="fa-solid fa-clapperboard">{{ __('catalog.catalog.chips.title', ['title' => $titleContext->display_title]) }}</x-ui.taxonomy-chip>
        @endif
        @foreach ($filterView->selectedYears() as $selectedYear)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->yearQuery($selectedYear))" rel="nofollow" wire:click.prevent="removeYear({{ $selectedYear }})" active icon="fa-solid fa-calendar-days">{{ __('catalog.catalog.chips.year', ['year' => $selectedYear]) }}</x-ui.taxonomy-chip>
        @endforeach
        @if ($invalidYear)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" rel="nofollow" wire:click.prevent="resetGroup('year')" active icon="fa-solid fa-calendar-days">{{ __('catalog.catalog.chips.invalid_year', ['year' => $requestedYear]) }}</x-ui.taxonomy-chip>
        @endif
        @foreach ($selectedTaxonomies as $filterType => $taxonomies)
            @foreach ($taxonomies as $taxonomy)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->filterQuery($filterType, $taxonomy->slug))" rel="nofollow" wire:click.prevent="removeTaxonomy('{{ $filterType }}', '{{ $taxonomy->slug }}')" :icon="$filterView->icon($filterType)" active>{{ $taxonomy->name }} · ×</x-ui.taxonomy-chip>
            @endforeach
        @endforeach
        @foreach ($excludedTaxonomies as $filterType => $taxonomies)
            @foreach ($taxonomies as $taxonomy)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->exclusionQuery($filterType, $taxonomy->slug))" rel="nofollow" wire:click.prevent="removeExcluded('{{ $filterType }}', '{{ $taxonomy->slug }}')" active icon="fa-solid fa-minus">{{ $filterView->excludedTaxonomyLabel($filterType, $taxonomy) }} · ×</x-ui.taxonomy-chip>
            @endforeach
        @endforeach
        @foreach ($filterView->summaryFilterChips() as $chip)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutCatalogStates($chip['keys']))" rel="nofollow" :icon="$chip['icon']" active>{{ $chip['label'] }} · ×</x-ui.taxonomy-chip>
        @endforeach
        @foreach ($filterView->listState('publication_type') as $publicationType)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('publication_type', $publicationType))" rel="nofollow" wire:click.prevent="removeChoice('publication_type', '{{ $publicationType }}')" active icon="fa-solid fa-clapperboard">{{ $filterView->publicationTypeLabel($publicationType) }} · ×</x-ui.taxonomy-chip>
        @endforeach
        @foreach ($filterView->listState('subtitles') as $subtitleValue)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('subtitles', $subtitleValue))" rel="nofollow" wire:click.prevent="removeChoice('subtitles', '{{ $subtitleValue }}')" active icon="fa-solid fa-closed-captioning">{{ $filterView->subtitleLabel($subtitleValue) }} · ×</x-ui.taxonomy-chip>
        @endforeach
        @foreach ($filterView->listState('quality') as $quality)
            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('quality', $quality))" rel="nofollow" wire:click.prevent="removeChoice('quality', '{{ $quality }}')" active icon="fa-solid fa-display">{{ $quality }} · ×</x-ui.taxonomy-chip>
        @endforeach
        @foreach ($filterView->advancedFilterChips() as $chip)
            @if (! in_array($chip['key'], ['year_from', 'year_to', 'rating_source', 'rating_min'], true))
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutCatalogState($chip['key']))" rel="nofollow" wire:click.prevent="resetAdvanced('{{ $chip['key'] }}')" active icon="fa-solid fa-sliders">{{ $chip['label'] }} — {{ $chip['value'] }} · ×</x-ui.taxonomy-chip>
            @endif
        @endforeach

        @if ($search !== '' || $filterView->hasActiveFilters() || $titleContext !== null || $invalidYear)
            <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <x-ui.icon name="fa-solid fa-rotate-left" />
                <span>{{ __('catalog.catalog.reset_all') }}</span>
            </a>
        @endif
    </div>
</section>
