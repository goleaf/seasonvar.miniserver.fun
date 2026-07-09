<!DOCTYPE html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Каталог сериалов') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#f6f8f9] text-[#26333b] antialiased">
        <main class="mx-auto flex min-h-screen max-w-3xl items-center px-4">
            <section class="w-full rounded border border-[#d4dce0] bg-white p-6 shadow-sm">
                <h1 class="text-2xl font-black">Каталог сериалов</h1>
                <p class="mt-3 text-sm leading-6 text-zinc-600">Откройте локальный каталог сериалов, сезонов и серий.</p>
                <a href="{{ route('home') }}" class="mt-5 inline-flex rounded-lg bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                    Перейти в каталог
                </a>
            </section>
        </main>
    </body>
</html>
