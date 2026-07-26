<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    :layout="$layout"
    data-catalog-card
    {{ $attributes }}
>
    <x-slot:mediaOverlay>
        @if ($hasNewEpisode || $isAdult)
            <div class="pointer-events-none absolute inset-0 z-10 flex items-start justify-between gap-2 p-2">
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
        @endif
    </x-slot:mediaOverlay>

    <div class="min-w-0">
        <h3 data-title-card-title class="line-clamp-2 text-lg font-semibold leading-6">
            <a href="{{ route('titles.show', $title) }}" class="block break-words text-slate-900 after:absolute after:inset-0 hover:text-emerald-800 focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                {{ $displayTitle }}
            </a>
        </h3>
        @if ($title->display_original_title)
            <span data-title-card-original-title class="mt-1 block line-clamp-1 text-sm leading-5 text-slate-600">{{ $title->display_original_title }}</span>
        @endif
    </div>

    <p class="mt-3 line-clamp-1 text-sm text-slate-600">
        @foreach ($listMetadata as $metadata)
            @if (! $loop->first)
                <span aria-hidden="true"> · </span>
            @endif
            <span>{{ $metadata }}</span>
        @endforeach
    </p>

    @if ($ratingLabels !== [])
        <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm font-semibold text-amber-800">
            @foreach ($ratingLabels as $label)
                <span data-title-card-rating class="inline-flex items-center gap-1">
                    <x-ui.icon name="fa-solid fa-star text-[0.85em]" />
                    <span>{{ $label }}</span>
                </span>
            @endforeach
        </p>
    @endif

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

    @if ($showDescription && $descriptionExcerpt)
        <p data-title-card-description class="mt-3 line-clamp-3 break-words text-sm leading-6 text-slate-700">{{ $descriptionExcerpt }}</p>
    @endif

    @include('components.catalog.title-card-actions')
</x-ui.poster-card>
