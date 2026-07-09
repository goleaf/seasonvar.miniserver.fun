@props(['label', 'value'])

<div class="border border-white/10 bg-white/[0.04] p-5">
    <div class="text-3xl font-semibold text-white">{{ number_format((int) $value) }}</div>
    <div class="mt-1 text-sm text-zinc-400">{{ $label }}</div>
</div>
