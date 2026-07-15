<div class="mx-auto max-w-2xl">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="register-title">
        <header>
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                    <x-ui.icon name="fa-solid fa-user-plus" />
                </span>
                <div class="min-w-0">
                    <h1 id="register-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">Регистрация</h1>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Создайте аккаунт, чтобы хранить свою библиотеку, оценки и историю просмотра.</p>
                </div>
            </div>
        </header>

        <form wire:submit="register" class="mt-6 space-y-5" novalidate>
            <x-form.field
                label="Имя"
                for="register-name"
                wire:model="form.name"
                autocomplete="name"
                required
            />
            <x-form.field
                label="Электронная почта"
                for="register-email"
                type="email"
                wire:model="form.email"
                autocomplete="email"
                required
            />
            <x-form.password-field
                label="Пароль"
                for="register-password"
                wire:model="form.password"
                autocomplete="new-password"
                hint="Не менее 12 символов: строчные и заглавные буквы, цифра и специальный символ."
                required
            />
            <x-form.password-field
                label="Повторите пароль"
                for="register-password-confirmation"
                wire:model="form.passwordConfirmation"
                autocomplete="new-password"
                required
            />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="register"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="fa-solid fa-user-plus" />
                <span wire:loading.remove wire:target="register">Создать аккаунт</span>
                <span wire:loading wire:target="register">Создание аккаунта…</span>
            </button>
        </form>

        <p class="mt-6 border-t border-slate-200 pt-5 text-center text-sm leading-6 text-slate-600">
            Уже есть аккаунт?
            <a href="{{ route('login') }}" class="font-bold text-emerald-700 hover:text-emerald-600">Войти</a>
        </p>
    </section>
</div>
