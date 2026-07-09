<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name', 'Каталог сериалов') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
        <div class="border-b border-[#26333b] bg-[#17242c] text-zinc-100 shadow-lg shadow-zinc-900/20">
            <div class="mx-auto max-w-7xl px-4 py-2 text-xs text-zinc-300 sm:px-6 lg:px-8">
                <span class="font-semibold text-emerald-300">Каталог сериалов</span>
            </div>

            <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 pb-4 sm:px-6 lg:flex-row lg:items-center lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
	                    <span class="grid h-12 w-12 place-items-center rounded bg-emerald-400 text-xl font-black text-[#17242c]">К</span>
	                    <span>
	                        <span class="block text-2xl font-black tracking-tight text-white">Каталог сериалов</span>
	                        <span class="block text-xs tracking-[0.25em] text-emerald-300">список сезонов</span>
                    </span>
                </a>

                <form action="{{ route('titles.index') }}" method="GET" class="flex min-w-0 flex-1 overflow-hidden rounded border border-white/10 bg-white shadow-inner shadow-black/20 lg:mx-6">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Поиск сериала..." class="min-w-0 flex-1 border-0 px-4 py-3 text-sm text-zinc-900 outline-none placeholder:text-zinc-500">
                    <button type="submit" class="bg-emerald-400 px-5 text-sm font-bold text-[#17242c] hover:bg-emerald-300">Найти</button>
                </form>

                <nav class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                    <a href="{{ route('home') }}" class="rounded bg-white/10 px-3 py-2 text-zinc-100 hover:bg-white/20">Главная</a>
                    <a href="{{ route('titles.index') }}" class="rounded bg-white/10 px-3 py-2 text-zinc-100 hover:bg-white/20">Все сериалы</a>
                </nav>
            </div>
        </div>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            @yield('content')
        </main>
    </body>
</html>
