<div class="mx-auto max-w-3xl rounded-panel border border-rose-200 bg-white p-6 text-center shadow-panel sm:p-10" role="alert">
    <span class="mx-auto grid h-12 w-12 place-items-center rounded-control bg-rose-50 text-rose-700">
        <x-ui.icon name="fa-solid fa-triangle-exclamation" />
    </span>
    <h1 class="mt-4 text-2xl font-black text-slate-900">{{ __('profiles.errors.unavailable') }}</h1>
    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('profiles.errors.load_failed') }}</p>
    <a href="{{ url()->current() }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
        {{ __('profiles.actions.retry') }}
    </a>
</div>
