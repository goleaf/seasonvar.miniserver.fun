@props([
    'title',
    'emptyLabel' => 'Нет постера',
    'imageClass' => 'h-full w-full object-contain',
    'emptyClass' => 'grid h-full place-items-center px-2 text-center text-xs font-semibold text-slate-500',
])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-control bg-slate-100 ring-1 ring-slate-200']) }}>
    @if ($title->poster_url)
        <img src="{{ $title->poster_url }}" alt="Постер {{ $title->title }}" loading="lazy" decoding="async" referrerpolicy="no-referrer" class="{{ $imageClass }}">
    @else
        <div class="{{ $emptyClass }}">
            <span class="inline-flex flex-col items-center gap-1">
                <x-ui.icon name="fa-regular fa-image text-lg text-slate-300" />
                @if ($emptyLabel !== '')
                    <span>{{ $emptyLabel }}</span>
                @endif
            </span>
        </div>
    @endif
</div>
