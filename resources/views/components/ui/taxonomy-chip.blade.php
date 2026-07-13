@if ($url())
    <a href="{{ $url() }}" {{ $attributes->merge(['class' => $classes().' relative z-10 min-h-11 max-w-full']) }}>
        @if ($iconClass())
            <i class="{{ $iconClass() }} text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span class="min-w-0 break-words">{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="shrink-0 tabular-nums text-slate-500">{{ $count }}</span>
        @endif
    </a>
@else
    <span {{ $attributes->merge(['class' => $classes()]) }}>
        @if ($iconClass())
            <i class="{{ $iconClass() }} text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span class="min-w-0 break-words">{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="shrink-0 tabular-nums text-slate-500">{{ $count }}</span>
        @endif
    </span>
@endif
