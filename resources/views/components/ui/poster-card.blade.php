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
            :fit="in_array($layout, ['stats', 'home', 'spotlight', 'trend'], true) ? 'cover' : 'contain'"
            :overscan="in_array($layout, ['stats', 'home', 'spotlight', 'trend'], true)"
            class="h-full w-full"
        />
    </div>
    <div data-ui-poster-card-body class="{{ $bodyClasses() }}">
        {{ $slot }}
    </div>
</article>
