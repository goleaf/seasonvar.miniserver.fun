<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    layout="grid"
    data-catalog-card
    {{ $attributes }}
>
    <div class="min-w-0">
        <h3 class="line-clamp-2 text-base font-semibold leading-5 text-slate-900">
            <a href="{{ route('titles.show', $title) }}" class="after:absolute after:inset-0 hover:text-emerald-800 focus-visible:outline-none">
                {{ $displayTitle }}
            </a>
        </h3>
        @if ($title->display_original_title)
            <p class="mt-1 line-clamp-1 text-sm text-slate-600">{{ $title->display_original_title }}</p>
        @endif
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-slate-600">
        @if ($title->year)
            <span>{{ $title->year }}</span>
        @endif
        @if ($ratingLabel)
            <span class="inline-flex items-center gap-1 text-amber-800">
                <x-ui.icon name="fa-solid fa-star text-[0.85em]" />
                <span>{{ $ratingLabel }}</span>
            </span>
        @endif
    </div>

    @if ($cardGenres->isNotEmpty())
        <div class="relative z-10 mt-2 flex min-w-0 flex-wrap gap-1.5">
            @foreach ($cardGenres as $genre)
                <x-ui.taxonomy-chip :taxonomy="$genre" />
            @endforeach
        </div>
    @endif

    <p class="mt-auto line-clamp-1 pt-3 text-xs text-slate-600">
        {{ $seasonsLabel }} · {{ $episodesLabel }}
    </p>

    @include('components.catalog.title-card-personal-state')
</x-ui.poster-card>
