@props([
    'href',
    'icon',
    'label',
    'active' => false,
    'count' => null,
    'total' => null,
])

<a href="{{ $href }}" @class([
    'flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-control px-3 py-2 text-sm leading-5 ring-1 transition',
    'bg-emerald-50 font-bold text-emerald-700 ring-emerald-100' => $active,
    'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $active,
])>
    <span class="inline-flex min-w-0 items-center gap-2">
        <i class="{{ $icon }} shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
        <span class="min-w-0 break-words">{{ $label }}</span>
    </span>

    @if ($count !== null)
        <span class="flex shrink-0 items-center gap-1 text-xs">
            <span class="font-bold">{{ $count }}</span>
            @if ($total !== null)
                <span class="text-slate-400">/ {{ $total }}</span>
            @endif
        </span>
    @endif
</a>
