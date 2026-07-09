@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'mb-3 flex flex-col gap-1 border-b border-slate-200 pb-3']) }}>
    <h2 class="text-base font-bold text-slate-700">{{ $title }}</h2>
    @if ($subtitle)
        <p class="text-sm leading-6 text-slate-500">{{ $subtitle }}</p>
    @endif
</div>
