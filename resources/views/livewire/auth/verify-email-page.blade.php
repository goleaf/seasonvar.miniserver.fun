<div class="mx-auto max-w-2xl">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="verify-email-title">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-envelope-circle-check" />
            </span>
            <div class="min-w-0">
                <h1 id="verify-email-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">Подтверждение почты</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Ссылка для подтверждения отправлена на адрес <strong class="break-words text-slate-800">{{ $email }}</strong>.
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-500">После перехода по ссылке вернитесь в свою библиотеку.</p>
            </div>
        </div>

        <a href="{{ route('library.index') }}" class="mt-6 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto">
            <x-ui.icon name="fa-solid fa-bookmark" />
            <span>Перейти в библиотеку</span>
        </a>
    </section>
</div>
