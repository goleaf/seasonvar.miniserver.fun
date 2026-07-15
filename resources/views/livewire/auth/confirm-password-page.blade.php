<div class="mx-auto max-w-2xl">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="confirm-password-title">
        <header class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-shield-halved" />
            </span>
            <div class="min-w-0">
                <h1 id="confirm-password-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">Подтверждение пароля</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Для продолжения защищённого действия введите текущий пароль.</p>
            </div>
        </header>

        <form wire:submit="confirm" class="mt-6 space-y-5" novalidate>
            <x-form.password-field
                label="Текущий пароль"
                for="confirm-current-password"
                wire:model="form.password"
                autocomplete="current-password"
                required
            />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="confirm"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="fa-solid fa-shield-halved" />
                <span wire:loading.remove wire:target="confirm">Подтвердить</span>
                <span wire:loading wire:target="confirm">Проверка…</span>
            </button>
        </form>
    </section>
</div>
