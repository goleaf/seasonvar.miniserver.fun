<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    layout="grid"
    data-catalog-card
    {{ $attributes }}
>
    <x-slot:mediaOverlay>
        <div class="pointer-events-none absolute inset-0 z-10 flex flex-col justify-between p-2">
            <div class="flex items-start justify-between gap-2">
                @if ($hasNewEpisode)
                    <span data-title-card-new-episode class="rounded-full bg-emerald-700 px-2 py-1 text-xs font-semibold text-white">
                        {{ __('catalog.title.card_actions.new_episode') }}
                    </span>
                @else
                    <span></span>
                @endif

                @if ($isAdult)
                    <span data-title-card-age-rating class="rounded-full bg-slate-900/90 px-2 py-1 text-xs font-semibold text-white">18+</span>
                @endif
            </div>

            @if ($ratingLabel)
                <span data-title-card-rating class="ml-auto inline-flex items-center gap-1 rounded-full bg-white/95 px-2 py-1 text-xs font-semibold text-amber-800 shadow-elevated">
                    <x-ui.icon name="fa-solid fa-star text-[0.85em]" />
                    <span>{{ $ratingLabel }}</span>
                </span>
            @endif
        </div>
    </x-slot:mediaOverlay>

    <div class="min-w-0">
        <h3 data-title-card-title class="line-clamp-2 text-base font-semibold leading-5 text-slate-900">
            <a href="{{ route('titles.show', $title) }}" class="after:absolute after:inset-0 hover:text-emerald-800 focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                {{ $displayTitle }}
            </a>
        </h3>
        @if ($title->display_original_title)
            <p data-title-card-original-title class="mt-1 line-clamp-1 text-sm text-slate-600">{{ $title->display_original_title }}</p>
        @endif
    </div>

    <p class="mt-2 line-clamp-1 text-sm text-slate-600">
        @if ($title->year)
            <span>{{ $title->year }}</span>
        @endif
        @if ($title->year && $seasonsCount > 0)
            <span aria-hidden="true"> · </span>
        @endif
        @if ($seasonsCount > 0)
            <span>{{ $seasonsLabel }}</span>
        @endif
    </p>

    @if ($cardGenres->isNotEmpty())
        <p class="relative z-10 mt-2 line-clamp-1 text-sm text-slate-600">
            @foreach ($cardGenres as $genre)
                @if (! $loop->first)
                    <span aria-hidden="true"> · </span>
                @endif
                <a
                    href="{{ route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $genre->slug]) }}"
                    class="font-medium hover:text-emerald-800 focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
                >{{ $genre->name }}</a>
            @endforeach
        </p>
    @endif

    @include('components.catalog.title-card-actions')
</x-ui.poster-card>
