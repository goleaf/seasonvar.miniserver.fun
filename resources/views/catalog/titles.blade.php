@extends('layouts.app', ['title' => 'Сериалы'])

@section('content')
    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-white">Сериалы</h1>
            <p class="mt-2 text-sm text-zinc-400">Поиск по названиям, описаниям, актерам, жанрам и связям каталога.</p>
            @if ($selectedTaxonomy)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded bg-emerald-400 px-3 py-1 font-semibold text-zinc-950">{{ $selectedTaxonomy->name }}</span>
                    <span class="text-zinc-400">Фильтр по relation: {{ $selectedTaxonomy->type }}</span>
                    <a href="{{ route('titles.index') }}" class="text-emerald-300 hover:text-emerald-200">Сбросить</a>
                </div>
            @endif
        </div>

        <form method="GET" action="{{ route('titles.index') }}" class="flex w-full max-w-md gap-2">
            @if ($selectedTaxonomy)
                <input type="hidden" name="taxonomy" value="{{ $selectedTaxonomy->slug }}">
                <input type="hidden" name="type" value="{{ $selectedTaxonomy->type }}">
            @endif
            <input
                name="q"
                value="{{ $search }}"
                placeholder="Название или описание"
                class="min-w-0 flex-1 rounded-md border border-white/10 bg-white/[0.05] px-3 py-2 text-sm text-white placeholder:text-zinc-500 focus:border-emerald-300 focus:outline-none"
            >
            <button class="rounded-md bg-emerald-400 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-300">Найти</button>
        </form>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($titles as $catalogTitle)
            <x-title-card :title="$catalogTitle" />
        @empty
            <div class="col-span-full border border-dashed border-white/15 bg-white/[0.03] p-8 text-sm text-zinc-300">
                Ничего не найдено.
            </div>
        @endforelse
    </div>

    <div class="mt-8">
        {{ $titles->links() }}
    </div>
@endsection
