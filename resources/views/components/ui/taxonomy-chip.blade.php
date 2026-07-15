@if ($url())
    <a href="{{ $url() }}" @if ($ariaLabel($slot)) aria-label="{{ $ariaLabel($slot) }}" @endif {{ $attributes->merge(['class' => $classes().' relative z-10 min-h-11']) }}>
        @if ($iconClass())
            <x-ui.icon name="{{ $iconClass() }} text-[0.85em]" />
        @endif
        <span class="min-w-0 break-words">{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="shrink-0 tabular-nums text-slate-500">{{ $count }}</span>
        @endif
    </a>
@else
    <span @if ($ariaLabel($slot)) aria-label="{{ $ariaLabel($slot) }}" @endif {{ $attributes->merge(['class' => $classes()]) }}>
        @if ($iconClass())
            <x-ui.icon name="{{ $iconClass() }} text-[0.85em]" />
        @endif
        <span class="min-w-0 break-words">{{ $label($slot) }}</span>
        @if ($count !== null)
            <span class="shrink-0 tabular-nums text-slate-500">{{ $count }}</span>
        @endif
    </span>
@endif
