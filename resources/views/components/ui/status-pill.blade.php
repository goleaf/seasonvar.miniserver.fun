<span {{ $attributes->merge(['class' => $classes()]) }}>
    @if ($icon)
        <i class="{{ $icon }}" aria-hidden="true"></i>
    @endif
    <span>{{ $slot }}</span>
</span>
