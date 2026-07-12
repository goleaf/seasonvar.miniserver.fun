@props([
    'search',
    'insufficientSearch',
    'filterView',
    'titleContext',
    'year',
    'invalidYear',
])

<x-ui.panel {{ $attributes->merge(['class' => 'col-span-full border-dashed']) }}>
    <div class="flex flex-col gap-4">
        <div>
            <div class="inline-flex items-center gap-2 text-base font-bold text-slate-700">
                <i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i>
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
        <div class="flex flex-wrap gap-2">
            @if ($search !== '')
                <a href="{{ route('titles.index', $filterView->withoutSearchQuery) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i>
                    <span>Очистить поиск</span>
                </a>
            @endif
            @if ($filterView->hasActiveFilters() || $titleContext !== null || $year !== null || $invalidYear)
                <a href="{{ route('titles.index', $filterView->withoutFiltersQuery) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
                    <span>Сбросить фильтры</span>
                </a>
            @endif
            <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                <span>Показать весь каталог</span>
            </a>
        </div>
    </div>
</x-ui.panel>
