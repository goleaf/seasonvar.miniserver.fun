@props(['label', 'value', 'icon' => null])

<div {{ $attributes->merge(['class' => 'rounded-panel border border-slate-200 bg-white p-5 shadow-panel']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="text-3xl font-bold text-emerald-700">{{ number_format((int) $value) }}</div>
            <div class="mt-1 text-sm text-slate-500">{{ $label }}</div>
        </div>

        @if ($icon)
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">
                <i class="{{ $icon }}" aria-hidden="true"></i>
            </span>
        @endif
    </div>
</div>
