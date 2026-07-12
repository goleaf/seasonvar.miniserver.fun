<span {{ $attributes->merge(['class' => $classes()]) }}>
    @if ($icon)
        <i class="{{ $icon }} shrink-0" aria-hidden="true"></i>
    @endif
    <span class="min-w-0 break-words">{{ $slot }}</span>
</span>
