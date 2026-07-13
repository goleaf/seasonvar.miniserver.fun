<span {{ $attributes->merge(['class' => $classes()]) }}>
    @if ($icon)
        <x-ui.icon name="{{ $icon }}" />
    @endif
    <span class="min-w-0 break-words">{{ $slot }}</span>
</span>
