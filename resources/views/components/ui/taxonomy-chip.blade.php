@if ($url())
    <a href="{{ $url() }}" {{ $attributes->merge(['class' => $classes().' relative z-10 min-h-11']) }}>
        @if ($iconClass())
            <i class="{{ $iconClass() }} shrink-0 text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span class="min-w-0 break-words">{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="shrink-0 text-slate-500">{{ $count }}</span>
        @endif
    </a>
@else
    <span {{ $attributes->merge(['class' => $classes()]) }}>
        @if ($iconClass())
            <i class="{{ $iconClass() }} shrink-0 text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span class="min-w-0 break-words">{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="shrink-0 text-slate-500">{{ $count }}</span>
        @endif
    </span>
@endif
