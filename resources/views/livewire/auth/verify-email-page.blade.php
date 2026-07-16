<div class="mx-auto max-w-2xl">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="verify-email-title">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-envelope-circle-check" />
            </span>
            <div class="min-w-0">
                <h1 id="verify-email-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('auth.pages.verify_email.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('auth.pages.verify_email.sent_to', ['email' => $email]) }}
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('auth.pages.verify_email.after_link') }}</p>
            </div>
        </div>

        <div class="mt-6 space-y-4">
            <x-form.status-message :message="$status" />
            <x-form.input-error for="email" id="verification-email-error" />

            <div class="flex flex-col gap-3 sm:flex-row">
                <button
                    type="button"
                    wire:click="resend"
                    wire:loading.attr="disabled"
                    wire:target="resend"
                    aria-live="polite"
                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                >
                    <x-ui.icon name="fa-solid fa-paper-plane" />
                    <span wire:loading.remove wire:target="resend">{{ __('auth.actions.resend_verification') }}</span>
                    <span wire:loading wire:target="resend">{{ __('auth.loading.sending') }}</span>
                </button>

                <a href="{{ route('library.index') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:w-auto">
                    <x-ui.icon name="fa-solid fa-bookmark" />
                    <span>{{ __('auth.actions.open_library') }}</span>
                </a>
            </div>
        </div>
    </section>
</div>
