<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name', 'Seasonvar Index') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
        <div class="border-b border-white/10 bg-zinc-950/95">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="text-lg font-semibold text-white">
                    Season Index
                </a>
                <nav class="flex items-center gap-2 text-sm text-zinc-300">
                    <a href="{{ route('home') }}" class="rounded-md px-3 py-2 hover:bg-white/10">Dashboard</a>
                    <a href="{{ route('titles.index') }}" class="rounded-md px-3 py-2 hover:bg-white/10">Titles</a>
                </nav>
            </div>
        </div>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            @yield('content')
        </main>
    </body>
</html>
