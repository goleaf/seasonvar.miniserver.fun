@if ($url())
    <a href="{{ $url() }}" {{ $attributes->merge(['class' => $classes()]) }}>
        @if ($iconClass())
            <i class="{{ $iconClass() }} text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span>{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="text-slate-400">{{ $count }}</span>
        @endif
    </a>
@else
    <span {{ $attributes->merge(['class' => $classes()]) }}>
        @if ($iconClass())
            <i class="{{ $iconClass() }} text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span>{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="text-slate-400">{{ $count }}</span>
        @endif
    </span>
@endif
