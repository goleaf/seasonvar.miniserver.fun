<div class="mx-auto max-w-4xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                    <x-ui.icon name="fa-solid fa-user" />
                </span>
                <div class="min-w-0">
                    <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">Профиль</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Имя и электронная почта вашего аккаунта.</p>
                </div>
            </div>

            <x-ui.status-pill
                :icon="$emailVerified ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"
                :variant="$emailVerified ? 'success' : 'warning'"
            >
                {{ $emailVerified ? 'Почта подтверждена' : 'Почта не подтверждена' }}
            </x-ui.status-pill>
        </div>

        @if ($createdAt)
            <p class="mt-4 text-xs font-semibold text-slate-500">Аккаунт создан {{ $createdAt }}</p>
        @endif
    </header>

    <section aria-labelledby="library-summary-title" class="space-y-3">
        <div class="flex items-end justify-between gap-3">
            <div>
                <h2 id="library-summary-title" class="text-lg font-black text-slate-800">Моя библиотека</h2>
                <p class="mt-1 text-sm text-slate-600">Краткая сводка личных списков и просмотров.</p>
            </div>
            <a href="{{ route('library.index') }}" class="text-sm font-bold text-emerald-700 hover:text-emerald-600">Открыть</a>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['label' => 'В списке', 'count' => $librarySummary->watchlistCount, 'icon' => 'fa-solid fa-bookmark'],
                ['label' => 'Оценено', 'count' => $librarySummary->ratingsCount, 'icon' => 'fa-solid fa-star'],
                ['label' => 'Продолжить', 'count' => $librarySummary->continueWatchingCount, 'icon' => 'fa-solid fa-circle-play'],
                ['label' => 'В истории', 'count' => $librarySummary->historyCount, 'icon' => 'fa-solid fa-clock-rotate-left'],
            ] as $item)
                <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
                    <span class="text-emerald-700"><x-ui.icon :name="$item['icon']" /></span>
                    <p class="mt-3 text-2xl font-black text-slate-800">{{ $item['count'] }}</p>
                    <p class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">{{ $item['label'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <x-ui.panel title="Данные аккаунта" subtitle="Изменение почты потребует повторного подтверждения." icon="fa-solid fa-address-card">
        @if ($status)
            <div class="mb-5">
                <x-form.status-message :message="$status" />
            </div>
        @endif

        <form wire:submit="saveProfile" class="space-y-5" novalidate>
            <x-form.field
                label="Имя"
                for="profile-name"
                wire:model="name"
                autocomplete="name"
                required
            />
            <x-form.field
                label="Электронная почта"
                for="profile-email"
                type="email"
                wire:model="email"
                autocomplete="email"
                required
            />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveProfile"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
            >
                <x-ui.icon name="fa-solid fa-floppy-disk" />
                <span wire:loading.remove wire:target="saveProfile">Сохранить профиль</span>
                <span wire:loading wire:target="saveProfile">Сохранение…</span>
            </button>
        </form>
    </x-ui.panel>

    @unless ($emailVerified)
        <x-ui.panel title="Подтвердите электронную почту" subtitle="До подтверждения доступны чтение профиля и настройки аккаунта." icon="fa-solid fa-envelope-circle-check">
            <a href="{{ route('verification.notice') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:w-auto">
                <x-ui.icon name="fa-solid fa-paper-plane" />
                <span>Открыть подтверждение почты</span>
            </a>
        </x-ui.panel>
    @endunless

    <nav aria-label="Разделы аккаунта" class="grid gap-3 sm:grid-cols-2">
        <a href="{{ route('profile.security') }}" class="flex min-h-11 items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-4 font-bold text-slate-700 shadow-panel hover:border-emerald-200 hover:text-emerald-700">
            <span class="inline-flex items-center gap-2">
                <x-ui.icon name="fa-solid fa-shield-halved" />
                Безопасность
            </span>
            <x-ui.icon name="fa-solid fa-chevron-right text-xs" />
        </a>
        <a href="{{ route('library.index') }}" class="flex min-h-11 items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-4 font-bold text-slate-700 shadow-panel hover:border-emerald-200 hover:text-emerald-700">
            <span class="inline-flex items-center gap-2">
                <x-ui.icon name="fa-solid fa-bookmark" />
                Моя библиотека
            </span>
            <x-ui.icon name="fa-solid fa-chevron-right text-xs" />
        </a>
    </nav>
</div>
