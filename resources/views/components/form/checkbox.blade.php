@props(['label', 'for'])

<label for="{{ $for }}" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control px-1 text-sm font-semibold text-slate-700">
    <input
        id="{{ $for }}"
        type="checkbox"
        {{ $attributes->class(['h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600']) }}
    >
    <span>{{ $label }}</span>
</label>
