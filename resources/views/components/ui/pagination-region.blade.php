@props(['name'])

<section
    data-pagination-region="{{ $name }}"
    data-pagination-scroll-target
    aria-busy="false"
    {{ $attributes->class(['relative min-w-0']) }}
>
    <div data-pagination-loading class="pointer-events-none absolute inset-x-0 top-3 z-30 hidden justify-center px-3" role="status" aria-live="polite" aria-atomic="true">
        <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white/95 px-4 py-2 text-sm font-black text-emerald-800 shadow-panel">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
            <span>{{ __('pagination.loading') }}</span>
        </span>
    </div>

    <div data-pagination-content class="min-w-0 transition-opacity duration-200 motion-reduce:transition-none">
        {{ $slot }}
    </div>
</section>
