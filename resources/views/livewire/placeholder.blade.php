<section
    aria-busy="true"
    aria-live="polite"
    class="min-h-32 rounded-panel border border-slate-200 bg-white p-5 shadow-panel"
    data-livewire-placeholder
>
    <div class="motion-safe:animate-pulse">
        <div class="h-5 w-40 rounded-control bg-slate-200"></div>
        <div class="mt-4 h-3 w-full rounded-control bg-slate-100"></div>
        <div class="mt-2 h-3 w-3/4 rounded-control bg-slate-100"></div>
    </div>
    <span class="sr-only">{{ __('catalog.loading') }}</span>
</section>
