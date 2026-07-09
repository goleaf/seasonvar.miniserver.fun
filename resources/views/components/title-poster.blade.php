@props([
    'title',
    'emptyLabel' => 'Нет постера',
    'imageClass' => 'h-full w-full object-cover',
    'emptyClass' => 'grid h-full place-items-center px-2 text-center text-xs font-semibold text-slate-400',
])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200']) }}>
    @if ($title->poster_url)
        <img src="{{ $title->poster_url }}" alt="{{ $title->title }}" class="{{ $imageClass }}">
    @else
        <div class="{{ $emptyClass }}">{{ $emptyLabel }}</div>
    @endif
</div>
