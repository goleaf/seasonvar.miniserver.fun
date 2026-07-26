<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    layout="recommendation"
    data-catalog-card
    {{ $attributes }}
>
    <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
        <div class="min-w-0">
            @if ($rank)
                <span class="text-xs font-bold uppercase tracking-wide text-emerald-700" data-recommendation-rank="{{ $rank }}">
                    {{ __('recommendations.card.rank', ['rank' => $rank]) }}
                </span>
            @endif

            <h3 class="mt-1 text-base font-bold leading-5 sm:text-lg sm:leading-6">
                <a href="{{ route('titles.show', $title) }}" class="break-words text-slate-800 after:absolute after:inset-0 hover:text-emerald-700">
                    {{ $displayTitle }}
                </a>
            </h3>

            @if ($title->display_original_title)
                <p class="mt-1 break-words text-sm font-semibold text-slate-500">{{ $title->display_original_title }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-1.5 text-xs font-semibold sm:shrink-0 sm:justify-end">
            @if ($title->year)
                <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600">
                    <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em] text-slate-400" />
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
    </div>

    @if ($cardGenres->isNotEmpty())
        <div class="relative z-10 mt-2 flex flex-wrap gap-1.5">
            @foreach ($cardGenres as $genre)
                <x-ui.taxonomy-chip :taxonomy="$genre" />
            @endforeach
        </div>
    @endif

    @if ($primaryReason)
        <div class="mt-2 flex min-w-0 items-start gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm text-emerald-800" aria-label="{{ __('recommendations.page.why') }}">
            <x-ui.icon name="fa-solid fa-check mt-1 shrink-0 text-[0.8em]" />
            <p class="min-w-0 break-words">
                <span class="font-bold">{{ __('recommendations.page.why') }}:</span>
                <span>{{ $primaryReason }}</span>
            </p>
        </div>
    @endif

    @if ($showDescription && $descriptionExcerpt)
        <p data-title-card-description class="mt-2 line-clamp-3 break-words text-sm leading-6 text-slate-600">{{ $descriptionExcerpt }}</p>
    @endif

    <a
        data-title-card-details
        href="{{ route('titles.show', $title) }}"
        class="relative z-10 mt-2 inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-emerald-700 transition hover:bg-emerald-50"
    >
        <span>{{ __('catalog.title.more_details') }}</span>
        <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
    </a>

    @include('components.catalog.title-card-personal-state')
</x-ui.poster-card>
