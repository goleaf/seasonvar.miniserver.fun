@props(['title', 'subtitle' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'mb-3 flex flex-col gap-1 border-b border-slate-200 pb-3']) }}>
    <h2 class="flex items-center gap-2 text-base font-bold text-slate-700">
        @if ($icon)
            <x-ui.icon :name="$icon" class="text-emerald-700" />
        @endif
        <span>{{ $title }}</span>
    </h2>
    @if ($subtitle)
        <p class="text-sm leading-6 text-slate-500">{{ $subtitle }}</p>
    @endif
</div>
