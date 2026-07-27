<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    layout="trend"
    data-catalog-card
    {{ $attributes }}
>
    <h3 class="text-base font-semibold leading-snug text-slate-900">
        <a
            href="{{ route('titles.show', $title) }}"
            data-home-title-link
            class="line-clamp-2 cursor-pointer break-words after:absolute after:inset-0 after:rounded-panel hover:text-emerald-800 focus-visible:outline-none focus-visible:after:ring-4 focus-visible:after:ring-emerald-200 focus-visible:after:ring-inset"
        >
            {{ $displayTitle }}
        </a>
    </h3>
    @if ($title->display_original_title)
        <p class="mt-1 line-clamp-1 text-xs text-slate-600">{{ $title->display_original_title }}</p>
    @endif
    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600">
        @if ($title->year)
            <span>{{ $title->year }}</span>
        @endif
        @if ($ratingLabel)
            <span class="inline-flex items-center gap-1 font-semibold text-amber-800">
                <x-ui.icon name="fa-solid fa-star" />
                <span>{{ $ratingLabel }}</span>
            </span>
        @endif
    </div>
    <p class="mt-2 line-clamp-1 text-xs text-slate-600">{{ $seasonsLabel }} · {{ $episodesLabel }}</p>
</x-ui.poster-card>
