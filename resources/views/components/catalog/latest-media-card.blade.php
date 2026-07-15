<x-ui.poster-card
    :src="$title->poster_url"
    :alt="$posterAlt()"
    layout="list"
    data-home-latest-media-card
>
    <h3 class="font-bold leading-5 text-slate-700">
        <a href="{{ $url() }}" class="break-words after:absolute after:inset-0 group-hover:text-emerald-700">
            {{ $title->display_title }}
        </a>
    </h3>
    @if ($title->display_original_title)
        <p class="mt-0.5 break-words text-xs font-semibold leading-5 text-slate-500">{{ $title->display_original_title }}</p>
    @endif
    <div class="mt-2 flex flex-wrap gap-1 text-xs font-semibold">
        @if ($seasonLabel)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                <x-ui.icon name="fa-solid fa-layer-group" />
                <span>{{ $seasonLabel }}</span>
            </span>
        @endif
        @if ($episodeLabel)
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                <x-ui.icon name="fa-solid fa-list-ol" />
                <span>{{ $episodeLabel }}</span>
            </span>
        @endif
        @if ($qualityLabel)
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                <x-ui.icon name="fa-solid fa-display" />
                <span>{{ $qualityLabel }}</span>
            </span>
        @endif
    </div>
    <p class="mt-2 break-words text-xs text-slate-500">{{ $meta }}</p>
</x-ui.poster-card>
