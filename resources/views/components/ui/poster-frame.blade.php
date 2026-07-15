<div data-ui-poster-frame {{ $attributes->merge(['class' => $frameClasses()]) }}>
    @if ($hasImage())
        <img
            data-ui-poster-image
            src="{{ $src }}"
            alt="{{ $alt }}"
            loading="{{ $loading }}"
            decoding="async"
            referrerpolicy="no-referrer"
            class="{{ $imageClasses() }}"
        >
    @else
        <span class="grid h-full min-h-20 w-full place-items-center px-2 text-center text-xs font-semibold text-slate-600">
            <span class="inline-flex min-w-0 flex-col items-center gap-1">
                <x-ui.icon name="fa-regular fa-image text-lg text-slate-300" />
                @if ($emptyLabel !== '')
                    <span class="min-w-0 break-words">{{ $emptyLabel }}</span>
                @endif
            </span>
        </span>
    @endif
</div>
