<x-ui.poster-card
    :src="$title->poster_url"
    alt="Постер {{ $title->display_title }}"
    layout="recommendation"
    data-catalog-card
    {{ $attributes }}
>
    <div class="flex min-w-0 items-start justify-between gap-3">
        <div class="min-w-0">
            @if ($rank)
                <span class="text-xs font-bold uppercase tracking-wide text-emerald-700" data-recommendation-rank="{{ $rank }}">
                    Совет № {{ $rank }}
                </span>
            @endif

            <h3 class="mt-1 text-base font-bold leading-5 sm:text-lg sm:leading-6">
                <a href="{{ route('titles.show', $title) }}" class="break-words text-slate-800 after:absolute after:inset-0 hover:text-emerald-700">
                    {{ $title->display_title }}
                </a>
            </h3>

            @if ($title->display_original_title)
                <p class="mt-1 break-words text-sm font-semibold text-slate-500">{{ $title->display_original_title }}</p>
            @endif
        </div>

        <div class="hidden shrink-0 items-center gap-2 text-xs font-semibold text-slate-500 sm:flex">
            <span>{{ $title->type === 'serial' ? __('catalog.title.series_type') : $title->type }}</span>
            @if ($title->year)
                <span aria-hidden="true">·</span>
                <span>{{ $title->year }}</span>
            @endif
        </div>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-slate-500 sm:hidden">
        <span>{{ $title->type === 'serial' ? __('catalog.title.series_type') : $title->type }}</span>
        @if ($title->year)
            <span aria-hidden="true">·</span>
            <span>{{ $title->year }}</span>
        @endif
    </div>

    @if ($reasonLabels !== [])
        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach (array_slice($reasonLabels, 0, 4) as $reasonLabel)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">
                    <x-ui.icon name="fa-solid fa-check text-[0.8em]" />
                    <span>{{ $reasonLabel }}</span>
                </span>
            @endforeach
        </div>
    @endif

    @if ($showDescription && $title->description)
        <p class="mt-2 break-words text-sm leading-5 text-slate-600">{{ $title->description }}</p>
    @endif

    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs font-semibold text-slate-500">
        <span class="inline-flex items-center gap-1">
            <x-ui.icon name="fa-solid fa-layer-group text-[0.85em] text-slate-400" />
            <span>{{ trans_choice('catalog.counts.seasons', $seasonsCount) }}</span>
        </span>
        <span class="inline-flex items-center gap-1">
            <x-ui.icon name="fa-solid fa-circle-play text-[0.85em] text-slate-400" />
            <span>{{ trans_choice('catalog.counts.episodes', $episodesCount) }}</span>
        </span>
        @if ($mediaCount > 0)
            <span class="inline-flex items-center gap-1">
                <x-ui.icon name="fa-solid fa-file-video text-[0.85em] text-slate-400" />
                <span>{{ trans_choice('catalog.counts.videos', $mediaCount) }}</span>
            </span>
        @endif
    </div>
</x-ui.poster-card>
