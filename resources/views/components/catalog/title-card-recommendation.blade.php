<x-ui.poster-card
    :src="$title->poster_url"
    :alt="__('catalog.seo.poster_alt', ['title' => $displayTitle])"
    layout="recommendation"
    data-catalog-card
    {{ $attributes }}
>
    <h3 class="line-clamp-2 text-lg font-semibold leading-6" data-title-card-title>
        <a href="{{ route('titles.show', $title) }}" class="break-words text-slate-900 after:absolute after:inset-0 hover:text-emerald-800 focus-visible:outline-none">
            {{ $displayTitle }}
        </a>
    </h3>

    @if ($title->display_original_title)
        <p class="mt-1 line-clamp-1 break-words text-sm font-medium text-slate-600" data-title-card-original-title>
            {{ $title->display_original_title }}
        </p>
    @endif

    @if ($recommendationMetadata !== [])
        <p class="mt-2 flex flex-wrap items-center gap-x-1.5 text-sm text-slate-600" data-recommendation-metadata>
            @foreach ($recommendationMetadata as $metadata)
                @if (! $loop->first)<span aria-hidden="true">·</span>@endif
                <span>{{ $metadata }}</span>
            @endforeach
        </p>
    @endif

    @if ($recommendationReasons !== [])
        <div
            class="mt-3 text-sm"
            data-recommendation-reasons
            role="group"
            aria-label="{{ $reasonHeading }}"
        >
            <p class="font-semibold text-slate-700">{{ $reasonHeading }}:</p>
            <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-slate-600">
                @foreach ($recommendationReasons as $reason)
                    @if (! $loop->first)<span aria-hidden="true">·</span>@endif
                    <span>{{ $reason }}</span>
                @endforeach
            </p>
        </div>
    @endif

    @if ($showDescription && $descriptionExcerpt)
        <p data-title-card-description class="mt-3 line-clamp-2 break-words text-sm leading-6 text-slate-600">{{ $descriptionExcerpt }}</p>
    @endif

    <a
        data-title-card-details
        href="{{ route('titles.show', $title) }}"
        class="title-card-action-primary relative z-10 mt-3 w-full sm:w-auto"
    >
        <span>{{ __('catalog.title.open_series') }}</span>
        <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
    </a>

    @include('components.catalog.title-card-personal-state')
</x-ui.poster-card>
