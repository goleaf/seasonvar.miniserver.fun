@props(['icon', 'value', 'label'])

<div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600">
    <div class="flex items-center justify-center gap-2 text-lg text-emerald-700">
        <i class="{{ $icon }}" aria-hidden="true"></i>
        <span>{{ $value }}</span>
    </div>
    <div>{{ $label }}</div>
</div>
