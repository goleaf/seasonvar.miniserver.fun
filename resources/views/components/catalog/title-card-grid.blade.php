<x-ui.poster-card
    :src="$title->poster_url"
    alt="Постер {{ $title->display_title }}"
    layout="grid"
    data-catalog-card
    {{ $attributes }}
>
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
            {{ $title->display_title }}
        </a>
    </h3>
    @if ($title->display_original_title)
        <p class="mt-1 break-words text-sm font-semibold leading-5 text-slate-500">{{ $title->display_original_title }}</p>
    @endif

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

    @if ($cardRelations->isNotEmpty())
        <div class="relative z-10 mt-3 flex flex-wrap gap-1.5">
            @foreach ($cardRelations as $taxonomy)
                <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
            @endforeach
        </div>
    @endif

    @include('components.catalog.title-card-personal-state')
</x-ui.poster-card>
