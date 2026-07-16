<div class="mx-auto max-w-2xl">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="login-title">
        <header>
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                    <x-ui.icon name="fa-solid fa-right-to-bracket" />
                </span>
                <div class="min-w-0">
                    <h1 id="login-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('auth.pages.login.title') }}</h1>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('auth.pages.login.intro') }}</p>
                </div>
            </div>
        </header>

        @if ($status)
            <div class="mt-6">
                <x-form.status-message :message="$status" />
            </div>
        @endif

        <form wire:submit="login" class="mt-6 space-y-5" novalidate>
            <x-form.field
                :label="__('auth.fields.email')"
                for="login-email"
                type="email"
                wire:model="form.email"
                autocomplete="email"
                required
            />
            <x-form.password-field
                :label="__('auth.fields.password')"
                for="login-password"
                wire:model="form.password"
                autocomplete="current-password"
                required
            />
            <x-form.checkbox :label="__('auth.fields.remember')" for="login-remember" wire:model="form.remember" />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="login"
                aria-live="polite"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="fa-solid fa-right-to-bracket" />
                <span wire:loading.remove wire:target="login">{{ __('auth.actions.login') }}</span>
                <span wire:loading wire:target="login">{{ __('auth.loading.checking') }}</span>
            </button>
        </form>

        <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-5 text-center text-sm leading-6 sm:flex-row sm:items-center sm:justify-between sm:text-left">
            <a href="{{ $forgotPasswordUrl }}" class="font-bold text-emerald-700 hover:text-emerald-600">{{ __('auth.actions.forgot_password') }}</a>
            @if ($registrationEnabled)
                <span class="text-slate-600">
                    {{ __('auth.pages.login.no_account') }}
                    <a href="{{ $registerUrl }}" class="font-bold text-emerald-700 hover:text-emerald-600">{{ __('auth.actions.register') }}</a>
                </span>
            @endif
        </div>
    </section>
</div>
