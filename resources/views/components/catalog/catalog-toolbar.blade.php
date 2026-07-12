@props([
    'titles',
    'sort',
    'view',
    'perPage',
    'filterView',
])

<div {{ $attributes }}>
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-control border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Найдено</div>
            <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                <i class="fa-solid fa-magnifying-glass text-emerald-700" aria-hidden="true"></i>
                <span>{{ $titles->total() }}</span>
            </div>
        </div>
        <div class="rounded-control border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-bold uppercase tracking-wide text-slate-400">На странице</div>
            <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                <i class="fa-solid fa-table-cells-large text-sky-700" aria-hidden="true"></i>
                <span>{{ $titles->count() }}</span>
            </div>
        </div>
        <div class="rounded-control border border-slate-200 bg-slate-50 p-3">
            <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Сортировка</div>
            <div class="mt-1 inline-flex min-w-0 items-center gap-2 text-lg font-black text-slate-700">
                <i class="{{ $filterView->sortIcon($sort) }} shrink-0 text-amber-700" aria-hidden="true"></i>
                <span class="min-w-0 break-words">{{ $filterView->sortLabel($sort) }}</span>
            </div>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($filterView->sortLabels as $sortKey => $sortLabel)
            <a href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}" @class([
                'inline-flex min-h-8 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ring-1',
                'bg-emerald-50 text-emerald-700 ring-emerald-100' => $filterView->isActiveSort($sortKey),
                'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveSort($sortKey),
            ])>
                <i class="{{ $filterView->sortIcon($sortKey) }} shrink-0" aria-hidden="true"></i>
                <span class="min-w-0 break-words">{{ $sortLabel }}</span>
            </a>
        @endforeach
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-bold">
        <span class="text-slate-400">Вид:</span>
        @foreach (['grid' => 'Плитка', 'list' => 'Список'] as $viewKey => $viewLabel)
            <a href="{{ route('titles.index', $filterView->viewQuery($viewKey)) }}" @class([
                'inline-flex min-h-8 items-center rounded-full px-2.5 py-1 ring-1',
                'bg-emerald-50 text-emerald-700 ring-emerald-100' => $view === $viewKey,
                'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => $view !== $viewKey,
            ])>{{ $viewLabel }}</a>
        @endforeach
        <span class="ml-2 text-slate-400">На странице:</span>
        @foreach ([24, 48, 96] as $pageSize)
            <a href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}" @class([
                'inline-flex min-h-8 items-center rounded-full px-2.5 py-1 ring-1',
                'bg-emerald-50 text-emerald-700 ring-emerald-100' => $perPage === $pageSize,
                'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => $perPage !== $pageSize,
            ])>{{ $pageSize }}</a>
        @endforeach
    </div>
</div>
