@props(['title' => null, 'subtitle' => null, 'pad' => true, 'icon' => null])

<section data-ui-panel {{ $attributes->merge(['class' => 'overflow-hidden rounded-panel border border-slate-200 bg-white']) }}>
    @if ($title || $subtitle)
        <div class="border-b border-slate-200 bg-white px-4 py-4 sm:px-5">
            <div @class([
                'flex gap-3',
                'items-start' => $subtitle,
                'items-center' => ! $subtitle,
            ])>
                @if ($icon)
                    <span @class([
                        'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-emerald-50 text-emerald-700',
                    ])>
                        <x-ui.icon name="{{ $icon }}" />
                    </span>
                @endif

                <div class="min-w-0">
                    @if ($title)
                        <h2 class="text-2xl font-semibold text-slate-900">{{ $title }}</h2>
                    @endif
                    @if ($subtitle)
                        <p class="mt-1 text-sm leading-5 text-slate-600">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($pad)
        <div class="p-4 sm:p-5">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</section>
