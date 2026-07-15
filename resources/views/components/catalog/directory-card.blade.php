@props(['item', 'upcoming' => false])

<a
    href="{{ $item->detail_url }}"
    class="group flex min-h-11 min-w-0 items-center gap-4 bg-white px-4 py-3 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-emerald-200 sm:px-5"
>
    <span class="min-w-0 flex-1">
        <span class="block break-words text-base font-extrabold leading-6 text-slate-800 group-hover:text-emerald-800">
            {{ $item->name }}
        </span>
        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-slate-500">
            <x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />
            <span>{{ trans_choice('catalog.counts.results', (int) $item->published_titles_count) }}</span>
            @if ($upcoming)
                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800">{{ __('catalog.directories.upcoming') }}</span>
            @endif
        </span>
    </span>

    <x-ui.icon name="fa-solid fa-arrow-right shrink-0 text-xs text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-emerald-700" />
</a>
