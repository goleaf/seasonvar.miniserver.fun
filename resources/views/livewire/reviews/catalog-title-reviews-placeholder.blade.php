<section
    id="reviews"
    aria-labelledby="reviews-title"
    aria-busy="true"
    aria-live="polite"
    class="scroll-mt-40 rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:scroll-mt-44 sm:p-5 lg:scroll-mt-48"
    data-livewire-placeholder
>
    <div class="motion-safe:animate-pulse" aria-hidden="true">
        <div class="h-3 w-28 rounded-control bg-emerald-100"></div>
        <div class="mt-3 h-7 w-52 max-w-full rounded-control bg-slate-200"></div>
        <div class="mt-4 h-3 w-full rounded-control bg-slate-100"></div>
        <div class="mt-2 h-3 w-3/4 rounded-control bg-slate-100"></div>
    </div>
    <h2 id="reviews-title" class="sr-only">{{ __('reviews.section.title') }}</h2>
    <span class="sr-only">{{ __('catalog.loading') }}</span>
</section>
