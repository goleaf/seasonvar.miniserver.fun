@props(['title' => null, 'subtitle' => null, 'pad' => true])

<section {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60']) }}>
    @if ($title || $subtitle)
        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
            @if ($title)
                <h2 class="text-sm font-bold text-slate-700">{{ $title }}</h2>
            @endif
            @if ($subtitle)
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $subtitle }}</p>
            @endif
        </div>
    @endif

    @if ($pad)
        <div class="p-4">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</section>
