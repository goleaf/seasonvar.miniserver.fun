@props([
    'search',
    'titleContext',
    'year',
    'requestedYear',
    'invalidYear',
    'selectedTaxonomies',
    'excludedTaxonomies',
    'invalidFilterSlugs',
    'filterView',
    'total',
])

@if ($search !== '' || $selectedTaxonomies->isNotEmpty() || $excludedTaxonomies->isNotEmpty() || $filterView->advancedFilterChips() !== [] || $titleContext !== null || $year !== null || $invalidYear || $invalidFilterSlugs !== [])
    <div {{ $attributes->merge(['class' => 'mt-3 space-y-3 text-sm']) }}>
        <div class="flex flex-wrap items-center gap-2">
            @if ($search !== '')
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutSearchQuery)" active icon="fa-solid fa-magnifying-glass">Поиск: {{ $search }} · очистить</x-ui.taxonomy-chip>
            @endif
            @if ($titleContext !== null)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutTitleQuery)" active icon="fa-solid fa-clapperboard">Сериал: {{ $titleContext->title }} · убрать</x-ui.taxonomy-chip>
            @endif
            @if ($year !== null)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" active icon="fa-solid fa-calendar-days">Год: {{ $year }} · убрать</x-ui.taxonomy-chip>
            @endif
            @if ($invalidYear)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" active icon="fa-solid fa-calendar-days">Год: {{ $requestedYear }} не найден · убрать</x-ui.taxonomy-chip>
            @endif
            @foreach ($selectedTaxonomies as $filterType => $taxonomies)
                @foreach ($taxonomies as $taxonomy)
                    <x-ui.taxonomy-chip :href="route('titles.index', $filterView->filterQuery($filterType, $taxonomy->slug))" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                @endforeach
            @endforeach
            @foreach ($excludedTaxonomies as $filterType => $taxonomies)
                @foreach ($taxonomies as $taxonomy)
                    <x-ui.taxonomy-chip :href="route('titles.index', $filterView->exclusionQuery($filterType, $taxonomy->slug))" active icon="fa-solid fa-minus">Без {{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                @endforeach
            @endforeach
            @foreach ($filterView->advancedFilterChips() as $chip)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutCatalogState($chip['key']))" active icon="fa-solid fa-sliders">
                    {{ $chip['label'] }}: {{ $chip['value'] }} · убрать
                </x-ui.taxonomy-chip>
            @endforeach
            @foreach ($invalidFilterSlugs as $filterType => $slug)
                <x-ui.taxonomy-chip :href="route('titles.index', $filterView->invalidFilterQuery($filterType))" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $slug }} не найден · убрать</x-ui.taxonomy-chip>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-3 text-slate-500">
            <span><i class="fa-solid fa-diagram-project text-slate-400" aria-hidden="true"></i> Активных связей: {{ $selectedTaxonomies->sum(fn ($taxonomies) => $taxonomies->count()) + $excludedTaxonomies->sum(fn ($taxonomies) => $taxonomies->count()) }}</span>
            @if ($invalidFilterSlugs !== [])
                <span><i class="fa-solid fa-triangle-exclamation text-amber-600" aria-hidden="true"></i> Ошибочных фильтров: {{ count($invalidFilterSlugs) }}</span>
            @endif
            @if ($invalidYear)
                <span><i class="fa-solid fa-calendar-xmark text-amber-600" aria-hidden="true"></i> Ошибочный год: {{ $requestedYear }}</span>
            @endif
            @if ($year !== null)
                <span><i class="fa-solid fa-calendar-days text-slate-400" aria-hidden="true"></i> Год: {{ $year }}</span>
            @endif
            @if ($titleContext !== null)
                <span><i class="fa-solid fa-clapperboard text-slate-400" aria-hidden="true"></i> Сериал: {{ $titleContext->title }}</span>
            @endif
            <span><i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i> Найдено сейчас: {{ $total }}</span>
            <a href="{{ route('titles.index') }}" class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-600">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                <span>Сбросить все</span>
            </a>
        </div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'mt-3 text-sm text-slate-500']) }}>
        <i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i>
        Найдено: {{ $total }}
    </div>
@endif
