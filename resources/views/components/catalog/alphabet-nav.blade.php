@props(['filterView'])

<nav {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1.5']) }} aria-label="Алфавитный переход по названиям">
    <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-400">Алфавит:</span>
    @foreach ($filterView->alphabet as $letter)
        <a href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}" @class([
            'inline-flex min-h-9 min-w-9 items-center justify-center rounded-full px-2 text-xs font-bold ring-1 transition',
            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $filterView->isActiveLetter($letter),
            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveLetter($letter),
        ])>{{ $letter === 'latin' ? 'A-Z' : $letter }}</a>
    @endforeach
</nav>
