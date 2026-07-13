<article
    data-ui-poster-card
    data-ui-poster-layout="{{ $layout }}"
    {{ $attributes->merge(['class' => $rootClasses()]) }}
>
    <div data-ui-poster-card-media class="{{ $mediaClasses() }}">
        <x-ui.poster-frame
            :src="$src"
            :alt="$alt"
            :empty-label="$emptyLabel"
            :loading="$loading"
            class="h-full w-full"
        />
    </div>
    <div data-ui-poster-card-body class="{{ $bodyClasses() }}">
        {{ $slot }}
    </div>
</article>
