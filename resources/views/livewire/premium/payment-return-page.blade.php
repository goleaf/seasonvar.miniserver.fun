<div class="mx-auto max-w-2xl space-y-5">
    <section class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7" aria-labelledby="premium-return-title">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-100 text-slate-700"><x-ui.icon name="fa-solid fa-receipt" /></span>
            <div class="min-w-0">
                <h1 id="premium-return-title" class="break-words text-2xl font-black text-slate-900">{{ __('premium.return.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('premium.return.description') }}</p>
            </div>
        </div>
        <div class="mt-5 rounded-control border border-slate-200 bg-slate-50 p-4" role="status" aria-live="polite">
            <p class="font-black text-slate-900">{{ __('premium.states.'.$state) }}</p>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('premium.return.'.$state) }}</p>
            <p class="mt-2 text-sm font-bold tabular-nums text-slate-700">{{ __('premium.payment.amount') }}: {{ $amount }}</p>
        </div>
        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
            <a href="{{ $settingsUrl }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">{{ __('premium.return.settings') }}</a>
            <a href="{{ $pricingUrl }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('premium.return.back') }}</a>
        </div>
    </section>
</div>
