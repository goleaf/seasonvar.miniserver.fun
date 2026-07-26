<main
    data-pwa-offline-shell
    data-pwa-add-label="{{ __('pwa.offline.add_to_library') }}"
    data-pwa-remove-label="{{ __('pwa.offline.remove_from_library') }}"
    data-pwa-rating-label="{{ __('pwa.offline.rating_label') }}"
    data-pwa-rating-clear-label="{{ __('pwa.offline.rating_clear') }}"
    data-pwa-queued-label="{{ __('pwa.offline.action_queued') }}"
    data-pwa-queue-issue-label="{{ __('pwa.offline.action_requires_attention') }}"
    data-pwa-saved-at-label="{{ __('pwa.offline.saved_at') }}"
    class="mx-auto min-h-screen w-full max-w-5xl px-4 py-12 sm:px-8"
>
    <section class="w-full rounded-panel border border-slate-200 bg-white p-6 shadow-elevated sm:p-10" aria-labelledby="offline-title">
        <p class="text-sm font-bold uppercase tracking-wide text-emerald-700">{{ __('pwa.offline.eyebrow') }}</p>
        <h1 id="offline-title" class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{{ __('pwa.offline.title') }}</h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-slate-600">{{ __('pwa.offline.description') }}</p>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <article class="rounded-control border border-emerald-200 bg-emerald-50 p-5">
                <h2 class="font-black text-emerald-950">{{ __('pwa.offline.saved_copy_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-emerald-900">{{ __('pwa.offline.saved_copy_body') }}</p>
            </article>
            <article class="rounded-control border border-amber-200 bg-amber-50 p-5">
                <h2 class="font-black text-amber-950">{{ __('pwa.offline.video_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-amber-900">{{ __('pwa.offline.video_body') }}</p>
            </article>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <section aria-labelledby="offline-library-title">
                <h2 id="offline-library-title" class="text-xl font-black text-slate-950">{{ __('pwa.offline.library_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('pwa.offline.library_hint') }}</p>
                <time data-pwa-offline-library-saved-at hidden class="mt-2 block text-xs font-semibold text-slate-500"></time>
                <p data-pwa-offline-queue-status class="mt-2 text-sm font-bold text-emerald-800" role="status" aria-live="polite"></p>
                <p data-pwa-offline-library-empty class="mt-4 rounded-control bg-slate-100 p-4 text-sm text-slate-600">{{ __('pwa.offline.library_empty') }}</p>
                <ul data-pwa-offline-library-list class="mt-4 grid gap-3"></ul>
            </section>
            <section aria-labelledby="offline-help-title">
                <h2 id="offline-help-title" class="text-xl font-black text-slate-950">{{ __('pwa.offline.help_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('pwa.offline.help_hint') }}</p>
                <time data-pwa-offline-help-saved-at hidden class="mt-2 block text-xs font-semibold text-slate-500"></time>
                <p data-pwa-offline-help-empty class="mt-4 rounded-control bg-slate-100 p-4 text-sm text-slate-600">{{ __('pwa.offline.help_empty') }}</p>
                <ul data-pwa-offline-help-list class="mt-4 grid gap-3"></ul>
            </section>
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="/" class="inline-flex min-h-11 items-center justify-center rounded-control bg-emerald-700 px-5 py-3 font-bold text-white hover:bg-emerald-800">
                {{ __('pwa.offline.retry') }}
            </a>
            <a href="/help" class="inline-flex min-h-11 items-center justify-center rounded-control border border-slate-300 bg-white px-5 py-3 font-bold text-slate-800 hover:bg-slate-100">
                {{ __('pwa.offline.help') }}
            </a>
        </div>
    </section>
</main>
