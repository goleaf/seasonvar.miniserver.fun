<x-ui.poster-card
    :src="$title->poster_url"
    :alt="$posterAlt"
    layout="list"
    data-home-latest-media-group="{{ $title->id }}"
>
    <h3 class="text-lg font-semibold leading-6 text-slate-900">
        <a href="{{ $titleUrl }}" class="break-words hover:text-emerald-800">
            {{ $displayTitle }}
        </a>
    </h3>
    @if ($title->display_original_title)
        <p class="mt-0.5 line-clamp-1 break-words text-sm text-slate-600">{{ $title->display_original_title }}</p>
    @endif

    <p class="mt-2 text-sm font-semibold text-slate-700">{{ $summaryLabel }}</p>
    <p class="mt-1 line-clamp-1 text-xs text-slate-600">{{ $metadataLabel }}</p>

    <div class="relative z-10 mt-3 flex flex-wrap gap-2">
        @if ($latestUrl !== null)
            <a href="{{ $latestUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-800">
                <x-ui.icon name="fa-solid fa-play" />
                <span>{{ __('home.updates.watch_latest') }}</span>
            </a>
        @endif
        <a href="{{ $episodesUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:border-emerald-700 hover:text-emerald-800">
            <span>{{ __('home.updates.show_episodes') }}</span>
            <x-ui.icon name="fa-solid fa-arrow-right" />
        </a>
    </div>
</x-ui.poster-card>
