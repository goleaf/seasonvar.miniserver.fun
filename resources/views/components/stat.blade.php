@props(['label', 'value'])

<div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/60">
    <div class="text-3xl font-bold text-emerald-700">{{ number_format((int) $value) }}</div>
    <div class="mt-1 text-sm text-slate-500">{{ $label }}</div>
</div>
