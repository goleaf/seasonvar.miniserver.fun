<div class="mx-auto max-w-5xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-shield-halved" />
            </span>
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">Безопасность</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Пароль, браузерные сессии и устройства, подключённые к API.</p>
            </div>
        </div>
    </header>

    <x-ui.panel title="Текущий пароль" subtitle="Он нужен для смены пароля, завершения других сессий и удаления аккаунта." icon="fa-solid fa-key">
        <x-form.password-field
            label="Текущий пароль"
            for="security-current-password"
            wire:model="currentPassword"
            autocomplete="current-password"
            required
        />
    </x-ui.panel>

    <x-ui.panel title="Сменить пароль" subtitle="После смены все устройства API будут отключены, текущая browser-сессия сохранится." icon="fa-solid fa-lock">
        @if ($passwordStatus)
            <div class="mb-5">
                <x-form.status-message :message="$passwordStatus" />
            </div>
        @endif

        <form wire:submit="updatePassword" class="space-y-5" novalidate>
            <x-form.password-field
                label="Новый пароль"
                for="security-new-password"
                wire:model="password"
                autocomplete="new-password"
                hint="Не менее 12 символов: строчные и заглавные буквы, цифра и специальный символ."
                required
            />
            <x-form.password-field
                label="Повторите новый пароль"
                for="security-new-password-confirmation"
                wire:model="passwordConfirmation"
                autocomplete="new-password"
                required
            />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="updatePassword"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
            >
                <x-ui.icon name="fa-solid fa-floppy-disk" />
                <span wire:loading.remove wire:target="updatePassword">Изменить пароль</span>
                <span wire:loading wire:target="updatePassword">Изменение…</span>
            </button>
        </form>
    </x-ui.panel>

    <x-ui.panel title="Устройства API" subtitle="Мобильные приложения и другие клиенты с персональными токенами." icon="fa-solid fa-mobile-screen">
        @if ($deviceStatus)
            <div class="mb-5">
                <x-form.status-message :message="$deviceStatus" />
            </div>
        @endif

        @if ($devices->isEmpty())
            <div class="rounded-control bg-slate-50 px-4 py-5 text-sm leading-6 text-slate-600">
                Подключённых устройств API нет.
            </div>
        @else
            <div class="space-y-3">
                @foreach ($devices as $device)
                    <article wire:key="api-device-{{ $device['id'] }}" class="grid gap-3 rounded-control border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <div class="min-w-0">
                            <h3 class="break-words font-black text-slate-800">{{ $device['name'] }}</h3>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                <span>Последнее использование: {{ $device['last_used_at'] ?? 'нет данных' }}</span>
                                <span>Действует до: {{ $device['expires_at'] ?? 'без срока' }}</span>
                            </div>
                        </div>
                        <button
                            type="button"
                            wire:click="revokeDevice({{ $device['id'] }})"
                            wire:confirm="Отключить это устройство API?"
                            wire:loading.attr="disabled"
                            wire:target="revokeDevice({{ $device['id'] }})"
                            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                        >
                            <x-ui.icon name="fa-solid fa-link-slash" />
                            <span>Отключить</span>
                        </button>
                    </article>
                @endforeach
            </div>

            <button
                type="button"
                wire:click="revokeAllDevices"
                wire:confirm="Отключить все устройства API?"
                wire:loading.attr="disabled"
                wire:target="revokeAllDevices"
                class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
            >
                <x-ui.icon name="fa-solid fa-mobile-screen-button" />
                <span>Отключить все устройства</span>
            </button>
        @endif
    </x-ui.panel>

    <x-ui.panel title="Другие браузерные сессии" subtitle="Текущая сессия останется активной." icon="fa-solid fa-desktop">
        @if ($sessionStatus)
            <div class="mb-5">
                <x-form.status-message :message="$sessionStatus" />
            </div>
        @endif

        <button
            type="button"
            wire:click="logoutOtherDevices"
            wire:loading.attr="disabled"
            wire:target="logoutOtherDevices"
            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
        >
            <x-ui.icon name="fa-solid fa-right-from-bracket" />
            <span wire:loading.remove wire:target="logoutOtherDevices">Завершить другие сессии</span>
            <span wire:loading wire:target="logoutOtherDevices">Завершение…</span>
        </button>
    </x-ui.panel>

    <section class="rounded-panel border border-rose-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="delete-account-title">
        <h2 id="delete-account-title" class="flex items-center gap-2 text-lg font-black text-rose-800">
            <x-ui.icon name="fa-solid fa-triangle-exclamation" />
            Удаление аккаунта
        </h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">Будут удалены профиль, личная библиотека, история просмотра, синхронизация и все токены. Каталог останется без изменений.</p>

        <button
            type="button"
            wire:click="deleteAccount"
            wire:confirm.prompt="Это действие нельзя отменить. Введите УДАЛИТЬ для подтверждения.|УДАЛИТЬ"
            wire:loading.attr="disabled"
            wire:target="deleteAccount"
            class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
        >
            <x-ui.icon name="fa-solid fa-trash-can" />
            <span wire:loading.remove wire:target="deleteAccount">Удалить аккаунт</span>
            <span wire:loading wire:target="deleteAccount">Удаление…</span>
        </button>
    </section>
</div>
