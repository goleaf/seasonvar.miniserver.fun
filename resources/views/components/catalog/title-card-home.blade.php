<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    :layout="$layout"
    data-catalog-card
    {{ $attributes }}
>
    <div class="min-w-0">
        <h3 class="{{ $layout === 'spotlight' ? 'text-xl lg:text-2xl' : 'text-lg' }} font-semibold leading-tight text-slate-900">
            <a href="{{ route('titles.show', $title) }}" class="break-words hover:text-emerald-800">
                {{ $displayTitle }}
            </a>
        </h3>
        @if ($title->display_original_title)
            <p class="mt-1 line-clamp-1 break-words text-sm text-slate-600">{{ $title->display_original_title }}</p>
        @endif
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600">
        @if ($title->year)
            <span>{{ $title->year }}</span>
        @endif
        @if ($ratingLabel)
            <span class="inline-flex items-center gap-1 font-semibold text-amber-800">
                <x-ui.icon name="fa-solid fa-star text-xs" />
                <span>{{ $ratingLabel }}</span>
            </span>
        @endif
    </div>

    @if ($cardGenres->isNotEmpty())
        <div class="relative z-10 mt-2 flex flex-wrap gap-1.5">
            @foreach ($cardGenres as $genre)
                <x-ui.taxonomy-chip :taxonomy="$genre" />
            @endforeach
        </div>
    @endif

    <p class="mt-2 line-clamp-1 text-sm text-slate-600">
        {{ $seasonsLabel }} · {{ $episodesLabel }}
    </p>

    @if ($layout === 'spotlight' && $showDescription && $descriptionExcerpt)
        <p data-title-card-description class="mt-4 hidden break-words text-sm leading-6 text-slate-700 lg:line-clamp-4">{{ $descriptionExcerpt }}</p>
    @endif

    @if ($layout === 'spotlight' && $primaryReason)
        <p class="mt-3 hidden items-start gap-2 text-sm font-semibold text-emerald-800 lg:flex">
            <x-ui.icon name="fa-solid fa-arrow-trend-up mt-1 shrink-0" />
            <span>{{ $primaryReason }}</span>
        </p>
    @endif

    @if ($layout === 'spotlight')
        <div class="relative z-10 mt-auto flex flex-wrap gap-2 pt-3">
            @if ($mediaCount > 0)
                <a href="{{ route('titles.show', $title) }}#player" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-800">
                    <x-ui.icon name="fa-solid fa-play" />
                    <span>{{ __('home.actions.watch') }}</span>
                </a>
            @endif
            <a href="{{ route('titles.show', $title) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:border-emerald-700 hover:text-emerald-800">
                <span>{{ __('catalog.title.more_details') }}</span>
                <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
            </a>
        </div>
    @endif
</x-ui.poster-card>
