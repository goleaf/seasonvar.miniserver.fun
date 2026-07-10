<article class="group flex h-full flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60 transition hover:border-emerald-300 hover:shadow-md hover:shadow-emerald-100">
    <a href="{{ route('titles.show', $title) }}" class="block bg-white">
        <x-title-poster :title="$title" class="aspect-[2/3] rounded-none bg-white" image-class="h-full w-full object-contain" empty-class="flex h-full items-center justify-center text-sm text-slate-400" />
    </a>

    <div class="flex min-w-0 flex-1 flex-col p-3 sm:p-4">
        <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-slate-500">
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
            <a href="{{ route('titles.show', $title) }}" class="break-words text-slate-700 hover:text-emerald-700">{{ $title->title }}</a>
        </h3>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                <i class="fa-solid fa-layer-group shrink-0 text-[0.85em]" aria-hidden="true"></i>
                <span>{{ $seasonsCount }} сезон(ов)</span>
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                <i class="fa-solid fa-circle-play shrink-0 text-[0.85em]" aria-hidden="true"></i>
                <span>{{ $episodesCount }} серий</span>
            </span>
            @if ($mediaCount > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700 ring-1 ring-amber-100">
                    <i class="fa-solid fa-file-video shrink-0 text-[0.85em]" aria-hidden="true"></i>
                    <span>{{ $mediaCount }} видео</span>
                </span>
            @endif
        </div>
        <div class="mt-3 flex flex-wrap gap-1">
            @foreach ($cardRelations as $taxonomy)
                <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
            @endforeach
        </div>
    </div>
</article>
