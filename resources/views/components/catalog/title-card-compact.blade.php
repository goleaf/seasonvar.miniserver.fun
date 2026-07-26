<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    layout="compact"
    data-catalog-card
    {{ $attributes }}
>
    <div class="min-w-0">
        <h3 class="text-base font-semibold leading-5">
            <a href="{{ route('titles.show', $title) }}" class="block break-words text-slate-900 after:absolute after:inset-0 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                {{ $displayTitle }}
            </a>
        </h3>
        @if ($title->display_original_title)
            <span class="mt-1 block break-words text-sm leading-5 text-slate-600">{{ $title->display_original_title }}</span>
        @endif
    </div>

    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-semibold">
        @if ($title->year)
            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600">
                <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em] text-slate-500" />
                <span>{{ $title->year }}</span>
            </span>
        @endif
        @if ($ratingLabel)
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-800">
                <x-ui.icon name="fa-solid fa-star text-[0.85em]" />
                <span>{{ $ratingLabel }}</span>
            </span>
        @endif
        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
            <x-ui.icon name="fa-solid fa-circle-play text-[0.85em]" />
            <span>{{ $episodesLabel }}</span>
        </span>
    </div>

    @if ($cardGenres->isNotEmpty())
        <div class="relative z-10 mt-3 flex flex-wrap gap-1.5">
            @foreach ($cardGenres as $genre)
                <x-ui.taxonomy-chip :taxonomy="$genre" />
            @endforeach
        </div>
    @endif

    @if ($showDescription && $descriptionExcerpt)
        <p data-title-card-description class="mt-2 line-clamp-3 break-words text-sm leading-6 text-slate-600">{{ $descriptionExcerpt }}</p>
    @endif

    <a
        data-title-card-details
        href="{{ route('titles.show', $title) }}"
        class="relative z-10 mt-2 inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
    >
        <span>{{ __('catalog.title.more_details') }}</span>
        <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
    </a>

    @include('components.catalog.title-card-personal-state')
</x-ui.poster-card>
