@props(['title' => null, 'subtitle' => null, 'pad' => true, 'icon' => null])

<section {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60']) }}>
    @if ($title || $subtitle)
        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
            <div class="flex items-start gap-2">
                @if ($icon)
                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">
                        <i class="{{ $icon }}" aria-hidden="true"></i>
                    </span>
                @endif

                <div class="min-w-0">
                    @if ($title)
                        <h2 class="text-sm font-bold text-slate-700">{{ $title }}</h2>
                    @endif
                    @if ($subtitle)
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($pad)
        <div class="p-4">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</section>
