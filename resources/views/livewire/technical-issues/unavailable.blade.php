<div class="mx-auto max-w-3xl">
    <section class="rounded-panel border border-rose-200 bg-white p-6 shadow-panel" role="alert">
        <h1 class="text-2xl font-black text-slate-900">{{ __('issues.states.detail_unavailable') }}</h1>
        <p class="mt-3 text-sm leading-6 text-rose-800">{{ $message }}</p>
        <a href="{{ $returnUrl }}" class="mt-5 inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 font-bold text-slate-700">{{ __('issues.my_tickets') }}</a>
    </section>
</div>
