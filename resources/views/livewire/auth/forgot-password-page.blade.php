<div class="mx-auto max-w-2xl">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="forgot-password-title">
        <header class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-key" />
            </span>
            <div class="min-w-0">
                <h1 id="forgot-password-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('auth.pages.forgot_password.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('auth.pages.forgot_password.intro') }}</p>
            </div>
        </header>

        @if ($status)
            <div class="mt-6">
                <x-form.status-message :message="$status" />
            </div>
        @endif

        <form wire:submit="sendResetLink" class="mt-6 space-y-5" novalidate>
            <x-form.field
                :label="__('auth.fields.email')"
                for="forgot-password-email"
                type="email"
                wire:model="form.email"
                autocomplete="email"
                required
            />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="sendResetLink"
                aria-live="polite"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="fa-solid fa-paper-plane" />
                <span wire:loading.remove wire:target="sendResetLink">{{ __('auth.actions.send_reset_link') }}</span>
                <span wire:loading wire:target="sendResetLink">{{ __('auth.loading.sending') }}</span>
            </button>
        </form>

        <p class="mt-6 border-t border-slate-200 pt-5 text-center text-sm leading-6">
            <a href="{{ $loginUrl }}" class="font-bold text-emerald-700 hover:text-emerald-600">{{ __('auth.actions.back_to_login') }}</a>
        </p>
    </section>
</div>
