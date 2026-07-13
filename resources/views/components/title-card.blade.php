<article data-catalog-card {{ $attributes->merge(['class' => 'catalog-card group relative grid min-w-0 grid-cols-[5.5rem_minmax(0,1fr)] overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel transition sm:flex sm:h-full sm:flex-col motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-panel-hover']) }}>
    <div class="relative bg-slate-50 sm:w-full">
        <x-title-poster :title="$title" class="aspect-[2/3] w-full rounded-none border-0" image-class="h-full w-full object-contain" />
    </div>
    <div class="flex min-w-0 flex-1 flex-col p-3 sm:p-4">
        <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
            <span class="inline-flex min-w-0 items-center gap-1">
                <x-ui.icon name="fa-solid fa-tv text-[0.85em] text-slate-400" />
                <span>{{ $title->type === 'serial' ? __('catalog.title.series_type') : $title->type }}</span>
            </span>
            @if ($title->year)
                <span class="inline-flex items-center gap-1">
                    <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em] text-slate-400" />
                    <span>{{ $title->year }}</span>
                </span>
            @endif
        </div>
        <h3 class="mt-2 text-base font-bold leading-6">
            <a href="{{ route('titles.show', $title) }}" class="break-words text-slate-800 after:absolute after:inset-0 hover:text-emerald-700">
                {{ $title->title }}
            </a>
        </h3>
        <div class="mt-3 flex flex-wrap gap-1.5 text-xs font-bold">
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                <x-ui.icon name="fa-solid fa-layer-group" />
                <span>{{ trans_choice('catalog.counts.seasons', $seasonsCount) }}</span>
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                <x-ui.icon name="fa-solid fa-circle-play" />
                <span>{{ trans_choice('catalog.counts.episodes', $episodesCount) }}</span>
            </span>
            @if ($mediaCount > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                    <x-ui.icon name="fa-solid fa-file-video" />
                    <span>{{ trans_choice('catalog.counts.videos', $mediaCount) }}</span>
                </span>
            @endif
        </div>
        <div class="relative z-10 mt-3 flex flex-wrap gap-1.5">
            @foreach ($cardRelations as $taxonomy)
                <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
            @endforeach
        </div>
    </div>
</article>
