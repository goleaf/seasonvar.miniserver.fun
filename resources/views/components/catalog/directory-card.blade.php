@props(['item', 'directory'])

<a
    href="{{ $item->detail_url }}"
    class="group flex min-h-28 min-w-0 flex-col justify-between rounded-panel bg-white p-4 shadow-sm shadow-slate-200/70 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
>
    <span class="flex min-w-0 items-start justify-between gap-3">
        <span class="min-w-0 break-words text-base font-extrabold leading-6 text-slate-800 group-hover:text-emerald-800">
            {{ $item->name }}
        </span>
        <x-ui.icon name="fa-solid fa-arrow-right shrink-0 text-xs text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-emerald-700" />
    </span>

    <span class="mt-4 flex items-center gap-2 text-sm font-semibold text-slate-500">
        <x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />
        <span>{{ trans_choice('catalog.counts.results', (int) $item->published_titles_count) }}</span>
        @if ($directory->isYear() && (int) $item->year > now()->year)
            <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800">{{ __('catalog.directories.upcoming') }}</span>
        @endif
    </span>
</a>
