@props(['icon', 'value', 'label'])

<div class="grid min-h-16 content-center gap-1 rounded-lg bg-slate-50 px-3 py-3 text-center text-slate-600">
    <div class="flex items-center justify-center gap-2 text-lg leading-none text-emerald-700">
        <x-ui.icon name="{{ $icon }}" />
        <span>{{ $value }}</span>
    </div>
    <div class="text-sm leading-tight">{{ $label }}</div>
</div>
