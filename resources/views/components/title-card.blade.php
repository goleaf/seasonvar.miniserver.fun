<article data-catalog-card class="catalog-card group relative grid min-w-0 grid-cols-[5.5rem_minmax(0,1fr)] overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel transition sm:flex sm:h-full sm:flex-col motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-panel-hover">
    <div class="relative bg-slate-50 sm:w-full">
        <x-title-poster :title="$title" class="aspect-[2/3] w-full rounded-none border-0" image-class="h-full w-full object-contain" />
    </div>
    <div class="flex min-w-0 flex-1 flex-col p-3 sm:p-4">
        <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
            <span class="inline-flex min-w-0 items-center gap-1">
                <i class="fa-solid fa-tv shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                <span>{{ $title->type === 'serial' ? 'сериал' : $title->type }}</span>
            </span>
            @if ($title->year)
                <span class="inline-flex items-center gap-1">
                    <i class="fa-solid fa-calendar-days shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
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
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <span>{{ $seasonsCount }} сезон(ов)</span>
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                <span>{{ $episodesCount }} серий</span>
            </span>
            @if ($mediaCount > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700 ring-1 ring-amber-100">
                    <i class="fa-solid fa-file-video" aria-hidden="true"></i>
                    <span>{{ $mediaCount }} видео</span>
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
